<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Models\Setting;
use RuntimeException;

/**
 * Paperless-ngx v3 REST client.
 *
 * Authentication is `Authorization: Token <token>` — Paperless's own scheme,
 * not Bearer. The token is a settings row, encrypted at rest, and is only ever
 * read here.
 *
 * Endpoint shapes worth knowing, because they are not all guessable:
 *
 *  - The source PDF is `documents/{id}/download/?original=true`. The value has
 *    to be the exact string "true"; Paperless compares it literally, so "1" and
 *    "True" both quietly return the *archive* version instead — which is the
 *    OCR'd, re-rendered copy, not the scan we want to read.
 *  - Custom field values on a document are a list of objects,
 *    `[{"field": <id>, "value": <value>}, ...]`, and a PATCH **replaces the
 *    whole list**. Anything already on the document that is not in the list is
 *    removed, hence setCustomFields() merging by default.
 *  - Notes are their own sub-resource, `documents/{id}/notes/`, and the body
 *    key is `note`. Paperless calls them notes; nothing in the API says
 *    "comment".
 */
final class PaperlessClient
{
    /** Generous: a scanned document can be tens of megabytes over a slow link. */
    private const DOWNLOAD_TIMEOUT = 180;

    private string $baseUrl;
    private string $token;

    public function __construct(?string $baseUrl = null, ?string $token = null)
    {
        $this->baseUrl = rtrim($baseUrl ?? (string) Setting::get('paperless_base_url', ''), '/');
        $this->token   = $token ?? (string) Setting::secret('paperless_token');

        if ($this->baseUrl === '') {
            throw new RuntimeException('No Paperless address is configured. Set it in Settings.');
        }

        if ($this->token === '') {
            throw new RuntimeException('No Paperless API token is configured. Set it in Settings.');
        }
    }

    /** Is Paperless configured well enough to bother trying? */
    public static function isConfigured(): bool
    {
        return Setting::isConfigured('paperless_base_url') && Setting::isConfigured('paperless_token');
    }

    /**
     * A cheap authenticated call, for the Settings screen's "test connection".
     *
     * @return array{ok:bool,message:string}
     */
    public function ping(): array
    {
        try {
            $response = $this->get('documents/', ['page_size' => 1]);
        } catch (HttpTransportException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        if ($response->status === 401 || $response->status === 403) {
            return ['ok' => false, 'message' => 'Paperless refused the API token.'];
        }

        if (!$response->ok()) {
            return ['ok' => false, 'message' => $response->errorSummary()];
        }

        $count = $response->json()['count'] ?? null;

        return [
            'ok'      => true,
            'message' => 'Connected' . (is_int($count) ? ", {$count} documents visible." : '.'),
        ];
    }

    // --- Documents ----------------------------------------------------------

    /**
     * One document's metadata.
     *
     * @return array<string,mixed>
     */
    public function document(int $id): array
    {
        $response = $this->get('documents/' . $id . '/');

        if ($response->status === 404) {
            throw new PaperlessNotFoundException('Paperless has no document ' . $id . '.');
        }

        return $this->decode($response, 'document ' . $id);
    }

    /**
     * Download the **original** file — the scan as it arrived, not the archived
     * copy Paperless generates.
     *
     * Returns the number of bytes written. Written straight to disk rather than
     * through memory; a partial download is deleted rather than left to look
     * like a real file.
     */
    public function downloadOriginal(int $id, string $path): int
    {
        $directory = dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create ' . $directory);
        }

        $response = Http::download(
            Http::url($this->baseUrl, '/api/documents/' . $id . '/download/', ['original' => 'true']),
            $path,
            $this->headers(),
            self::DOWNLOAD_TIMEOUT,
            0,

            /*
             * A ceiling on what one document may write to disk.
             *
             * Paperless is a separate service on the network: trusted-ish
             * rather than trusted. A misconfiguration, a wrong id or a
             * pathological scan should not be able to take the application down
             * by filling the volume that holds every other document, the page
             * images and the log. The transfer is aborted the moment it goes
             * over rather than measured afterwards — measuring afterwards is
             * measuring after the disk is full.
             */
            (int) Config::get('uploads.max_pdf_bytes', 100 * 1024 * 1024)
        );

        if ($response->status === 404) {
            @unlink($path);

            throw new PaperlessNotFoundException('Paperless has no document ' . $id . ' to download.');
        }

        if (!$response->ok()) {
            @unlink($path);

            throw new RuntimeException('Could not download document ' . $id . '. ' . $response->errorSummary());
        }

        $bytes = is_file($path) ? (int) filesize($path) : 0;

        if ($bytes === 0) {
            @unlink($path);

            throw new RuntimeException('Paperless returned an empty file for document ' . $id . '.');
        }

        // A downstream stage would treat an HTML error page as a PDF and fail
        // somewhere far less obvious. Check what actually arrived.
        $handle = fopen($path, 'rb');
        $magic  = $handle === false ? '' : (string) fread($handle, 5);
        if ($handle !== false) {
            fclose($handle);
        }

        if ($magic !== '%PDF-') {
            @unlink($path);

            throw new RuntimeException(
                'Document ' . $id . ' did not come back as a PDF. Paperless sent '
                . ($response->header('content-type') ?? 'an unknown content type') . '.'
            );
        }

        return $bytes;
    }

