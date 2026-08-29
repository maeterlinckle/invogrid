<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Models\Setting;
use RuntimeException;

/**
 * Clear Books REST API v1, over plain cURL.
 *
 * Everything here was read out of the published OpenAPI specification
 * (https://api.clearbooks.co.uk/spec/v1.yaml), not inferred. The facts that
 * shape this class, in the order they bite:
 *
 *  - **OAuth 2 authorisation code, confidential client only.** There is no
 *    client-credentials or password grant: a human signs in once and the
 *    application then lives on refresh tokens. PKCE is supported and used.
 *  - **Refresh tokens are single use.** Using one issues a new pair and
 *    invalidates the old. Two processes refreshing at once therefore lock the
 *    integration out entirely, which is why the refresh runs under a named
 *    database lock and re-reads the settings after taking it.
 *  - **One access token per user per application.** Completing the consent flow
 *    a second time revokes whatever this instance was holding.
 *  - **The business is a header**, `X-Business-ID`, not a path segment. It is
 *    only required for multi-business authorisations, but sending it always
 *    removes the question of which business a document landed in.
 *  - **Pagination is by header.** `?page=N&limit=N` going out;
 *    `X-Pagination-Current-Page` and `X-Pagination-Total-Pages` coming back,
 *    and the walk is finished when those two are equal. `limit` caps at 200.
 *  - **Errors are a JSON *array*** of `{errorCode, errorMessage}` — not the
 *    object `HttpResponse::errorSummary()` knows how to read, hence the local
 *    one below.
 *  - **Rate limiting starts above five requests a second** and answers 429.
 *
 * There is no projects endpoint, and no project scope. Clear Books' project
 * codes are not reachable from this API at all, which is the reason every
 * submitted document offers an "Open in Clear Books" link for a person to set
 * one by hand.
 */
final class ClearBooksClient
{
    /** The API lives under /v1; the token endpoint does not. */
    private const API_PREFIX = '/v1';

    private const TOKEN_PATH = '/oauth/token';

    /** The most Clear Books will return in one page. */
    private const PAGE_LIMIT = 200;

    /** Refresh this long before the token actually expires. */
    private const REFRESH_MARGIN = 120;

    /** Named lock, so two workers never spend the same single-use refresh token. */
    private const TOKEN_LOCK = 'invogrid.clearbooks.token';

    /**
     * Microseconds between requests. Clear Books rate-limits above five a
     * second, so 200ms is the documented limit exactly; the margin is that a
     * request also takes time to travel.
     */
    private const MIN_REQUEST_GAP_US = 200_000;

    /** When the last request left this process, for the pacer. */
    private static float $lastRequestAt = 0.0;

    private string $baseUrl;
    private string $clientId;
    private string $clientSecret;
    private string $businessId;

    public function __construct()
    {
        $this->baseUrl      = rtrim((string) Setting::get('clearbooks_base_url', ''), '/');
        $this->clientId     = (string) Setting::get('clearbooks_client_id', '');
        $this->clientSecret = (string) Setting::secret('clearbooks_client_secret');
        $this->businessId   = (string) Setting::get('clearbooks_business_id', '');

        if ($this->baseUrl === '') {
            throw new RuntimeException('No Clear Books API address is configured. Set it in Settings.');
        }

        if ($this->clientId === '' || $this->clientSecret === '') {
            throw new RuntimeException('No Clear Books application credentials are configured. Set them in Settings.');
        }
    }

    /** Are the application credentials present? Says nothing about authorisation. */
    public static function isConfigured(): bool
    {
        return Setting::isConfigured('clearbooks_base_url')
            && Setting::isConfigured('clearbooks_client_id')
            && Setting::isConfigured('clearbooks_client_secret');
    }

    /** Has somebody completed the consent flow? */
    public static function isConnected(): bool
    {
        return Setting::isConfigured('clearbooks_refresh_token');
    }

    /**
     * When the stored access token expires, as a unix timestamp, or null.
     */
    public static function expiresAt(): ?int
    {
        $value = (string) Setting::get('clearbooks_token_expires_at', '');

        return $value === '' || !ctype_digit($value) ? null : (int) $value;
    }

    // --- The consent flow ---------------------------------------------------

