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

        $annotations = is_array($structured) && is_array($structured['handwrittenAnnotations'] ?? null)
            ? $structured['handwrittenAnnotations']
            : null;

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

            // The three annotation fields, promoted for the same reason
            // `notes_present` was: the routing decision tests one of them on
            // every document, and a decision that has to decode a JSON blob to
            // reach its input is a decision nothing can index or query.
            'clearbooks_number'  => self::reference($structured['clearbooksNumber'] ?? null),
            'project_code'       => self::reference($structured['project'] ?? null),
            'annotations_json'   => $annotations === null
                ? null
                : json_encode($annotations, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),

            'prompt_template_id' => $fields['prompt_template_id'] ?? null,
            'prompt_tokens'      => $fields['prompt_tokens'] ?? null,
            'completion_tokens'  => $fields['completion_tokens'] ?? null,
            'duration_ms'        => $fields['duration_ms'] ?? null,
        ]);
    }

    /**
     * One of the two hand-written references, tidied but not judged.
     *
     * The "#" the prompt says a Clearbooks Number is usually written with is
     * not part of the reference, and `#80421` and `80421` must not be two
     * different answers. Nothing else is corrected: whether what came back is
     * *usable* is `isUsableNumber()`'s question, asked by the stage that routes
     * on it, and a column that quietly dropped an unusable value would leave
     * nothing to show the person asking why their document went the other way.
     */
    private static function reference(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $trimmed = trim(ltrim(trim((string) $value), '#'));

        return $trimmed === '' ? null : mb_substr($trimmed, 0, 32);
    }

    /**
     * Is this Clearbooks Number one the pipeline can act on?
     *
     * The prompt is explicit that the number is digits only, and says why: a
     * circled code with letters in it is a Project, which is a different field
     * with a different meaning. So a value that is not digits is a misread, and
     * routing a document to the Existing Invoice flow on the strength of one
     * would send it looking for a Clear Books record that cannot exist.
     */
    public static function isUsableNumber(?string $number): bool
    {
        return $number !== null && $number !== '' && ctype_digit($number);
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
     * The transcription and nothing else. It used to carry an appended
     * `### Notes` section restating the annotations, which every extraction
     * prompt then had to be told to skip; the annotations are columns now, and
     * `annotations()` is where a prompt that wants them gets them.
     *
     * @param array<string,mixed> $row
     */
    public static function text(array $row): string
    {
        $text = $row['ocr_text'] ?? null;

        return is_string($text) && $text !== '' ? $text : (string) ($row['raw_text'] ?? '');
    }

    /**
     * The handwritten marks found on the page, each as the prompt describes
     * them: `{text, inkColor, marksPrintedText, location}`.
     *
     * Read from its own column rather than out of `structured_json`, so a
     * caller that wants only this does not decode the whole response — and so
     * the list is empty rather than missing when the model answered in prose.
     *
     * @param array<string,mixed> $row
     * @return array<int,array<string,mixed>>
     */
    public static function annotations(array $row): array
    {
        $json = $row['annotations_json'] ?? null;

        if (!is_string($json) || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, 'is_array'));
    }

    /**
     * The Clearbooks Number read off the page, or null.
     *
     * @param array<string,mixed> $row
     */
    public static function clearbooksNumber(array $row): ?string
    {
        $value = $row['clearbooks_number'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The project code read off the page, or null.
     *
     * @param array<string,mixed> $row
     */
    public static function projectCode(array $row): ?string
    {
        $value = $row['project_code'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
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
