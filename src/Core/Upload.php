<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Files arriving from a browser.
 *
 * The only uploads this application takes are the two logo variants, so this is
 * deliberately small — but an upload endpoint is the one place where a
 * privileged user can put a file of their choosing on the server, so the
 * checking is not proportional to the feature's size.
 *
 * Three independent checks, and all three have to pass:
 *
 *  1. **The extension**, against a whitelist. Cheap, and it is what the web
 *     server will decide to execute on if it is ever misconfigured to serve the
 *     storage directory.
 *  2. **The real content type**, sniffed with finfo rather than believed from
 *     the browser's `Content-Type`, which is whatever the client felt like
 *     sending.
 *  3. **That it actually decodes as an image**, via `getimagesize()`. A PHP
 *     script with a PNG header passes a naive mime sniff; it does not survive
 *     being parsed as an image.
 *
 * SVG is absent from every whitelist on purpose: an SVG is a document that can
 * carry script, so serving one from this origin would hand an administrator an
 * XSS vector against everybody else. A PNG at twice the display height is
 * indistinguishable in a 36-pixel-tall header.
 */
final class Upload
{
    /**
     * The files posted under one field name, normalised to a list.
     *
     * PHP shapes `$_FILES` differently for `name` and `name[]`, which is a
     * long-standing trap: code written against one silently misreads the other.
     *
     * @return array<int,array{name:string,tmp_name:string,error:int,size:int}>
     */
    public static function files(string $field): array
    {
        $file = $_FILES[$field] ?? null;

        if (!is_array($file)) {
            return [];
        }

        if (!is_array($file['name'])) {
            return (int) $file['error'] === UPLOAD_ERR_NO_FILE ? [] : [[
                'name'     => (string) $file['name'],
                'tmp_name' => (string) $file['tmp_name'],
                'error'    => (int) $file['error'],
                'size'     => (int) $file['size'],
            ]];
        }

        $files = [];

        foreach (array_keys($file['name']) as $i) {
            if ((int) $file['error'][$i] === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $files[] = [
                'name'     => (string) $file['name'][$i],
                'tmp_name' => (string) $file['tmp_name'][$i],
                'error'    => (int) $file['error'][$i],
                'size'     => (int) $file['size'][$i],
            ];
        }

        return $files;
    }

    /**
     * Check one uploaded file. Returns a sentence to show the person, or null
     * when it is acceptable.
     *
     * @param array{name:string,tmp_name:string,error:int,size:int} $file
     * @param array<int,string> $allowedMimes
     * @param array<int,string> $allowedExtensions
     */
    public static function validate(
        array $file,
        array $allowedMimes,
        array $allowedExtensions,
        int $maxBytes,
    ): ?string {
        $name = self::displayName($file['name']);

        if ($file['error'] !== UPLOAD_ERR_OK) {
            return $name . ': ' . self::errorMessage($file['error']);
        }

        // Without this, a crafted request could name any path on the server and
        // have it copied into the uploads directory and served back.
        if (!is_uploaded_file($file['tmp_name'])) {
            return $name . ': the upload could not be verified.';
        }

        if ($file['size'] <= 0) {
            return $name . ': the file is empty.';
        }

        if ($file['size'] > $maxBytes) {
            return sprintf(
                '%s is %s, and the limit is %s.',
                $name,
                self::formatBytes($file['size']),
                self::formatBytes($maxBytes)
            );
        }

        $extension = strtolower((string) pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($extension, $allowedExtensions, true)) {
            return sprintf(
                '%s: %s files are not accepted. Use %s.',
                $name,
                $extension === '' ? 'unnamed' : strtoupper($extension),
                self::readableList(array_map('strtoupper', $allowedExtensions))
            );
        }

        // The contents, not the browser-supplied Content-Type.
        $detected = self::detectMime($file['tmp_name']);

        if ($detected === null || !in_array($detected, $allowedMimes, true)) {
            return sprintf('%s does not look like a real %s file inside.', $name, strtoupper($extension));
        }

        // And it has to survive being parsed as an image. This is the check
        // that a script wearing a PNG header fails.
        $size = @getimagesize($file['tmp_name']);

        if ($size === false || (int) $size[0] < 1 || (int) $size[1] < 1) {
            return $name . ': that file could not be read as an image.';
        }

        return null;
    }

    /**
     * Move an uploaded file into place, returning its path relative to the
     * uploads root — which is what goes in the database.
     *
     * The name is generated, never taken from the client. A filename is
     * attacker-controlled text and there is no reason for it to survive.
     *
     * @param array{name:string,tmp_name:string} $file
     */
    public static function store(array $file, string $relativeDirectory, string $extension): string
    {
        $root      = (string) Config::get('storage.uploads');
        $directory = $root . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, trim($relativeDirectory, '/'));

        self::ensureDirectory($directory);

        $filename = date('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '.' . strtolower($extension);
        $target   = $directory . DIRECTORY_SEPARATOR . $filename;

        if (!move_uploaded_file($file['tmp_name'], $target)) {
            throw new RuntimeException(
                'Could not save the file. Check the permissions on the storage directory.'
            );
        }

        @chmod($target, 0640);

        return trim($relativeDirectory, '/') . '/' . $filename;
    }

    /**
     * A stored relative path resolved to a real one, or null.
     *
     * Refuses anything that climbs out of the uploads directory, and resolves
     * symlinks before comparing — a link inside the directory could otherwise
     * point at /etc/passwd.
     */
    public static function absolutePath(string $relativePath): ?string
    {
        $relative = str_replace(chr(92), '/', trim($relativePath));

        if ($relative === '' || str_contains($relative, '..')) {
            return null;
        }

        $base = rtrim((string) Config::get('storage.uploads'), '/' . chr(92));
        $full = $base . DIRECTORY_SEPARATOR . ltrim($relative, '/');

        if (!is_file($full)) {
            return null;
        }

        $real     = realpath($full);
        $realBase = realpath($base);

        if ($real === false || $realBase === false || !str_starts_with($real, $realBase)) {
            return null;
        }

        return $real;
    }

    public static function delete(string $relativePath): void
    {
        $absolute = self::absolutePath($relativePath);

        if ($absolute !== null) {
            @unlink($absolute);
        }
    }

    public static function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create ' . $directory . '.');
        }
    }

    public static function detectMime(string $path): ?string
    {
        if (!function_exists('finfo_open')) {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return null;
        }

        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);

        return is_string($mime) && $mime !== '' ? $mime : null;
    }

    /**
     * The pixel dimensions of a stored image, for a screen that wants to say
     * "that is 40 pixels tall and will look soft on a retina display".
     *
     * @return array{width:int,height:int}|null
     */
    public static function dimensions(string $relativePath): ?array
    {
        $absolute = self::absolutePath($relativePath);

        if ($absolute === null) {
            return null;
        }

        $size = @getimagesize($absolute);

        return $size === false ? null : ['width' => (int) $size[0], 'height' => (int) $size[1]];
    }

    /** A client-supplied filename, made safe to print back at them. */
    public static function displayName(string $name): string
    {
        $name = basename(str_replace(chr(92), '/', $name));

        return $name === '' ? 'The file' : mb_substr($name, 0, 80);
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1) . ' MB';
        }

        return max(1, (int) round($bytes / 1024)) . ' KB';
    }

    /** @param array<int,string> $items */
    private static function readableList(array $items): string
    {
        if (count($items) < 2) {
            return implode('', $items);
        }

        $last = array_pop($items);

        return implode(', ', $items) . ' or ' . $last;
    }

    private static function errorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'the file is larger than the server allows.',
            UPLOAD_ERR_PARTIAL                        => 'the upload was interrupted — please try again.',
            UPLOAD_ERR_NO_FILE                        => 'no file was received.',
            UPLOAD_ERR_NO_TMP_DIR                     => 'the server has no temporary directory configured.',
            UPLOAD_ERR_CANT_WRITE                     => 'the server could not write the file to disk.',
            UPLOAD_ERR_EXTENSION                      => 'a server extension blocked the upload.',
            default                                   => 'the upload failed.',
        };
    }
}
