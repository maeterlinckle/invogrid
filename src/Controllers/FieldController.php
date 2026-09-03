<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Models\AuditLog;
use App\Models\CustomField;
use Throwable;

/**
 * The custom fields the pipeline reads off a page, managed without a deploy.
 *
 * A field here is a row plus a sentence of prompt hint, and the extraction
 * stage picks it up on the very next document — `CustomField::forPrompt()` is
 * read fresh each run and injected as `{{ customFields }}`. Nothing has to be
 * listed in a prompt by hand.
 *
 * This screen used to have a second half: each field could be paired with a
 * Paperless custom field, and the form could create one over the API. All of
 * that is gone with Paperless itself. What is left is the half that was always
 * doing the work — a field's key, label, data type and prompt hint are what
 * drive extraction, and they never needed anywhere to be written back to.
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
        $this->view('admin/fields', [
            'pageTitle' => 'Custom fields',
            'fields'    => CustomField::all(),
        ]);
    }

    /** The form, for a new field or an existing one. */
    public function edit(?string $id = null): void
    {
        $field = $id === null ? null : CustomField::findById((int) $id);

        if ($id !== null && $field === null) {
            $this->notFound('No such custom field.');
        }

        $this->view('admin/field-form', [
            'pageTitle' => $field === null ? 'Add a custom field' : 'Edit ' . $field['label'],
            'field'     => $field,
        ]);
    }

    /** Create or update. */
    public function save(?string $id = null): void
    {
        $field = $id === null ? null : CustomField::findById((int) $id);

        if ($id !== null && $field === null) {
            $this->notFound('No such custom field.');
        }

        $input = [
            'label'          => (string) Request::post('label', ''),
            'data_type'      => (string) Request::post('data_type', 'string'),
            'select_options' => (string) Request::post('select_options', ''),
            'prompt_hint'    => (string) Request::post('prompt_hint', ''),
            'active'         => Request::boolean('active'),

            // Never taken from the form. `source` says whether a value is read
            // off the page or produced by submitting, which is a fact about
            // where the field's data comes from rather than a preference — and
            // the two seeded `submission` fields are the only ones anything
            // knows how to fill in.
            'source'         => $field === null ? CustomField::EXTRACTED : (string) $field['source'],
        ];

        /*
         * The key is only ever read when creating.
         *
         * The edit form renders it as read-only *text*, so a browser posts
         * nothing for it — and passing `''` through to `CustomField::update()`
         * makes its "the key cannot change" guard fire on every save, because
         * an empty key does not equal the stored one. That guard is right; the
         * caller was wrong to hand it a value it had not been given. Omitting
         * the key entirely says what is true: this request does not propose one.
         */
        if ($field === null) {
            $input['field_key'] = (string) Request::post('field_key', '');
        }

        $back = '/admin/fields/' . ($field === null ? 'new' : (int) $field['id']);

        try {
            if ($field === null) {
                CustomField::create($input);

                AuditLog::record('fields.created', null, sprintf(
                    '%s added the custom field "%s" (%s, %s).',
                    Auth::displayName(),
                    $input['field_key'],
                    $input['label'],
                    $input['data_type']
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
}