    /**
     * Update a document. Only the keys given are touched.
     *
     * @param array<string,mixed> $fields correspondent, document_type,
     *                                    storage_path, title, content, tags,
     *                                    custom_fields
     * @return array<string,mixed> The document as Paperless now holds it
     */
    public function updateDocument(int $id, array $fields): array
    {
        $allowed = ['correspondent', 'document_type', 'storage_path', 'title', 'content', 'tags', 'custom_fields', 'created', 'owner'];
        $unknown = array_diff(array_keys($fields), $allowed);

        if ($unknown !== []) {
            // A typo in a field name is silently ignored by Paperless, which
            // means a write that appears to work and changes nothing.
            throw new RuntimeException('Not a document field: ' . implode(', ', $unknown));
        }

        $response = Http::patchJson(
            Http::url($this->baseUrl, '/api/documents/' . $id . '/'),
            $fields,
            $this->headers()
        );

        return $this->decode($response, 'update of document ' . $id);
    }

    /**
     * Set custom field values, keeping the ones already on the document.
     *
     * A PATCH of `custom_fields` replaces the entire list, so writing one field
     * naively wipes every other. This reads what is there, merges by field id
     * and writes the union back.
     *
     * @param array<int,mixed> $values Field id => value
     * @return array<string,mixed>
     */
    public function setCustomFields(int $id, array $values, bool $merge = true): array
    {
        $existing = [];

        if ($merge) {
            foreach ((array) ($this->document($id)['custom_fields'] ?? []) as $entry) {
                if (is_array($entry) && isset($entry['field'])) {
                    $existing[(int) $entry['field']] = $entry['value'] ?? null;
                }
            }
        }

        // The new values win where they overlap.
        $merged  = $existing;
        foreach ($values as $fieldId => $value) {
            $merged[(int) $fieldId] = $value;
        }

        $payload = [];
        foreach ($merged as $fieldId => $value) {
            $payload[] = ['field' => $fieldId, 'value' => $value];
        }

        return $this->updateDocument($id, ['custom_fields' => $payload]);
    }

    /**
     * Add a note to a document.
     *
     * Paperless calls these notes. The body key is `note`, singular.
     */
    public function addNote(int $id, string $note): bool
    {
        $note = trim($note);

        if ($note === '') {
            return false;
        }

        $response = Http::postJson(
            Http::url($this->baseUrl, '/api/documents/' . $id . '/notes/'),
            ['note' => $note],
            $this->headers()
        );

        if (!$response->ok()) {
            throw new RuntimeException('Could not add a note to document ' . $id . '. ' . $response->errorSummary());
        }

        return true;
    }

    /**
     * Every document Paperless holds against one correspondent.
     *
     * The question the correspondent sync has to answer before it deletes
     * anything, and the reason it asks Paperless rather than its own tables: a
     * document scanned before InvoGrid existed, or filed by hand, is still a
     * document pointing at that correspondent, and deleting the correspondent
     * out from under it would silently unfile it.
     *
     * @return array<int,array<string,mixed>>
     */
    public function documentsForCorrespondent(int $correspondentId): array
    {
        return $this->allPages('documents/', 250, ['correspondent__id' => $correspondentId]);
    }

    /** Move a document to a different correspondent. */
    public function setCorrespondent(int $documentId, int $correspondentId): array
    {
        return $this->updateDocument($documentId, ['correspondent' => $correspondentId]);
    }

    // --- Correspondents -----------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    public function correspondents(): array
    {
        return $this->allPages('correspondents/');
    }

