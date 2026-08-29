<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Models\Document;
use App\Models\DocumentEvent;
use App\Models\Setting;
use App\Services\Pipeline;
use Throwable;

/**
 * The Paperless webhook receiver.
 *
 * Constraints this endpoint is built around, all read out of paperless-ngx's
 * own source rather than guessed:
 *
 *  - **Paperless gives it five seconds.** `httpx.Client(timeout=5.0)`. So this
 *    does the smallest possible amount of work — check the secret, write a row,
 *    queue a job — and returns. Downloading the PDF here would time out on any
 *    document of size, and a timed-out webhook is a *retried* webhook.
 *  - **A non-2xx is retried up to three times with backoff.** Idempotency is
 *    therefore not a nicety. A second delivery of the same id must find the
 *    document already registered and do nothing.
 *  - **Redirects are not followed.** If `FORCE_HTTPS` bounces the request, the
 *    webhook simply fails. The workflow has to be given the final https URL.
 *  - **The body shape is whatever the workflow was configured to send.** With
 *    `use_params` it is a dict of rendered values; with `body` it is one
 *    rendered string, posted as raw content with no content type. So this
 *    accepts JSON, form encoding, a query string and a bare number, and looks
 *    for the id under any of the names somebody might plausibly have used.
 *
 * It is deliberately outside the CSRF and session middleware: the caller is a
 * server, and the shared secret is what authenticates it.
 */
final class WebhookController extends Controller
{
    /**
     * Keys the document id might arrive under.
     *
     * `doc_id` first because that is what Paperless's own placeholder is called
     * — `{{doc_id}}` — and so what a workflow following the README will send.
     * The rest are what people actually write when they are configuring it from
     * memory.
     */
    private const ID_KEYS = ['doc_id', 'document_id', 'id', 'pk', 'documentId', 'docId'];

    /** Header names the shared secret may arrive in. */
    private const SECRET_HEADERS = ['X-InvoGrid-Token', 'X-Webhook-Secret', 'Authorization'];

    public function receive(): void
    {
        $raw         = (string) file_get_contents('php://input');
        $contentType = (string) (Request::header('Content-Type') ?? '');

        // Logged before anything is validated, and whether or not it is
        // accepted. Confirming the placeholder against a real delivery is
        // exactly what this is for — see the README's Paperless setup section.
        $this->logDelivery($contentType, $raw);

        $expected = (string) (Setting::secret('paperless_webhook_secret') ?? '');

        if ($expected === '') {
            // Refusing rather than waving it through. An endpoint that accepts
            // anything because nobody configured a secret is worse than one
            // that plainly does not work yet.
            Response::json([
                'error' => 'InvoGrid has no webhook secret configured. Set one in Settings.',
            ], 503);
        }

        if (!$this->secretIsValid($expected, $raw, $contentType)) {
            error_log('[webhook] rejected a delivery from ' . Request::ip() . ': bad or missing secret');

            Response::json(['error' => 'Rejected.'], 401);
        }

        $paperlessId = $this->extractDocumentId($raw, $contentType);

        if ($paperlessId === null) {
            error_log('[webhook] no document id in a delivery from ' . Request::ip()
                . ' (content-type: ' . ($contentType ?: 'none') . ')');

            // 400, not 422: this will never succeed on a retry, and the message
            // says what to fix.
            Response::json([
                'error' => 'No document id in the payload. The Paperless workflow should send doc_id, '
                    . 'using the {{doc_id}} placeholder. Check storage/logs/webhook.log for what arrived.',
            ], 400);
        }

        try {
            ['document' => $document, 'created' => $created] = Document::register($paperlessId);
        } catch (Throwable $e) {
            error_log('[webhook] could not register paperless document ' . $paperlessId . ': ' . $e->getMessage());

            // 500 so Paperless retries: this one might well come right.
            Response::json(['error' => 'Could not register the document.'], 500);
        }

        $id     = (int) $document['id'];
        $status = (string) $document['status'];

        if (!$created) {
            // The re-delivery case, and the ordinary one. Nothing is re-run: a
            // document already past `received` has either been worked or is
            // being worked, and starting again would duplicate the work and
            // could contradict a decision a human already made.
            DocumentEvent::record($id, 'webhook', DocumentEvent::SKIPPED, 'Already registered; ignored a repeat delivery.');

            Response::json([
                'status'      => 'already-known',
                'document'    => $id,
                'doc_status'  => $status,
            ], 200);
        }

        DocumentEvent::record($id, 'webhook', DocumentEvent::SUCCEEDED, 'Registered from a Paperless webhook.');
        Pipeline::advance($id, $status);

        Response::json([
            'status'     => 'accepted',
            'document'   => $id,
            'doc_status' => $status,
        ], 202);
    }

