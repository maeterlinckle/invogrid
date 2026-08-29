<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Models\AuditLog;
use App\Services\Branding;

/**
 * The logo: serving it, and replacing it.
 *
 * Two very different jobs in one class because they are two halves of one
 * thing, but note which is which — **`show()` is the only open method here**
 * and everything else is behind `can:settings.manage`. The route table is where
 * that is enforced, and `tests/smoke.php` fails if `/branding/{variant}` is not
 * on its short list of deliberately open routes.
 *
 * The file lives under /storage, outside the document root, so it cannot be
 * fetched directly — `show()` reads it and sends it with the right type. The
 * sign-in page needs it, so that route is deliberately open to a signed-out
 * visitor: a logo is not a secret, and gating it would mean an unbranded login.
 */
final class BrandingController extends Controller
{
    // --- Open ---------------------------------------------------------------

    public function show(string $variant): void
    {
        $path = Branding::resolve($variant);

        if ($path === null) {
            $this->notFound('No logo has been uploaded.');
        }

        $absolute = Branding::absolutePath($path);

        if ($absolute === null) {
            $this->notFound('No logo has been uploaded.');
        }

        $mime = Branding::mime($variant);
        if (!in_array($mime, Branding::mimes(), true)) {
            $mime = 'image/png';
        }

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($absolute));

        // The URL carries a fingerprint of the stored path, so a replaced logo
        // arrives under a new address and this can be cached hard.
        header('Cache-Control: public, max-age=604800');
        header('X-Content-Type-Options: nosniff');

        if (Request::method() !== 'HEAD') {
            readfile($absolute);
        }

        exit;
    }

    // --- Admin --------------------------------------------------------------

    public function index(): void
    {
        $this->view('admin/branding', [
            'pageTitle'  => 'Branding',
            'light'      => Branding::slot('light'),
            'dark'       => Branding::slot('dark'),
            'maxBytes'   => Branding::maxBytes(),
            'extensions' => Branding::extensions(),
        ]);
    }

    /**
     * Take one or both variants off the form.
     *
     * Independently: somebody replacing only the dark logo leaves the light
     * file input empty, and that must not read as an error or wipe the light
     * logo. Errors are collected rather than thrown on the first one, so a form
     * with one good file and one bad saves the good one and says what was wrong
     * with the other.
     */
    public function upload(): void
    {
        $saved  = [];
        $failed = [];

        foreach (Branding::VARIANTS as $variant) {
            $result = Branding::acceptUpload($variant);

            if (!$result['provided']) {
                continue;
            }

            if ($result['error'] === null) {
                $saved[] = $variant;
            } else {
                $failed[] = $result['error'];
            }
        }

        foreach ($failed as $problem) {
            Flash::error($problem);
        }

        if ($saved !== []) {
            AuditLog::record('branding.updated', null, sprintf(
                '%s uploaded the %s mode logo.',
                Auth::displayName(),
                implode(' and ', $saved)
            ));

            Flash::success(count($saved) === 2
                ? 'Both logos updated. They appear in the header, on the sign-in page and on printed summaries.'
                : 'The ' . $saved[0] . ' mode logo was updated.');
        } elseif ($failed === []) {
            Flash::info('No file was chosen, so nothing changed.');
        }

        Response::redirect('/admin/branding');
    }

    public function remove(string $variant): void
    {
        if (!in_array($variant, Branding::VARIANTS, true)) {
            $this->notFound('There is no such logo variant.');
        }

        if (Branding::path($variant) === null) {
            Flash::info('There was no ' . $variant . ' mode logo to remove.');
            Response::redirect('/admin/branding');
        }

        Branding::remove($variant);

        AuditLog::record('branding.removed', null, sprintf(
            '%s removed the %s mode logo.',
            Auth::displayName(),
            $variant
        ));

        Flash::success(Branding::hasAny()
            ? 'Removed. The other variant now stands in for both — one logo is better than none.'
            : 'Removed. The header falls back to the ' . config('app.mark', 'IG') . ' monogram.');

        Response::redirect('/admin/branding');
    }
}
