<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use RuntimeException;

/**
 * The document-specific fields to pull out of a page — a hand-written
 * "Clearbooks Number", a circled project code.
 *
 * Data rather than code: adding one is a row here plus a line of prompt hint,
 * and the extraction stage picks it up on the next document. `data_type`
 * mirrors Paperless's own custom-field types so a value maps straight onto the
 * Paperless field it is paired with.
 */
final class CustomField
{
    public const EXTRACTED  = 'extracted';
    public const SUBMISSION = 'submission';

    /**
     * Active fields, optionally narrowed to one origin.
     *
     * The two origins must not be confused. An `extracted` field is read off
     * the scanned page and is offered to the extraction prompt; a `submission`
     * field is **produced** by the submission — the Clear Books bill id, the
     * document number Clear Books assigned — and written into Paperless
     * afterwards.
     *
     * Handing a submission field to a vision model asks it to find a number
     * that does not exist until InvoGrid creates the record, and it will
     * oblige with something. Callers say which they want; `active()` with no
     * argument returns both, and is only for a screen listing every field.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function active(?string $source = null): array
    {
        $sql    = 'SELECT * FROM custom_fields WHERE active = 1';
        $params = [];

        if ($source !== null) {
            $sql     .= ' AND source = ?';
            $params[] = $source;
        }

        return Database::select($sql . ' ORDER BY sort_order, label', $params);
    }

    /**
     * The fields read off the page — what the extraction stage asks about.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function extracted(): array
    {
        return self::active(self::EXTRACTED);
    }

    /**
     * The fields the submission fills in and the write-back sends to Paperless.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forSubmission(): array
    {
        return self::active(self::SUBMISSION);
    }

    /**
     * Every data type a field may have.
     *
     * Mirrors Paperless's own list so a value maps straight onto the field it
     * is paired with — except `longtext`, which is InvoGrid's and becomes a
     * Paperless `string` when one is created. Keeping the two lists close is
     * what lets `coerce()` be the only place a value is converted.
     *
     * @var array<string,string>
     */
    public const DATA_TYPES = [
        'string'   => 'Text',
        'longtext' => 'Long text',
        'integer'  => 'Whole number',
        'float'    => 'Number',
        'monetary' => 'Money',
        'date'     => 'Date',
        'boolean'  => 'Yes / no',
        'url'      => 'Web address',
        'select'   => 'One of a list',
    ];

    /** @return array<int,array<string,mixed>> */
    public static function all(): array
    {
        return Database::select('SELECT * FROM custom_fields ORDER BY sort_order, label');
    }

    /** @return array<string,mixed>|null */
    public static function findById(int $id): ?array
    {
        return Database::selectOne('SELECT * FROM custom_fields WHERE id = ?', [$id]);
    }

    /**
     * Add a field.
     *
     * The key is settled here and **never again**: stored values in
     * `extractions.custom_field_values` are keyed by it, so renaming one later
     * would orphan every value already read off a document. `update()` refuses
     * to change it for that reason, and the form shows it as read-only once the
     * field exists.
     *
     * @param array<string,mixed> $fields
     */
    public static function create(array $fields): int
    {
        $key = self::normaliseKey((string) ($fields['field_key'] ?? ''));

        if ($key === '') {
            throw new RuntimeException('A field needs a key — letters, numbers and underscores.');
        }

        if (self::find($key) !== null) {
            throw new RuntimeException('There is already a field with the key "' . $key . '".');
        }

        return Database::insert('custom_fields', self::writable($fields) + [
            'field_key'  => $key,
            'sort_order' => (int) ($fields['sort_order'] ?? self::nextSortOrder()),
        ]);
    }

    /**
     * Change a field. The key is not among the things that may change.
     *
     * @param array<string,mixed> $fields
     * @return array<int,string> What actually changed
     */
    public static function update(int $id, array $fields): array
    {
        $before = self::findById($id);

        if ($before === null) {
            throw new RuntimeException('No such field.');
        }

        if (isset($fields['field_key']) && self::normaliseKey((string) $fields['field_key']) !== (string) $before['field_key']) {
            throw new RuntimeException(
                'A field key cannot be changed once it exists: every value already read off a '
                . 'document is stored under it. Deactivate this one and add a new field instead.'
            );
        }

        $update  = self::writable($fields);
        $changed = [];

        foreach ($update as $column => $value) {
            if ((string) ($before[$column] ?? '') !== (string) ($value ?? '')) {
                $changed[] = $column;
            }
        }

        if (isset($fields['sort_order'])) {
            $update['sort_order'] = (int) $fields['sort_order'];
        }

        Database::update('custom_fields', $update, $id);

        return $changed;
    }

    /**
     * Take a field out of use, or put it back.
     *
     * Never deleted. A deactivated field keeps its row, so an extraction from
     * last month still resolves the values stored against it, and turning it
     * back on picks up where it left off. It simply stops being offered to the
     * extraction prompt and stops being written back.
     */
    public static function setActive(int $id, bool $active): void
    {
        Database::update('custom_fields', ['active' => $active ? 1 : 0], $id);
    }