    /**
     * Where to send somebody to authorise this application.
     *
     * `$verifier` is the PKCE code verifier the caller has stashed in the
     * session; the challenge derived from it travels in the URL, and the
     * verifier itself is presented at the token endpoint. That is what stops a
     * stolen authorisation code from being redeemed by anybody else.
     */
    public function authorisationUrl(string $state, string $verifier): string
    {
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return Http::url((string) Setting::get('clearbooks_authorise_url', ''), '', [
            'response_type'         => 'code',
            'client_id'             => $this->clientId,
            'redirect_uri'          => self::redirectUri(),
            'scope'                 => (string) Setting::get('clearbooks_scopes', ''),
            'state'                 => $state,
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    /** Where Clear Books sends the browser back to. */
    public static function redirectUri(): string
    {
        $configured = (string) Setting::get('clearbooks_redirect_uri', '');

        if ($configured !== '') {
            return $configured;
        }

        return rtrim((string) Config::get('app.url', ''), '/') . '/admin/clearbooks/callback';
    }

    /**
     * Turn an authorisation code into a stored token pair.
     */
    public function exchangeCode(string $code, string $verifier): void
    {
        $this->storeTokens($this->token([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => self::redirectUri(),
            'code_verifier' => $verifier,
        ]));
    }

    /**
     * Forget the authorisation.
     *
     * The tokens are cleared locally only — Clear Books has no revocation
     * endpoint in this API, and the next consent flow supersedes them anyway.
     */
    public static function disconnect(): void
    {
        foreach (['clearbooks_access_token', 'clearbooks_refresh_token', 'clearbooks_token_expires_at'] as $key) {
            Setting::put($key, '');
        }
    }

    // --- Reference data -----------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    public function suppliers(): array
    {
        return $this->allPages('/accounting/suppliers');
    }

    /** @return array<int,array<string,mixed>> */
    public function accountCodes(): array
    {
        return $this->allPages('/accounting/accountCodes');
    }

    /**
     * VAT treatments for one side of the ledger.
     *
     * Not paginated — the spec declares no page parameters and no pagination
     * headers on this endpoint, so it is fetched whole.
     *
     * @return array<int,array<string,mixed>>
     */
    public function vatTreatments(string $vatType = 'purchases'): array
    {
        return $this->list('/accounting/vatTreatments/' . $this->vatType($vatType));
    }

    /**
     * VAT rates, optionally narrowed to one treatment.
     *
     * Which rates are legal depends on the treatment, so the cache is filled by
     * asking once per treatment rather than once overall.
     *
     * @return array<int,array<string,mixed>>
     */
    public function vatRates(string $vatType = 'purchases', ?string $treatment = null): array
    {
        $query = $treatment === null || $treatment === '' ? [] : ['vatTreatment' => $treatment];

        return $this->list('/accounting/vatRates/' . $this->vatType($vatType), $query);
    }

    // --- Writing ------------------------------------------------------------

    /**
     * Create a supplier.
     *
     * Called only from a human decision. Nothing in the pipeline creates a
     * supplier on its own: a wrong new record is harder to undo than a document
     * left waiting.
     *
     * @param array<string,mixed> $supplier At minimum `name`.
     * @return array<string,mixed>
     */
    public function createSupplier(array $supplier): array
    {
        if (trim((string) ($supplier['name'] ?? '')) === '') {
            throw new RuntimeException('A supplier needs a name.');
        }

        return $this->send('POST', '/accounting/suppliers', $supplier);
    }

    /**
     * Create a purchase document.
     *
     * `$purchaseType` is the API's own path segment — `bills`, `creditNotes` or
     * `expenses` — which is what `document_types.clearbooks_resource` holds, so
     * adding a document type stays a row rather than a branch here.
     *
     * @param array<string,mixed> $document
     * @return array<string,mixed>
     */
    public function createPurchase(string $purchaseType, array $document): array
    {
        $allowed = ['bills', 'creditNotes', 'expenses'];
        $type    = ltrim(str_replace('purchases/', '', trim($purchaseType)), '/');

        if (!in_array($type, $allowed, true)) {
            throw new RuntimeException(
                'Not a purchase document type Clear Books accepts: ' . $purchaseType
                . '. It must be one of ' . implode(', ', $allowed) . '.'
            );
        }

        return $this->send('POST', '/accounting/purchases/' . $type, $document);
    }

    /** @param array<string,mixed> $document @return array<string,mixed> */
    public function createBill(array $document): array
    {
        return $this->createPurchase('bills', $document);
    }

    /** @param array<string,mixed> $document @return array<string,mixed> */
    public function createCreditNote(array $document): array
    {
        return $this->createPurchase('creditNotes', $document);
    }

    /**
     * Attach a file to a purchase document.
     *
     * The body is the raw bytes as `application/octet-stream` and the name goes
     * in the path — not a multipart form, which is what most APIs would want
     * and what a first attempt at this would send.
     *
     * @return array<string,mixed>
     */
    public function attachToPurchase(string $purchaseType, int $purchaseId, string $fileName, string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('There is no file at ' . $path . ' to attach.');
        }

        $bytes = file_get_contents($path);

        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('Could not read ' . $path . ' to attach it.');
        }

        // A name is a path segment here, so anything that could climb out of it
        // is removed rather than escaped.
        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($fileName)) ?: 'attachment.pdf';
        $type     = ltrim(str_replace('purchases/', '', trim($purchaseType)), '/');

        $response = $this->request(
            'POST',
            '/accounting/purchases/' . $type . '/' . $purchaseId . '/attachments/' . rawurlencode($safeName),
            [],
            $bytes,
            'application/octet-stream',
            120
        );

        return $this->decode($response, 'attachment of ' . $safeName);
    }