    /** @return array<string,mixed> */
    public function createCorrespondent(string $name, array $extra = []): array
    {
        $response = Http::postJson(
            Http::url($this->baseUrl, '/api/correspondents/'),
            ['name' => $name] + $extra,
            $this->headers()
        );

        return $this->decode($response, 'creation of correspondent ' . $name);
    }

    /** @return array<string,mixed> */
    public function updateCorrespondent(int $id, array $fields): array
    {
        $response = Http::patchJson(
            Http::url($this->baseUrl, '/api/correspondents/' . $id . '/'),
            $fields,
            $this->headers()
        );

        return $this->decode($response, 'update of correspondent ' . $id);
    }

    public function deleteCorrespondent(int $id): bool
    {
        $response = Http::delete(Http::url($this->baseUrl, '/api/correspondents/' . $id . '/'), $this->headers());

        // 404 counts as deleted: the caller wanted it gone and it is gone.
        return $response->ok() || $response->status === 404;
    }

    // --- Custom fields ------------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    public function customFields(): array
    {
        return $this->allPages('custom_fields/');
    }

    /**
     * @param array<string,mixed> $extra `select_options` for a select field
     * @return array<string,mixed>
     */
    public function createCustomField(string $name, string $dataType, array $extra = []): array
    {
        $response = Http::postJson(
            Http::url($this->baseUrl, '/api/custom_fields/'),
            ['name' => $name, 'data_type' => $dataType] + $extra,
            $this->headers()
        );

        return $this->decode($response, 'creation of custom field ' . $name);
    }

    /** @return array<string,mixed> */
    public function updateCustomField(int $id, array $fields): array
    {
        $response = Http::patchJson(
            Http::url($this->baseUrl, '/api/custom_fields/' . $id . '/'),
            $fields,
            $this->headers()
        );

        return $this->decode($response, 'update of custom field ' . $id);
    }

    public function deleteCustomField(int $id): bool
    {
        $response = Http::delete(Http::url($this->baseUrl, '/api/custom_fields/' . $id . '/'), $this->headers());

        return $response->ok() || $response->status === 404;
    }

    // --- Document types and tags -------------------------------------------

    /** @return array<int,array<string,mixed>> */
    public function documentTypes(): array
    {
        return $this->allPages('document_types/');
    }

    /** @return array<int,array<string,mixed>> */
    public function tags(): array
    {
        return $this->allPages('tags/');
    }

    /** The address of a document in the Paperless web interface. */
    public function documentUrl(int $id): string
    {
        return $this->baseUrl . '/documents/' . $id . '/';
    }

    // --- Plumbing -----------------------------------------------------------

    /** @param array<string,scalar|null> $query */
    private function get(string $path, array $query = []): HttpResponse
    {
        return Http::get(Http::url($this->baseUrl, '/api/' . ltrim($path, '/'), $query), $this->headers());
    }

    /**
     * Every page of a list endpoint, followed through `next`.
     *
     * Paperless pages at 25 by default and these lists are read whole — every
     * supplier, every custom field. The page size is pushed up to keep the
     * round trips down, and the loop is bounded so a server that keeps handing
     * back a `next` cannot spin forever.
     *
     * @param array<string,scalar|null> $query Filters, e.g. correspondent__id
     * @return array<int,array<string,mixed>>
     */
    private function allPages(string $path, int $pageSize = 250, array $query = []): array
    {
        $results = [];
        $page    = 1;

        do {
            $response = $this->get($path, $query + ['page' => $page, 'page_size' => $pageSize]);
            $decoded  = $this->decode($response, $path);

            foreach ((array) ($decoded['results'] ?? []) as $row) {
                $results[] = $row;
            }

            $hasMore = !empty($decoded['next']);
            $page++;
        } while ($hasMore && $page <= 200);

        return $results;
    }

    /**
     * @return array<string,mixed>
     */
    private function decode(HttpResponse $response, string $what): array
    {
        if ($response->status === 401 || $response->status === 403) {
            throw new RuntimeException('Paperless refused the API token (' . $response->status . ').');
        }

        if (!$response->ok()) {
            throw new RuntimeException('Paperless rejected the ' . $what . '. ' . $response->errorSummary());
        }

        $decoded = $response->json();

        if ($decoded === null) {
            throw new RuntimeException(
                'Paperless answered the ' . $what . ' with something that is not JSON. '
                . 'Is the base URL pointing at Paperless itself rather than a proxy or a login page?'
            );
        }

        return $decoded;
    }

    /** @return array<string,string> */
    private function headers(): array
    {
        return [
            'Authorization' => 'Token ' . $this->token,
            'Accept'        => 'application/json',
        ];
    }
}