    /**
     * The columns a form may set.
     *
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    private static function writable(array $fields): array
    {
        $type = (string) ($fields['data_type'] ?? 'string');

        if (!array_key_exists($type, self::DATA_TYPES)) {
            throw new RuntimeException('"' . $type . '" is not a field type InvoGrid knows about.');
        }

        $label = trim((string) ($fields['label'] ?? ''));

        if ($label === '') {
            throw new RuntimeException('A field needs a label — it is what a reviewer sees.');
        }

        $options = $type === 'select' ? self::selectOptions($fields['select_options'] ?? null) : null;

        if ($type === 'select' && $options === null) {
            throw new RuntimeException('A "one of a list" field needs at least one choice.');
        }

        $paperlessId = $fields['paperless_field_id'] ?? null;
        $hint        = trim((string) ($fields['prompt_hint'] ?? ''));

        return [
            'label'              => mb_substr($label, 0, 120),
            'data_type'          => $type,
            'select_options'     => $options === null ? null : json_encode($options, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'paperless_field_id' => $paperlessId === null || $paperlessId === '' ? null : (int) $paperlessId,
            'prompt_hint'        => $hint === '' ? null : $hint,
            'active'             => !empty($fields['active']) ? 1 : 0,
            'source'             => (string) ($fields['source'] ?? self::EXTRACTED),
        ];
    }

    /**
     * Select choices, in the shape Paperless holds them.
     *
     * `[{id, label}]` — the same structure Paperless returns and accepts, so a
     * value chosen here writes back without translation. Accepts either that
     * structure (importing an existing Paperless field) or one label per line
     * (somebody typing a new list).
     *
     * @return array<int,array{id:string,label:string}>|null
     */
    public static function selectOptions(mixed $given): ?array
    {
        $options = [];

        if (is_string($given)) {
            foreach (preg_split('/\R/', $given) ?: [] as $line) {
                $label = trim($line);

                if ($label !== '') {
                    $options[] = ['id' => self::optionId($label), 'label' => $label];
                }
            }
        } elseif (is_array($given)) {
            foreach ($given as $option) {
                if (is_array($option) && trim((string) ($option['label'] ?? '')) !== '') {
                    $label     = trim((string) $option['label']);
                    $options[] = [
                        'id'    => trim((string) ($option['id'] ?? '')) === '' ? self::optionId($label) : (string) $option['id'],
                        'label' => $label,
                    ];
                } elseif (is_string($option) && trim($option) !== '') {
                    $options[] = ['id' => self::optionId(trim($option)), 'label' => trim($option)];
                }
            }
        }

        return $options === [] ? null : $options;
    }

    /** The stored options as one label per line, for the form. */
    public static function optionLines(?string $json): string
    {
        $decoded = $json === null || $json === '' ? [] : json_decode($json, true);

        if (!is_array($decoded)) {
            return '';
        }

        $labels = [];

        foreach ($decoded as $option) {
            if (is_array($option) && isset($option['label'])) {
                $labels[] = (string) $option['label'];
            } elseif (is_string($option)) {
                $labels[] = $option;
            }
        }

        return implode("\n", $labels);
    }

    /**
     * A key from whatever somebody typed.
     *
     * Lower case, underscores, nothing else — it is a JSON object key in
     * `custom_field_values` and a name the extraction prompt reads back, and
     * both are easier to reason about when it cannot contain a space.
     */
    public static function normaliseKey(string $given): string
    {
        $key = strtolower(trim($given));
        $key = (string) preg_replace('/[^a-z0-9]+/', '_', $key);

        return trim($key, '_');
    }

    private static function optionId(string $label): string
    {
        return substr(self::normaliseKey($label), 0, 32) ?: substr(md5($label), 0, 8);
    }

    private static function nextSortOrder(): int
    {
        return ((int) Database::scalar('SELECT COALESCE(MAX(sort_order), 0) FROM custom_fields')) + 10;
    }

    /** @return array<string,mixed>|null */
    public static function find(string $fieldKey): ?array
    {
        return Database::selectOne('SELECT * FROM custom_fields WHERE field_key = ?', [$fieldKey]);
    }

    /**
     * The fields shaped for injection into a prompt.
     *
     * `hint` is what actually does the work — it is the operator's description
     * of where the value sits on the page and what it looks like, and it is
     * passed to the model verbatim.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forPrompt(): array
    {
        $rows = [];

        foreach (self::extracted() as $field) {
            $entry = [
                'key'   => (string) $field['field_key'],
                'label' => (string) $field['label'],
                'type'  => (string) $field['data_type'],
            ];

            if ($field['prompt_hint'] !== null && trim((string) $field['prompt_hint']) !== '') {
                $entry['hint'] = trim((string) $field['prompt_hint']);
            }

            // Only a select field constrains its answer, and only then is the
            // list worth the tokens.
            if ($field['data_type'] === 'select' && is_string($field['select_options'] ?? null)) {
                $options = json_decode((string) $field['select_options'], true);

                if (is_array($options) && $options !== []) {
                    $entry['options'] = $options;
                }
            }

            $rows[] = $entry;
        }

        return $rows;
    }

    /**
     * Coerce an extracted value to the field's declared type.
     *
     * Returns null for anything that cannot be made to fit — a date that is not
     * a date, an integer that is a sentence. Null means "not found", which is a
     * legitimate answer everywhere in this pipeline; storing a malformed value
     * would push the problem into the Paperless write-back, where it fails much
     * less legibly.
     */
    public static function coerce(string $dataType, mixed $value): mixed
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        return match ($dataType) {
            'integer'  => is_numeric($value) ? (int) $value : null,
            'float',
            'monetary' => is_numeric($value) ? (float) $value : null,
            'boolean'  => is_bool($value)
                ? $value
                : (in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true)
                    ? true
                    : (in_array(strtolower((string) $value), ['0', 'false', 'no', 'off'], true) ? false : null)),
            'date'     => self::asDate($value),
            'url'      => filter_var((string) $value, FILTER_VALIDATE_URL) === false ? null : (string) $value,
            default    => is_scalar($value) ? (string) $value : null,
        };
    }

    private static function asDate(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }
}