    // --- Connection check ---------------------------------------------------

    /**
     * A cheap authenticated call, for the admin screen.
     *
     * @return array{ok:bool,message:string}
     */
    public function ping(): array
    {
        try {
            $response = $this->request('GET', '/accounting/vatTreatments/purchases');
        } catch (ClearBooksAuthException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } catch (HttpTransportException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        if (!$response->ok()) {
            return ['ok' => false, 'message' => self::explain($response)];
        }

        $decoded = $response->json();
        $count   = is_array($decoded) ? count($decoded) : 0;

        return [
            'ok'      => true,
            'message' => 'Connected' . ($this->businessId === '' ? '' : ' to business ' . $this->businessId)
                . ', ' . $count . ' purchase VAT treatment' . ($count === 1 ? '' : 's') . ' visible.',
        ];
    }

    /** The address of a purchase document in the Clear Books web interface. */
    public static function documentUrl(string $purchaseType, int $id): string
    {
        $base = rtrim((string) Setting::get('clearbooks_web_url', ''), '/');
        $type = ltrim(str_replace('purchases/', '', trim($purchaseType)), '/');

        return $base . '/accounting/purchases/' . $type . '/' . $id;
    }

    // --- Plumbing -----------------------------------------------------------

    /**
     * Walk every page of a paginated list.
     *
     * The walk stops when the current page equals the total, which is what the
     * API tells us directly — rather than "keep going until a short page",
     * which is wrong the moment a total happens to be an exact multiple of the
     * page size. A missing header means the endpoint is not paginated, so one
     * page is the whole answer. The bound is belt and braces against a server
     * that keeps claiming there is more.
     *
     * @param array<string,scalar|null> $query
     * @return array<int,array<string,mixed>>
     */
    private function allPages(string $path, array $query = []): array
    {
        $results = [];
        $page    = 1;

        do {
            $response = $this->request('GET', $path, $query + ['page' => $page, 'limit' => self::PAGE_LIMIT]);
            $decoded  = $this->decode($response, ltrim($path, '/'));

            foreach ($decoded as $row) {
                if (is_array($row)) {
                    $results[] = $row;
                }
            }

            $current = $response->header('x-pagination-current-page');
            $total   = $response->header('x-pagination-total-pages');

            $more = $current !== null && $total !== null && (int) $current < (int) $total;
            $page++;
        } while ($more && $page <= 500);

        return $results;
    }

    /**
     * A list endpoint that does not paginate.
     *
     * @param array<string,scalar|null> $query
     * @return array<int,array<string,mixed>>
     */
    private function list(string $path, array $query = []): array
    {
        $decoded = $this->decode($this->request('GET', $path, $query), ltrim($path, '/'));

        return array_values(array_filter($decoded, 'is_array'));
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function send(string $method, string $path, array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return $this->decode($this->request($method, $path, [], $body), ltrim($path, '/'));
    }

    /**
     * One authenticated request, with a single re-authentication retry.
     *
     * A 401 in the middle of a run is normally a token that expired a moment
     * after it was checked — a clock skew of seconds is enough. Refreshing and
     * repeating the request once turns that into a non-event; a second 401 is a
     * real authorisation failure and is raised as one.
     *
     * @param array<string,scalar|null> $query
     */
    private function request(
        string $method,
        string $path,
        array $query = [],
        ?string $body = null,
        string $contentType = 'application/json',
        int $timeout = Http::TIMEOUT,
        bool $isRetry = false,
    ): HttpResponse {
        $url     = Http::url($this->baseUrl . self::API_PREFIX, $path, $query);
        $headers = [
            'Authorization' => 'Bearer ' . $this->accessToken(),
            'Accept'        => 'application/json',
        ];

        if ($this->businessId !== '') {
            $headers['X-Business-ID'] = $this->businessId;
        }

        if ($body !== null) {
            $headers['Content-Type'] = $contentType;
        }

        $this->pace();

        /*
         * A GET may be repeated; anything else may not.
         *
         * Clear Books has no idempotency key, so a POST that timed out after
         * the record was written is indistinguishable from one that never
         * arrived — and repeating it puts a second bill in somebody's accounts.
         * The cache refresh, which is all GETs and is where the rate limit is
         * actually met, gets the retries.
         */
        $retries  = strtoupper($method) === 'GET' ? 2 : 0;
        $response = Http::request($method, $url, $headers, $body, $timeout, null, $retries);

        if ($response->status === 401 && !$isRetry) {
            $this->refresh(true);

            return $this->request($method, $path, $query, $body, $contentType, $timeout, true);
        }

        return $response;
    }

    /**
     * Keep under Clear Books' five-a-second limit rather than discovering it.
     *
     * `allPages()` walks a supplier list as fast as the network allows, which
     * on a real business is several hundred requests and is exactly where the
     * limit is met. Waiting 200ms between calls costs a cache refresh a few
     * seconds; being rate-limited costs it a 429, a backoff and a retry of work
     * already done.
     *
     * Per process, which is the right scope: the refresh is one cron job, and
     * two of them running at once is a problem the lock file already solves.
     */
    private function pace(): void
    {
        $gap = self::MIN_REQUEST_GAP_US - (int) ((microtime(true) - self::$lastRequestAt) * 1_000_000);

        if ($gap > 0) {
            usleep($gap);
        }

        self::$lastRequestAt = microtime(true);
    }

    /**
     * A usable access token, refreshed if it is about to expire.
     */
    private function accessToken(): string
    {
        $token   = (string) Setting::secret('clearbooks_access_token');
        $expires = self::expiresAt();

        if ($token !== '' && $expires !== null && $expires - self::REFRESH_MARGIN > time()) {
            return $token;
        }

        $this->refresh();

        return (string) Setting::secret('clearbooks_access_token');
    }

    /**
     * Spend the refresh token for a new pair.
     *
     * Held under a named database lock for the whole exchange, because a
     * refresh token is single use: two workers reaching this at the same moment
     * would each spend it, one would succeed, and the other's newly stored pair
     * would already be dead — leaving the integration needing a human to sign
     * in again. Having taken the lock, the settings are re-read: the other
     * process may have finished the job while this one was waiting, in which
     * case there is nothing left to do.
     */
    private function refresh(bool $force = false): void
    {
        $refreshToken = (string) Setting::secret('clearbooks_refresh_token');

        if ($refreshToken === '') {
            throw new ClearBooksAuthException(
                'InvoGrid is not authorised with Clear Books. Connect it from the Clear Books settings screen.'
            );
        }

        $locked = (int) Database::scalar('SELECT GET_LOCK(?, ?)', [self::TOKEN_LOCK, 15]) === 1;

        if (!$locked) {
            throw new ClearBooksException(
                'Another process is refreshing the Clear Books token and did not finish in time.',
                true
            );
        }

        try {
            // Somebody else may have refreshed while this call was queued
            // behind the lock. The cache is per-request, so it has to be
            // dropped before the check means anything.
            Setting::flush();

            $expires = self::expiresAt();

            if (!$force && $expires !== null && $expires - self::REFRESH_MARGIN > time()) {
                return;
            }

            $refreshToken = (string) Setting::secret('clearbooks_refresh_token');

            $this->storeTokens($this->token([
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refreshToken,
            ]));
        } finally {
            Database::scalar('SELECT RELEASE_LOCK(?)', [self::TOKEN_LOCK]);
        }
    }

    /**
     * Post to the token endpoint.
     *
     * Form-encoded, with the client credentials in the body rather than in a
     * Basic header: both are permitted by OAuth 2 and this is the shape Clear
     * Books' own examples use.
     *
     * @param array<string,string> $grant
     * @return array<string,mixed>
     */
    private function token(array $grant): array
    {
        $response = Http::request(
            'POST',
            $this->baseUrl . self::TOKEN_PATH,
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'],
            http_build_query($grant + [
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
            ], '', '&', PHP_QUERY_RFC3986)
        );

        $decoded = $response->json();

        if (!$response->ok() || !is_array($decoded) || !isset($decoded['access_token'])) {
            $reason = is_array($decoded)
                ? (string) ($decoded['error_description'] ?? $decoded['error'] ?? $response->errorSummary())
                : $response->errorSummary();

            throw new ClearBooksAuthException(
                'Clear Books would not issue a token: ' . rtrim($reason, '. ') . '.'
                . ' Reconnect from the Clear Books settings screen.',
                $response->status
            );
        }

        return $decoded;
    }

    /**
     * Store a token pair.
     *
     * Refuses rather than half-saves when the tokens cannot be encrypted:
     * writing a refresh token to the database in the clear is worse than a
     * failed connection, and `Setting::put()` already declines to do it.
     *
     * @param array<string,mixed> $payload
     */
    private function storeTokens(array $payload): void
    {
        $access  = (string) ($payload['access_token'] ?? '');
        $refresh = (string) ($payload['refresh_token'] ?? '');
        $expires = time() + (int) ($payload['expires_in'] ?? 3600);

        if (!Setting::put('clearbooks_access_token', $access)) {
            throw new ClearBooksAuthException(
                'The Clear Books token cannot be stored: APP_KEY is missing, so it cannot be encrypted.',
                0
            );
        }

        // A refresh grant always returns a new refresh token here, but an
        // absent one must not wipe the working one.
        if ($refresh !== '') {
            Setting::put('clearbooks_refresh_token', $refresh);
        }

        Setting::put('clearbooks_token_expires_at', (string) $expires);
    }

    /**
     * @return array<int|string,mixed>
     */
    private function decode(HttpResponse $response, string $what): array
    {
        if (!$response->ok()) {
            if ($response->status === 401 || $response->status === 403) {
                throw new ClearBooksAuthException(
                    'Clear Books refused the request (' . $response->status . '). ' . self::explain($response),
                    $response->status
                );
            }

            throw new ClearBooksException(
                'Clear Books rejected the ' . $what . '. ' . self::explain($response),
                $response->status === 429 || $response->status >= 500,
                $response->status,
                null,
                [
                    'endpoint' => $what,
                    'answered' => mb_substr(self::explain($response), 0, 400),
                    'took ms'  => $response->durationMs,
                ],
                Http::retryAfter($response)
            );
        }

        $decoded = $response->json();

        if ($decoded === null) {
            throw new ClearBooksException(
                'Clear Books answered the ' . $what . ' with something that is not JSON. '
                . 'Is the API address pointing at Clear Books itself rather than at a proxy or a login page?',
                false,
                $response->status
            );
        }

        return $decoded;
    }

    /**
     * Read the error out of a Clear Books failure.
     *
     * The body is an array of `{errorCode, errorMessage}` — not the object
     * shape `HttpResponse::errorSummary()` looks for, so it would otherwise
     * print the raw JSON and lose the one sentence worth reading.
     */
    private static function explain(HttpResponse $response): string
    {
        $decoded  = $response->json();
        $messages = [];

        foreach (is_array($decoded) ? $decoded : [] as $error) {
            if (!is_array($error)) {
                continue;
            }

            $message = trim((string) ($error['errorMessage'] ?? ''));
            $code    = trim((string) ($error['errorCode'] ?? ''));

            if ($message !== '') {
                $messages[] = $code === '' ? $message : $message . ' (' . $code . ')';
            }
        }

        if ($messages === []) {
            return $response->errorSummary();
        }

        return 'HTTP ' . $response->status . ': ' . implode('; ', $messages);
    }

    private function vatType(string $vatType): string
    {
        if (!in_array($vatType, ['sales', 'purchases'], true)) {
            throw new RuntimeException('VAT type must be sales or purchases, not ' . $vatType . '.');
        }

        return $vatType;
    }
}