    /**
     * Does the request carry the shared secret?
     *
     * Accepted in a header, in the query string, or in the body — because the
     * workflow's webhook action can set headers *or* params but a given
     * Paperless setup may find one easier than the other. `hash_equals`
     * throughout: a string comparison that returns early leaks the secret one
     * character at a time.
     */
    private function secretIsValid(string $expected, string $raw, string $contentType): bool
    {
        foreach (self::SECRET_HEADERS as $header) {
            $value = (string) (Request::header($header) ?? '');

            if ($value === '') {
                continue;
            }

            // Tolerate "Bearer <secret>" and "Token <secret>" in Authorization.
            foreach (['Bearer ', 'Token '] as $prefix) {
                if (stripos($value, $prefix) === 0) {
                    $value = substr($value, strlen($prefix));
                    break;
                }
            }

            if (hash_equals($expected, trim($value))) {
                return true;
            }
        }

        $fromQuery = (string) (Request::query('token', '') ?? '');
        if ($fromQuery !== '' && hash_equals($expected, $fromQuery)) {
            return true;
        }

        $payload = $this->payload($raw, $contentType);

        foreach (['token', 'secret', 'webhook_secret'] as $key) {
            $value = $payload[$key] ?? null;

            if (is_string($value) && $value !== '' && hash_equals($expected, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The document id, from wherever it turns out to be.
     *
     * Paperless renders `{{doc_id}}` to a *string*, and for a
     * Consumption-Started trigger it renders to an **empty** string — the
     * document does not exist yet at that point, so there is no id to send.
     * That is why an empty or zero value is rejected here rather than becoming
     * document 0: it means the workflow is on the wrong trigger, and the README
     * says so.
     */
    private function extractDocumentId(string $raw, string $contentType): ?int
    {
        $payload = $this->payload($raw, $contentType);

        foreach (self::ID_KEYS as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }

            $value = $payload[$key];

            if (is_int($value) && $value > 0) {
                return $value;
            }

            if (is_string($value) && ctype_digit(trim($value)) && (int) trim($value) > 0) {
                return (int) trim($value);
            }
        }

        // A `body` of nothing but the id, which is the simplest workflow
        // configuration there is.
        $trimmed = trim($raw);
        if ($trimmed !== '' && ctype_digit($trimmed) && (int) $trimmed > 0) {
            return (int) $trimmed;
        }

        return null;
    }

    /**
     * The request payload as an array, whatever it was encoded as.
     *
     * Memoised: `php://input` has already been read once by the caller, and the
     * parsing is done twice — once for the secret, once for the id.
     *
     * @return array<string,mixed>
     */
    private function payload(string $raw, string $contentType): array
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $payload = $_GET;

        // Form-encoded bodies land in $_POST already; a JSON body does not.
        if ($_POST !== []) {
            $payload = array_merge($payload, $_POST);
        }

        $trimmed = trim($raw);

        if ($trimmed !== '') {
            if (str_contains(strtolower($contentType), 'json') || str_starts_with($trimmed, '{')) {
                $decoded = json_decode($trimmed, true);

                if (is_array($decoded)) {
                    $payload = array_merge($payload, $decoded);
                }
            } elseif ($_POST === [] && str_contains($trimmed, '=')) {
                // A `body` action posts raw content with no content type, so
                // PHP will not have parsed it even when it is form-shaped.
                parse_str($trimmed, $parsed);
                $payload = array_merge($payload, $parsed);
            }
        }

        return $cache = $payload;
    }

    /**
     * Append the delivery to a small log.
     *
     * The point is the first delivery from a newly configured workflow: the
     * exact payload shape is whatever was typed into Paperless, and looking at
     * one real request settles it in seconds where reading documentation does
     * not. Kept short and truncated so it cannot grow without bound.
     *
     * The secret is redacted, wherever it appears. A log that records the
     * credential it exists to check is a log that has undone the credential.
     */
    private function logDelivery(string $contentType, string $raw): void
    {
        $path = rtrim((string) Config::get('storage.logs'), '/' . chr(92))
            . DIRECTORY_SEPARATOR . 'webhook.log';

        // Rotate rather than truncate: the previous file is often the one with
        // the interesting delivery in it.
        if (is_file($path) && filesize($path) > 512 * 1024) {
            @rename($path, $path . '.1');
        }

        $secret = (string) (Setting::secret('paperless_webhook_secret') ?? '');
        $body   = str_limit(preg_replace('/\s+/', ' ', $raw) ?? $raw, 2000);

        if ($secret !== '') {
            $body = str_replace($secret, '[redacted]', $body);
        }

        $headers = [];
        foreach (self::SECRET_HEADERS as $header) {
            if (Request::header($header) !== null) {
                $headers[] = $header . ': [present]';
            }
        }

        @file_put_contents(
            $path,
            sprintf(
                "[%s] %s from %s | content-type: %s | %s | body: %s\n",
                date('Y-m-d H:i:s'),
                Request::method(),
                Request::ip(),
                $contentType !== '' ? $contentType : 'none',
                $headers === [] ? 'no secret header' : implode(', ', $headers),
                $body !== '' ? $body : '(empty)'
            ),
            FILE_APPEND | LOCK_EX
        );
    }
}
