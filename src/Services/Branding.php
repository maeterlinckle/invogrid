<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Upload;
use App\Models\Setting;

/**
 * The uploaded logo, and everything that needs to reach it.
 *
 * One place, because the same two files have to appear in contexts that have
 * nothing else in common: the site header, the sign-in page (before anyone has
 * signed in) and printed output (light variant, because paper is white).
 *
 * Both variants are independently optional, and where the one for the current
 * theme is missing the other stands in — one logo is better than none. With
 * neither, the header falls back to the IG monogram, which is what a fresh
 * install shows.
 *
 * The files are served by BrandingController from outside the document root
 * rather than linked directly, so the storage directory stays unreachable.
 */
final class Branding
{
    public const VARIANTS = ['light', 'dark'];

    /** Where uploads land, under the storage path. */
    private const DIRECTORY = 'branding';

    /**
     * Raster only, deliberately. An SVG is a document that can carry script, so
     * serving one from this origin would hand an administrator an XSS vector.
     * A PNG at twice the display height looks identical in the header.
     */
    public static function mimes(): array
    {
        return (array) Config::get('uploads.logo_mimes', ['image/png']);
    }

    /** The stored relative path for a variant, or null when none is set. */
    public static function path(string $variant): ?string
    {
        if (!in_array($variant, self::VARIANTS, true)) {
            return null;
        }

        $path = Setting::get('logo_' . $variant . '_path');

        if ($path === null || $path === '') {
            return null;
        }

        // A setting can outlive the file it names — a restore from a database
        // dump without the uploads directory, say. Treat a missing file as no
        // logo rather than rendering a broken image on every page.
        return self::absolutePath($path) === null ? null : $path;
    }

    /** The variant actually used for a theme, falling back to the other. */
    public static function resolve(string $variant): ?string
    {
        $other = $variant === 'light' ? 'dark' : 'light';

        return self::path($variant) ?? self::path($other);
    }

    public static function mime(string $variant): string
    {
        return (string) (Setting::get('logo_' . $variant . '_mime') ?? 'image/png');
    }

    public static function hasAny(): bool
    {
        return self::path('light') !== null || self::path('dark') !== null;
    }

    /**
     * A URL for the header, carrying a fingerprint of the stored path so a
     * replaced logo is not served from a month-old cache.
     */
    public static function url(string $variant): ?string
    {
        $path = self::path($variant);

        if ($path === null) {
            return null;
        }

        return url('/branding/' . $variant) . '?v=' . substr(hash('sha256', $path), 0, 10);
    }

    /**
     * Turn a stored relative path into a real one, refusing anything that tries
     * to climb out of the uploads directory.
     */
    public static function absolutePath(string $relative): ?string
    {
        $relative = str_replace(chr(92), '/', trim($relative));

        if ($relative === '' || str_contains($relative, '..')) {
            return null;
        }

        $base = rtrim((string) Config::get('storage.uploads'), '/' . chr(92));
        $full = $base . DIRECTORY_SEPARATOR . ltrim($relative, '/');

        if (!is_file($full)) {
            return null;
        }

        // Resolve both sides before comparing: a symlink inside the uploads
        // directory could otherwise point anywhere on the disk.
        $real     = realpath($full);
        $realBase = realpath($base);

        if ($real === false || $realBase === false || !str_starts_with($real, $realBase)) {
            return null;
        }

        return $real;
    }

    /** Where a variant's file should be written. */
    public static function directory(): string
    {
        return rtrim((string) Config::get('storage.uploads'), '/' . chr(92))
            . DIRECTORY_SEPARATOR . self::DIRECTORY;
    }

    /** @return array<int,string> */
    public static function extensions(): array
    {
        return (array) Config::get('uploads.logo_extensions', ['png']);
    }

    public static function maxBytes(): int
    {
        return (int) Config::get('uploads.max_logo_bytes', 2 * 1024 * 1024);
    }

    /**
     * Take one variant off a submitted form.
     *
     * Returns `provided` so a form carrying two file inputs, of which one was
     * left empty, does not report "no file chosen" for the half that was filled
     * in — the two variants are independent and are saved independently.
     *
     * @return array{provided:bool,error:string|null}
     */
    public static function acceptUpload(string $variant): array
    {
        $files = Upload::files('logo_' . $variant);

        if ($files === []) {
            return ['provided' => false, 'error' => null];
        }

        return ['provided' => true, 'error' => self::store($variant, $files[0])];
    }

    /**
     * Replace one variant. Returns an error sentence, or null on success.
     *
     * @param array{name:string,tmp_name:string,error:int,size:int} $file
     */
    private static function store(string $variant, array $file): ?string
    {
        if (!in_array($variant, self::VARIANTS, true)) {
            return 'Unknown logo variant.';
        }

        $problem = Upload::validate($file, self::mimes(), self::extensions(), self::maxBytes());

        if ($problem !== null) {
            return $problem;
        }

        $extension = strtolower((string) pathinfo($file['name'], PATHINFO_EXTENSION));

        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }

        $stored = Upload::store($file, self::DIRECTORY, $extension);

        // Read what is on the setting *before* overwriting it, and delete the
        // old file only once the new one is safely written. The other order
        // leaves the site with no logo at all if the write fails.
        $previous = Setting::get('logo_' . $variant . '_path');

        Setting::put('logo_' . $variant . '_path', $stored);
        Setting::put(
            'logo_' . $variant . '_mime',
            Upload::detectMime((string) Upload::absolutePath($stored)) ?? 'image/png'
        );

        if ($previous !== null && $previous !== '' && $previous !== $stored) {
            Upload::delete($previous);
        }

        return null;
    }

    /** Take a variant out of use, and off the disk. */
    public static function remove(string $variant): void
    {
        if (!in_array($variant, self::VARIANTS, true)) {
            return;
        }

        $previous = Setting::get('logo_' . $variant . '_path');

        Setting::put('logo_' . $variant . '_path', null);
        Setting::put('logo_' . $variant . '_mime', null);

        if ($previous !== null && $previous !== '') {
            Upload::delete($previous);
        }
    }

    /**
     * What is actually stored in one slot, for the admin screen.
     *
     * Uses `path()` rather than `resolve()` on purpose: this has to show an
     * empty slot as empty. `resolve()` stands the other variant in, which is
     * right for the header and would be a lie on a form.
     *
     * @return array{url:string|null,dimensions:array{width:int,height:int}|null,bytes:int|null}
     */
    public static function slot(string $variant): array
    {
        $path = self::path($variant);

        if ($path === null) {
            return ['url' => null, 'dimensions' => null, 'bytes' => null];
        }

        $absolute = self::absolutePath($path);

        return [
            'url'        => self::url($variant),
            'dimensions' => Upload::dimensions($path),
            'bytes'      => $absolute === null ? null : (int) filesize($absolute),
        ];
    }
}
