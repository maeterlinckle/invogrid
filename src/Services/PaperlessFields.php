<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CustomField;
use RuntimeException;
use Throwable;

/**
 * The bridge between an InvoGrid custom field and a Paperless one.
 *
 * Two directions, and the point of both is that setting up a new field should
 * not mean opening the Paperless admin in another tab, creating something
 * there, coming back and typing its id in:
 *
 *  - **pick** an existing Paperless field, importing its type and — for a
 *    select — its choices, so the two ends agree from the start;
 *  - **create** one, from what has already been typed into the InvoGrid form.
 *
 * Paperless's own data types are mirrored by `CustomField::DATA_TYPES` with one
 * exception: `longtext` is InvoGrid's, and becomes a Paperless `string`. That
 * conversion lives here rather than in the model, because it is a fact about
 * Paperless.
 *
 * Select choices are `[{id, label}]` at both ends by design. Storing them in
 * Paperless's own shape means a value written back needs no translation, and a
 * translation table between two lists of the same thing is a place for them to
 * drift apart.
 */
final class PaperlessFields
{
    /** InvoGrid's types that Paperless does not have, and what they become. */
    private const SUBSTITUTIONS = ['longtext' => 'string'];

    /** Can this screen talk to Paperless at all? */
    public static function isAvailable(): bool
    {
        return PaperlessClient::isConfigured();
    }

    /**
     * Every custom field Paperless holds, for the picker.
     *
     * Returns an empty list rather than throwing when Paperless is unreachable:
     * the fields screen is still usable without it — a field can be defined and
     * paired up later — and a configuration screen that will not render because
     * a remote service is down is worse than one that says so.
     *
     * @return array{fields:array<int,array<string,mixed>>,error:?string}
     */
    public static function available(): array
    {
        if (!self::isAvailable()) {
            return ['fields' => [], 'error' => 'Paperless is not configured, so its fields cannot be listed.'];
        }

        try {
            $fields = (new PaperlessClient())->customFields();
        } catch (Throwable $e) {
            return ['fields' => [], 'error' => 'Paperless could not be reached: ' . $e->getMessage()];
        }

        usort($fields, static fn (array $a, array $b): int => strcasecmp(
            (string) ($a['name'] ?? ''),
            (string) ($b['name'] ?? '')
        ));

        return ['fields' => $fields, 'error' => null];
    }

    /**
     * One Paperless field, shaped for the InvoGrid form.
     *
     * @return array{paperless_field_id:int,label:string,data_type:string,select_options:?array<int,array{id:string,label:string}>}
     */
    public static function describe(int $paperlessFieldId): array
    {
        foreach (self::available()['fields'] as $field) {
            if ((int) ($field['id'] ?? 0) !== $paperlessFieldId) {
                continue;
            }

            $type = (string) ($field['data_type'] ?? 'string');

            return [
                'paperless_field_id' => $paperlessFieldId,
                'label'              => (string) ($field['name'] ?? ''),

                // A type Paperless has and InvoGrid does not would otherwise be
                // rejected by the model with a message about InvoGrid's list,
                // which is not the reader's problem to solve.
                'data_type'          => array_key_exists($type, CustomField::DATA_TYPES) ? $type : 'string',
                'select_options'     => CustomField::selectOptions(
                    $field['extra_data']['select_options'] ?? null
                ),
            ];
        }

        throw new RuntimeException('Paperless has no custom field ' . $paperlessFieldId . '.');
    }

    /**
     * Create the Paperless field to match what is on the form.
     *
     * Returns its id, for storing against the InvoGrid field. Throws rather
     * than swallowing: the person pressing this asked for a field to exist in
     * Paperless, and quietly not creating one would leave them with an InvoGrid
     * field that silently never writes back.
     *
     * @param array<int,array{id:string,label:string}>|null $selectOptions
     */
    public static function create(string $name, string $dataType, ?array $selectOptions = null): int
    {
        if (!self::isAvailable()) {
            throw new RuntimeException('Paperless is not configured, so a field cannot be created there.');
        }

        $name = trim($name);

        if ($name === '') {
            throw new RuntimeException('A Paperless field needs a name.');
        }

        $paperlessType = self::SUBSTITUTIONS[$dataType] ?? $dataType;
        $extra         = [];

        if ($paperlessType === 'select') {
            if ($selectOptions === null || $selectOptions === []) {
                throw new RuntimeException('A select field needs at least one choice before Paperless will take it.');
            }

            $extra['extra_data'] = ['select_options' => array_values($selectOptions)];
        }

        $created = (new PaperlessClient())->createCustomField($name, $paperlessType, $extra);
        $id      = (int) ($created['id'] ?? 0);

        if ($id === 0) {
            throw new RuntimeException('Paperless created the field but did not say what its id is.');
        }

        return $id;
    }

    /**
     * Which of these Paperless fields is already paired with an InvoGrid one.
     *
     * Shown in the picker so somebody does not pair two InvoGrid fields to the
     * same Paperless one — the write-back merges by field id, so the second
     * would overwrite the first on every document.
     *
     * @return array<int,string> Paperless field id => the InvoGrid label using it
     */
    public static function alreadyPaired(?int $exceptFieldId = null): array
    {
        $paired = [];

        foreach (CustomField::all() as $field) {
            if ($field['paperless_field_id'] === null || (int) $field['id'] === $exceptFieldId) {
                continue;
            }

            $paired[(int) $field['paperless_field_id']] = (string) $field['label'];
        }

        return $paired;
    }
}
