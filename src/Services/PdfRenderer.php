<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Models\Setting;
use RuntimeException;

/**
 * Renders each page of a PDF to an image, by shelling out to poppler's
 * `pdftoppm`.
 *
 * poppler rather than Imagick, decided at the start of the project and not to
 * be revisited here: one self-contained binary with no PHP ABI to match, versus
 * a DLL that has to agree with the exact PHP build. See docs/PROJECT-STATE.md.
 *
 * Two things about `pdftoppm` that will mislead a caller that does not know
 * them, and that this class exists partly to absorb:
 *
 *  1. **It writes warnings to stderr and still exits 0.** A perfectly good
 *     render routinely prints `Syntax Error: No display font for 'Symbol'`.
 *     Success is judged on the exit code *and* whether page files appeared —
 *     never on stderr being empty.
 *  2. **It appends the page number to the prefix**, zero-padded to the width of
 *     the page count: `out-1.png` for a 9-page document, `out-01.png` for a
 *     10-page one. So the output is globbed rather than named in advance.
 */
final class PdfRenderer
{
    /**
     * Resolution to render at.
     *
     * 200 DPI puts an A4 page at 1654 × 2339, which is inside the 2576-pixel
     * long edge the current vision models accept without downscaling — so the
     * detail we pay to send is detail the model actually sees. It is also
     * enough to read a biro annotation, which 150 DPI is marginal for.
     */
    public const DEFAULT_DPI = 200;

    /**
     * The long edge a page image may have.
     *
     * Above this the provider downsamples anyway, so rendering larger spends
     * upload bandwidth and wall-clock to no benefit. (Models older than the
     * current generation cap at 1568 instead; the setting can be lowered when
     * one of those is selected.)
     */
    public const DEFAULT_MAX_EDGE = 2576;

    /** Rendering a long document is slow; this is per document, not per page. */
    private const TIMEOUT_SECONDS = 300;

    /** A guard against a malformed PDF claiming thousands of pages. */
    public const MAX_PAGES = 100;

    /**
     * Render every page.
     *
     * @return array<int,array{page:int,path:string,relative:string,width:int,height:int,bytes:int}>
     */
    public function render(string $pdfPath, string $outputDirectory, string $relativePrefix): array
    {
        if (!is_file($pdfPath)) {
            throw new RuntimeException('No PDF at ' . $pdfPath);
        }

        if (!is_dir($outputDirectory) && !@mkdir($outputDirectory, 0775, true) && !is_dir($outputDirectory)) {
            throw new RuntimeException('Could not create ' . $outputDirectory);
        }

        // A retry must not leave last attempt's pages behind: a document that
        // rendered 8 pages and now renders 3 would otherwise appear to have 8.
        foreach (glob($outputDirectory . DIRECTORY_SEPARATOR . 'page-*') ?: [] as $stale) {
            @unlink($stale);
        }

        $dpi     = max(72, min(400, Setting::int('pdf_render_dpi', self::DEFAULT_DPI)));
        $maxEdge = max(512, min(4096, Setting::int('pdf_max_edge_px', self::DEFAULT_MAX_EDGE)));
        $format  = Setting::get('pdf_render_format', 'jpeg') === 'png' ? 'png' : 'jpeg';

        $prefix = $outputDirectory . DIRECTORY_SEPARATOR . 'page';

        $this->run($this->arguments($format, ['-r', (string) $dpi], $pdfPath, $prefix));

        $files = $this->renderedFiles($outputDirectory, $format);

        if ($files === []) {
            throw new RuntimeException(
                'pdftoppm produced no pages. The file may be encrypted, or not really a PDF.'
            );
        }

        if (count($files) > self::MAX_PAGES) {
            throw new RuntimeException(
                'This document has ' . count($files) . ' pages, which is past the '
                . self::MAX_PAGES . '-page limit. It is unlikely to be a purchase invoice.'
            );
        }

        $pages = [];

        foreach ($files as $index => $file) {
            $size = @getimagesize($file);

            if ($size === false) {
                throw new RuntimeException('pdftoppm wrote something that is not an image: ' . basename($file));
            }

            [$width, $height] = $size;

            // Only the pages that actually came out too big are re-rendered, and
            // only those. A landscape scan at 200 DPI can exceed the cap where a
            // portrait one does not, so this is per page rather than per
            // document.
            if (max($width, $height) > $maxEdge) {
                $this->run($this->arguments(
                    $format,
                    ['-scale-to', (string) $maxEdge, '-f', (string) ($index + 1), '-l', (string) ($index + 1)],
                    $pdfPath,
                    $prefix
                ));

                $size = @getimagesize($file);

                if ($size !== false) {
                    [$width, $height] = $size;
                }
            }

            $pages[] = [
                'page'     => $index + 1,
                'path'     => $file,
                'relative' => $relativePrefix . '/' . basename($file),
                'width'    => (int) $width,
                'height'   => (int) $height,
                'bytes'    => (int) filesize($file),
            ];
        }

        return $pages;
    }

