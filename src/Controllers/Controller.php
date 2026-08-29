<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Core\View;

abstract class Controller
{
    /**
     * @param array<string,mixed> $data
     * @param string $layout 'layouts/auth' for the signed-out shell.
     */
    protected function view(string $template, array $data = [], string $layout = 'layouts/app'): void
    {
        View::render($template, $data, $layout);
    }

    /**
     * Validate the request, or redirect back with the errors and the old input.
     *
     * @param array<string,string> $rules
     * @param array<string,string> $labels
     * @return array<string,mixed> The validated input
     */
    protected function validate(array $rules, array $labels = [], ?string $redirectTo = null): array
    {
        $input     = Request::all();
        $validator = Validator::make($input, $rules, $labels);

        if ($validator->failed()) {
            Flash::errors($validator->errors());
            Flash::old($input);
            Flash::error('Please check the highlighted fields and try again.');

            if ($redirectTo !== null) {
                Response::redirect($redirectTo);
            }

            Response::back();
        }

        // Normalise every scalar field to a trimmed string, with a field that
        // was not submitted at all becoming ''. That matches how a browser
        // posts an empty text input, so callers can rely on a single `!== ''`
        // test rather than handling null separately.
        $validated = [];
        foreach (array_keys($rules) as $field) {
            $value = $input[$field] ?? '';

            if (is_array($value)) {
                $validated[$field] = $value;
                continue;
            }

            $validated[$field] = is_string($value) ? trim($value) : (string) $value;
        }

        return $validated;
    }

    /**
     * Reject a request with field errors the generic rules cannot express —
     * cross-field checks, uniqueness with a tailored message, and so on.
     *
     * @param array<string,string> $errors
     */
    protected function failValidation(array $errors, string $redirectTo): never
    {
        Flash::errors($errors);
        Flash::old(Request::all());
        Flash::error(count($errors) === 1 ? reset($errors) : 'Please check the highlighted fields and try again.');

        Response::redirect($redirectTo);
    }

    /** Render the standard 404 page and stop. */
    protected function notFound(string $message = 'The record you were looking for does not exist, or has been deleted.'): never
    {
        View::renderError(404, 'Not found', $message);
        exit;
    }
}
