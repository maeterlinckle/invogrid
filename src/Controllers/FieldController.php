<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Models\AuditLog;
use App\Models\CustomField;
use App\Services\PaperlessFields;
use Throwable;

/**
 * The custom fields the pipeline reads off a page, managed without a deploy.
 *
 * A field here is a row plus a sentence of prompt hint, and the extraction
 * stage picks it up on the very next document — `CustomField::forPrompt()` is
 * read fresh each run and injected as `{{ customFields }}`. Nothing has to be
 * listed in a prompt by hand.
 *
 * Two rules the screen exists to enforce:
 *
 *  - **A field key never changes.** Values already read off documents are
 *    stored under it in `extractions.custom_field_values`, and renaming one
 *    orphans every one of them. The form shows the key as read-only once the
 *    field exists.
 *  - **A field is deactivated, never deleted**, for the same reason: last
 *    month's extraction still has to resolve the values it stored.
 */
final class FieldController extends Controller
{
    public function index(): void
    {
        $paperless = PaperlessFields::available();

        $this->view('admin/fields', [
            'pageTitle'       => 'Custom fields',
            'fields'          => CustomField::all(),
            'paperlessFields' => $this->byId($paperless['fields']),
            'paperlessError'  => $paperless['error'],
        ]);
    }

    /** The form, for a new field or an existing one. */
    public function edit(?string $id = null): void
    {
        $field = $id === null ? null : CustomField::findById((int) $id);

        if ($id !== null && $field === null) {
            $this->notFound('No such custom field.');
        }

        $paperless = PaperlessFields::available();

        $this->view('admin/field-form', [
            'pageTitle'       => $field === null ? 'Add a custom field' : 'Edit ' . $field['label'],
            'field'           => $field,
            'paperlessFields' => $paperless['fields'],
            'paperlessError'  => $paperless['error'],
            'alreadyPaired'   => PaperlessFields::alreadyPaired($field === null ? null : (int) $field['id']),
        ]);
    }

    /**
     * Create or update, and — if asked — make the Paperless field too.
     *
     * The Paperless side is done **first** when it is being created, because a
     * failure there should leave nothing behind. The other order would give an
     * InvoGrid field that silently never writes back, which looks like it is
     * working right up until somebody goes looking for the value in Paperless.
     */
    public function save(?string $id = null): void
    {
        $field = $id === null ? null : CustomField::findById((int) $id);

        if ($id !== null && $field === null) {
            $this->notFound('No such custom field.');
        }

        $input = [
            'field_key'          => (string) Request::post('field_key', ''),
            'label'              => (string) Request::post('label', ''),
            'data_type'          => (string) Request::post('data_type', 'string'),
            'select_options'     => (string) Request::post('select_options', ''),
            'prompt_hint'        => (string) Request::post('prompt_hint', ''),
            'paperless_field_id' => Request::post('paperless_field_id', ''),
            'active'             => Request::boolean('active'),
            'source'             => $field === null ? CustomField::EXTRACTED : (string) $field['source'],
        ];

        $back = '/admin/fields/' . ($field === null ? 'new' : (int) $field['id']);

        try {
            if (Request::boolean('create_in_paperless')) {
                $input['paperless_field_id'] = PaperlessFields::create(
                    trim($input['label']) === '' ? $input['field_key'] : $input['label'],
                    $input['data_type'],
                    CustomField::selectOptions($input['select_options'])
                );

                AuditLog::record('paperless.custom_field_created', null, sprintf(
                    '%s created Paperless custom field %d "%s" (%s).',
                    Auth::displayName(),
                    $input['paperless_field_id'],
                    $input['label'],
                    $input['data_type']
                ));
            }

            if ($field === null) {
                $newId = CustomField::create($input);

                AuditLog::record('fields.created', null, sprintf(
                    '%s added the custom field "%s" (%s, %s)%s.',
                    Auth::displayName(),
                    $input['field_key'],
                    $input['label'],
                    $input['data_type'],
                    $input['paperless_field_id'] === '' || $input['paperless_field_id'] === null
                        ? ', not paired with Paperless'
                        : ', paired with Paperless field ' . $input['paperless_field_id']
                ));

                Flash::success('Added "' . $input['label'] . '". The next document extracted will be asked about it.');
                Response::redirect('/admin/fields');
            }

            $changed = CustomField::update((int) $field['id'], $input);
        } catch (Throwable $e) {
            Flash::error($e->getMessage());
            Flash::old(Request::all());
            Response::redirect($back);
        }

        if ($changed === []) {
            Flash::info('Nothing was changed.');
            Response::redirect('/admin/fields');
        }

        // The hint is the part that changes what a model is asked, so it is
        // named rather than lumped in with the rest.
        AuditLog::record('fields.updated', null, sprintf(
            '%s changed %s on the custom field "%s".',
            Auth::displayName(),
            implode(', ', array_map(
                static fn (string $c): string => str_replace('_', ' ', $c),
                $changed
            )),
            $field['field_key']
        ));

        Flash::success(in_array('prompt_hint', $changed, true)
            ? 'Saved. The new hint goes to the model on the next document extracted.'
            : 'Saved.');

        Response::redirect('/admin/fields');
    }

    /** Take a field out of use, or put it back. */
    public function toggle(string $id): void
    {
        $field = CustomField::findById((int) $id);

        if ($field === null) {
            $this->notFound('No such custom field.');
        }

        $active = (int) $field['active'] !== 1;

        CustomField::setActive((int) $field['id'], $active);

        AuditLog::record($active ? 'fields.activated' : 'fields.deactivated', null, sprintf(
            '%s %s the custom field "%s".',
            Auth::displayName(),
            $active ? 'brought back' : 'took out of use',
            $field['field_key']
        ));

        Flash::success($active
            ? '"' . $field['label'] . '" is in use again, from the next document extracted.'
            : '"' . $field['label'] . '" is out of use. Values already read off documents are kept.');

        Response::redirect('/admin/fields');
    }

    /**
     * @param array<int,array<string,mixed>> $fields
     * @return array<int,array<string,mixed>>
     */
    private function byId(array $fields): array
    {
        $byId = [];

        foreach ($fields as $field) {
            $byId[(int) ($field['id'] ?? 0)] = $field;
        }

        return $byId;
    }
}