    /**
     * How many pages a PDF has, without rendering it.
     *
     * Uses `pdfinfo` when it is beside `pdftoppm`, which it always is in a
     * poppler install. Returns null rather than throwing — this is a
     * convenience for the UI, and a document whose page count cannot be read is
     * not a document that should fail.
     */
    public function pageCount(string $pdfPath): ?int
    {
        $pdfinfo = $this->siblingBinary('pdfinfo');

        if ($pdfinfo === null) {
            return null;
        }

        try {
            $output = $this->run([$pdfinfo, $pdfPath]);
        } catch (RuntimeException) {
            return null;
        }

        if (preg_match('/^Pages:\s+(\d+)/m', $output['stdout'], $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    /** Is the renderer usable at all? For the Settings screen and db:check. */
    public static function isAvailable(): bool
    {
        return (new self())->binary() !== null;
    }

    /**
     * @param array<int,string> $options
     * @return array<int,string>
     */
    private function arguments(string $format, array $options, string $pdfPath, string $prefix): array
    {
        $binary = $this->binary();

        if ($binary === null) {
            throw new RuntimeException(
                'pdftoppm was not found. Install poppler-utils, or set PDFTOPPM_PATH in .env.'
            );
        }

        $arguments = [$binary, '-' . $format];

        if ($format === 'jpeg') {
            // 90 rather than the default 75: the annotations this pipeline is
            // reading are thin pen strokes, and JPEG ringing around a thin red
            // line on white is exactly the artefact that turns a 3 into an 8.
            $arguments[] = '-jpegopt';
            $arguments[] = 'quality=90';
        }

        foreach ($options as $option) {
            $arguments[] = $option;
        }

        $arguments[] = $pdfPath;
        $arguments[] = $prefix;

        return $arguments;
    }

    /**
     * The rendered pages, in page order.
     *
     * Sorted by the number in the filename rather than by string: `page-10.png`
     * sorts before `page-2.png` alphabetically, which would hand the model a
     * document with its pages shuffled.
     *
     * @return array<int,string>
     */
    private function renderedFiles(string $directory, string $format): array
    {
        $extension = $format === 'png' ? 'png' : 'jpg';
        $files     = glob($directory . DIRECTORY_SEPARATOR . 'page-*.' . $extension) ?: [];

        $numbered = [];

        foreach ($files as $file) {
            if (preg_match('/-(\d+)\.[a-z]+$/', $file, $matches) === 1) {
                $numbered[(int) $matches[1]] = $file;
            }
        }

        ksort($numbered);

        return array_values($numbered);
    }

    /**
     * Run a command, with a timeout and without going anywhere near a shell.
     *
     * `proc_open` with an argument array: no quoting, no escaping, and a
     * filename containing a space or a semicolon is just a filename. The paths
     * here come from the database, which means they ultimately come from
     * outside.
     *
     * @param array<int,string> $arguments
     * @return array{stdout:string,stderr:string}
     */
    private function run(array $arguments): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($arguments, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new RuntimeException('Could not run ' . basename($arguments[0]) . '.');
        }

        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout   = '';
        $stderr   = '';
        $deadline = time() + self::TIMEOUT_SECONDS;

        // Read both pipes as they fill. Waiting on proc_close first would
        // deadlock the moment either pipe's buffer filled up, which a long
        // document's warnings will do.
        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);

            $status = proc_get_status($process);

            if (!$status['running']) {
                break;
            }

            if (time() > $deadline) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                throw new RuntimeException(
                    'pdftoppm did not finish within ' . self::TIMEOUT_SECONDS . ' seconds.'
                );
            }

            usleep(50_000);
        }

        // Whatever was buffered between the last read and the exit.
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new RuntimeException(sprintf(
                '%s exited %d. %s',
                basename($arguments[0]),
                $exitCode,
                trim($stderr) !== '' ? trim($stderr) : 'It said nothing about why.'
            ));
        }

        // stderr on a zero exit is a warning about the document, not a failure.
        // Logged so it is findable, never treated as an error.
        if (trim($stderr) !== '') {
            error_log('[pdftoppm] ' . str_limit(preg_replace('/\s+/', ' ', $stderr) ?? $stderr, 300));
        }

        return ['stdout' => $stdout, 'stderr' => $stderr];
    }

    /** The configured `pdftoppm`, or the one on PATH. */
    private function binary(): ?string
    {
        $configured = trim((string) Config::get('pdf.pdftoppm', ''));

        if ($configured !== '') {
            return is_file($configured) ? $configured : null;
        }

        return $this->onPath('pdftoppm');
    }

    /** Another poppler tool, found next to pdftoppm or on PATH. */
    private function siblingBinary(string $name): ?string
    {
        $binary = $this->binary();

        if ($binary !== null) {
            $sibling = dirname($binary) . DIRECTORY_SEPARATOR . $name
                . (str_ends_with(strtolower($binary), '.exe') ? '.exe' : '');

            if (is_file($sibling)) {
                return $sibling;
            }
        }

        return $this->onPath($name);
    }

    private function onPath(string $name): ?string
    {
        $isWindows = DIRECTORY_SEPARATOR === chr(92);
        $command   = $isWindows ? 'where' : 'which';

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process     = @proc_open([$command, $name], $descriptors, $pipes);

        if (!is_resource($process)) {
            return null;
        }

        $found = trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if ($found === '') {
            return null;
        }

        // `where` can return several lines.
        $first = strtok($found, "\r\n");

        return is_string($first) && $first !== '' ? $first : null;
    }
}
