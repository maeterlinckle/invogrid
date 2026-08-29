<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * A transcription of a document's pages.
 *
 * Kept per run rather than overwritten. Re-reading a document with a different
 * model is the obvious thing to try when an extraction looks wrong, and being
 * able to put the two transcriptions side by side is what makes that useful.
 * The newest is the one downstream stages read.
 *
 * The response is **parsed once, here, on the way in**. Everything after this
 * point reads columns. Nothing re-parses the raw text — and in particular no
 * template does, which is how this started out and is the habit the n8n flow
 * had to live with because it had nowhere to put the structure.
 */
final class OcrResult
{
    /** @param array<string,mixed> $fields */
    public static function create(int $documentId, array $fields): int
    {
        $raw        = (string) $fields['raw_text'];
        $structured = $fields['structured'] ?? null;

        // A response that came back as prose rather than JSON is still a
        // perfectly good transcription. It simply has no structure to promote,
        // and the transcription is the whole of it.
        $ocrText = is_array($structured) && isset($structured['ocrText'])
            ? (string) $structured['ocrText']
            : $raw;

        $notesPresent = null;
        if (is_array($structured) && array_key_exists('notesPresent', $structured)) {
            $notesPresent = $structured['notesPresent'] ? 1 : 0;
        }

        return Database::insert('ocr_results', [
            'document_id'        => $documentId,
            'llm_provider'       => $fields['llm_provider'],
            'llm_model'          => $fields['llm_model'],
            'raw_text'           => $raw,
            'ocr_text'           => $ocrText,
            'structured_json'    => is_array($structured)
                ? json_encode($structured, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : null,
            'notes_present'      => $notesPresent,
            'prompt_template_id' => $fields['prompt_template_id'] ?? null,
            'prompt_tokens'      => $fields['prompt_tokens'] ?? null,
            'completion_tokens'  => $fields['completion_tokens'] ?? null,
            'duration_ms'        => $fields['duration_ms'] ?? null,
        ]);
    }

    /**
     * The structured half of a result, decoded.
     *
     * Everything the model reported beyond the transcription itself: the
     * annotations, the custom fields, and whatever a future prompt version adds
     * alongside them. Null when the response was not JSON.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>|null
     */
    public static function structured(array $row): ?array
    {
        $json = $row['structured_json'] ?? null;

        if (!is_string($json) || $json === '') {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * The transcription a downstream prompt should be given.
     *
     * The extraction prompts are told to ignore everything from `### Notes`
     * onward, so the section travels with the text for a human without being
     * read as invoice content by the machine.
     *
     * @param array<string,mixed> $row
     */
    public static function text(array $row): string
    {
        $text = $row['ocr_text'] ?? null;

        return is_string($text) && $text !== '' ? $text : (string) ($row['raw_text'] ?? '');
    }

    /** @return array<string,mixed>|null */
    public static function latest(int $documentId): ?array
    {
        return Database::selectOne(
            'SELECT * FROM ocr_results WHERE document_id = ? ORDER BY created_at DESC, id DESC LIMIT 1',
            [$documentId]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function forDocument(int $documentId): array
    {
        return Database::select(
            'SELECT o.*, p.template_key, p.version AS prompt_version
               FROM ocr_results o
               LEFT JOIN prompt_templates p ON p.id = o.prompt_template_id
              WHERE o.document_id = ?
              ORDER BY o.created_at DESC, o.id DESC',
            [$documentId]
        );
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::selectOne('SELECT * FROM ocr_results WHERE id = ?', [$id]);
    }
}
