<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class View
{
    /** @var array<string,mixed> */
    private static array $shared = [];

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    /**
     * Render a template inside the main layout.
     *
     * @param array<string,mixed> $data
     */
    public static function render(string $template, array $data = [], string $layout = 'layouts/app'): void
    {
        echo self::capture($template, $data, $layout);
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function capture(string $template, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $data = array_merge(self::$shared, [
            'errors' => Flash::takeErrors(),
            'old'    => Flash::takeOld(),
        ], $data);

        $content = self::renderFile($template, $data);

        if ($layout === null) {
            return $content;
        }

        $data['content'] = $content;

        return self::renderFile($layout, $data);
    }

    /**
     * Render a template without a layout (partials, emails, fragments).
     *
     * @param array<string,mixed> $data
     */
    public static function partial(string $template, array $data = []): string
    {
        return self::renderFile($template, array_merge(self::$shared, $data));
    }

    /** @param array<string,mixed> $data */
    private static function renderFile(string $template, array $data): string
    {
        $path = Config::get('app.root') . '/templates/' . ltrim($template, '/') . '.php';

        if (!is_file($path)) {
            throw new RuntimeException('Template not found: ' . $template);
        }

        extract($data, EXTR_SKIP);

        ob_start();
        require $path;

        return (string) ob_get_clean();
    }

    public static function renderError(int $status, string $title, string $message): void
    {
        http_response_code($status);

        try {
            // Signed-out visitors get the slim layout, which has no navigation.
            $layout = Auth::check() ? 'layouts/app' : 'layouts/auth';

            echo self::capture('errors/error', [
                'status'    => $status,
                'title'     => $title,
                'message'   => $message,
                'pageTitle' => $title,
            ], $layout);
        } catch (\Throwable) {
            echo '<!doctype html><meta charset="utf-8"><title>' . htmlspecialchars($title) . '</title>'
                . '<h1>' . htmlspecialchars($title) . '</h1><p>' . htmlspecialchars($message) . '</p>';
        }
    }
}
