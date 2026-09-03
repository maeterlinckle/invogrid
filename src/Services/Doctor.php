<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Migrator;
use App\Models\Document;
use App\Models\PipelineJob;
use App\Models\Setting;
use App\Services\Llm\LlmFactory;
use Throwable;

/**
 * Everything that has to be true for a document to get all the way through,
 * checked in one pass.
 *
 * Written for `manage.sh doctor`, which is the first thing anybody runs on a
 * server that is misbehaving — so every row has to say what is wrong *and* what
 * to do about it. A check that reports "FAIL: storage" and stops has told the
 * reader nothing they did not already suspect.
 *
 * The dashboard's "Finish setting up" panel reads `integrations()` from here
 * rather than keeping its own copy. There was one, and two lists of what a
 * working install needs is one list and one thing that used to be the list.
 */
final class Doctor
{
    public const OK   = 'ok';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    /**
     * Every check, grouped.
     *
     * @return array<int,array{group:string,label:string,status:string,detail:string,hint:string}>
     */
    public static function run(): array
    {
        return array_merge(
            self::php(),
            self::configuration(),
            self::storage(),
            self::database(),
            self::tools(),
            self::integrationRows(),
            self::pipeline(),
        );
    }

    /** The worst status in a set of rows, for an exit code. */
    public static function worst(array $rows): string
    {
        foreach ([self::FAIL, self::WARN] as $level) {
            foreach ($rows as $row) {
                if ($row['status'] === $level) {
                    return $level;
                }
            }
        }

        return self::OK;
    }

    /** @return array<int,array<string,string>> */
    private static function php(): array
    {
        $rows = [[
            'group'  => 'PHP',
            'label'  => 'Version',
            'status' => version_compare(PHP_VERSION, '8.2', '>=') ? self::OK : self::FAIL,
            'detail' => PHP_VERSION,
            'hint'   => '8.2 or newer. Below that the syntax this is written in does not parse.',
        ]];

        /*
         * `openssl` is not a nice-to-have: it is what encrypts every stored API
         * token, and without it Setting::put() refuses to save one rather than
         * writing it to the database in the clear. An install missing it looks
         * like "the Clear Books secret will not save" with no reason given.
         */
        $required = [
            'pdo_mysql' => 'Talking to MariaDB at all.',
            'curl'      => 'Every integration. There are no vendor SDKs; it is all cURL.',
            'mbstring'  => 'Handling supplier names that are not plain ASCII.',
            'json'      => 'Every API payload and every JSON column.',
            'openssl'   => 'Encrypting stored credentials. Without it, no secret can be saved.',
            'fileinfo'  => 'Checking what an uploaded logo really is, rather than believing it.',
        ];

        foreach ($required as $extension => $why) {
            $rows[] = [
                'group'  => 'PHP',
                'label'  => 'Extension ' . $extension,
                'status' => extension_loaded($extension) ? self::OK : self::FAIL,
                'detail' => extension_loaded($extension) ? 'loaded' : 'missing',
                'hint'   => $why,
            ];
        }

        return $rows;
    }

    /** @return array<int,array<string,string>> */
    private static function configuration(): array
    {
        $key   = (string) Config::get('app.key', '');
        $usable = false;

        if ($key !== '') {
            // Not "is it set" but "does it work": a truncated or re-encoded key
            // is set and useless, and the failure shows up much later as a
            // token that will not decrypt.
            $probe  = Crypto::encrypt('invogrid-doctor');
            $usable = $probe !== null && Crypto::decrypt($probe) === 'invogrid-doctor';
        }

        $rows = [[
            'group'  => 'Configuration',
            'label'  => 'APP_KEY',
            'status' => $usable ? self::OK : self::FAIL,
            'detail' => $key === '' ? 'not set' : ($usable ? 'set, and a round trip works' : 'set, but will not encrypt'),
            'hint'   => 'php bin/console.php key:generate, then put it in .env. '
                . 'Back it up with the database: a database restored without it has secrets nobody can read.',
        ]];

        $url = (string) Config::get('app.url', '');

        $rows[] = [
            'group'  => 'Configuration',
            'label'  => 'APP_URL',
            'status' => $url === '' ? self::WARN : self::OK,
            'detail' => $url === '' ? 'not set' : $url,
            'hint'   => 'Used to build the Clear Books redirect URI and every absolute link InvoGrid writes.',
        ];

        $https = (bool) Config::get('security.force_https', true);

        $rows[] = [
            'group'  => 'Configuration',
            'label'  => 'HTTPS enforced',
            'status' => $https ? self::OK : self::WARN,
            'detail' => $https ? 'yes' : 'no — FORCE_HTTPS is false',
            'hint'   => 'Off is correct for local development and wrong anywhere else: '
                . 'passwords and session cookies cross the network in the clear.',
        ];

        return $rows;
    }

    /** @return array<int,array<string,string>> */
    private static function storage(): array
    {
        $rows = [];

        foreach (['path' => 'storage', 'pdf' => 'PDFs', 'pages' => 'page images', 'uploads' => 'uploads', 'logs' => 'logs'] as $key => $what) {
            $path     = (string) Config::get('storage.' . $key, '');
            $exists   = $path !== '' && is_dir($path);
            $writable = $exists && is_writable($path);

            $rows[] = [
                'group'  => 'Storage',
                'label'  => ucfirst($what),
                'status' => $writable ? self::OK : ($exists ? self::FAIL : self::WARN),
                'detail' => $path === ''
                    ? 'no path configured'
                    : ($exists ? ($writable ? 'writable' : 'NOT writable') : 'does not exist yet'),
                'hint'   => 'The web server user has to write here. `manage.sh permissions` fixes the modes.',
            ];
        }

        return $rows;
    }

    /** @return array<int,array<string,string>> */
    private static function database(): array
    {
        try {
            Database::connection();
        } catch (Throwable $e) {
            return [[
                'group'  => 'Database',
                'label'  => 'Connection',
                'status' => self::FAIL,
                'detail' => $e->getMessage(),
                'hint'   => 'Check DB_HOST, DB_DATABASE, DB_USERNAME and DB_PASSWORD in .env.',
            ]];
        }

        $rows = [[
            'group'  => 'Database',
            'label'  => 'Connection',
            'status' => self::OK,
            'detail' => (string) Config::get('database.database') . ' on ' . (string) Config::get('database.host'),
            'hint'   => '',
        ]];

        try {
            $pending = (new Migrator())->pending();

            $rows[] = [
                'group'  => 'Database',
                'label'  => 'Migrations',
                'status' => $pending === [] ? self::OK : self::FAIL,
                'detail' => $pending === []
                    ? 'up to date'
                    : count($pending) . ' pending: ' . implode(', ', array_slice($pending, 0, 3)),
                'hint'   => 'php bin/migrate.php',
            ];
        } catch (Throwable $e) {
            $rows[] = [
                'group'  => 'Database',
                'label'  => 'Migrations',
                'status' => self::FAIL,
                'detail' => $e->getMessage(),
                'hint'   => 'php bin/migrate.php',
            ];
        }

        // The OAuth token refresh serialises on GET_LOCK, because a refresh
        // token is single use and two workers racing for it lose the account.
        try {
            $lock = Database::scalar("SELECT GET_LOCK('invogrid.doctor', 0)");
            Database::scalar("SELECT RELEASE_LOCK('invogrid.doctor')");

            $rows[] = [
                'group'  => 'Database',
                'label'  => 'GET_LOCK available',
                'status' => (int) $lock === 1 ? self::OK : self::WARN,
                'detail' => (int) $lock === 1 ? 'yes' : 'refused',
                'hint'   => 'The Clear Books token refresh serialises on it. A refresh token is '
                    . 'single use, and two workers racing for it lose the connection.',
            ];
        } catch (Throwable $e) {
            $rows[] = [
                'group'  => 'Database',
                'label'  => 'GET_LOCK available',
                'status' => self::WARN,
                'detail' => $e->getMessage(),
                'hint'   => 'The Clear Books token refresh serialises on it.',
            ];
        }

        $admins = (int) Database::scalar("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1");

        $rows[] = [
            'group'  => 'Database',
            'label'  => 'Active administrators',
            'status' => $admins > 0 ? self::OK : self::FAIL,
            'detail' => (string) $admins,
            'hint'   => 'php bin/create-admin.php — an install with none can only be rescued from the server.',
        ];

        return $rows;
    }

    /** @return array<int,array<string,string>> */
    private static function tools(): array
    {
        $available = PdfRenderer::isAvailable();

        return [[
            'group'  => 'Tools',
            'label'  => 'pdftoppm (poppler-utils)',
            'status' => $available ? self::OK : self::FAIL,
            'detail' => $available ? 'found' : 'not found',
            'hint'   => 'apt install poppler-utils. Without it no page can be rendered, '
                . 'so no document can be read at all. Set PDFTOPPM_PATH if it is installed but off the PATH.',
        ]];
    }

    /**
     * The integrations, in the shape the dashboard's setup panel wants.
     *
     * @return array<int,array{label:string,done:bool,hint:string}>
     */
    public static function integrations(): array
    {
        $checks = [
            /*
             * No "documents can get in" check, and there does not need to be
             * one: the upload page is built in and works the moment somebody
             * with `documents.upload` signs in. There is nothing to configure
             * and so nothing that can be left unconfigured — which is most of
             * the point of native ingest.
             */
            [
                'label' => 'Clear Books OAuth2 credentials and business id',
                'done'  => Setting::isConfigured('clearbooks_client_id')
                    && Setting::isConfigured('clearbooks_client_secret')
                    && Setting::isConfigured('clearbooks_business_id'),
                'hint'  => 'Needed to read the supplier and account code lists, and to submit.',
            ],
            [
                'label' => 'Clear Books authorised',
                'done'  => Setting::isConfigured('clearbooks_refresh_token'),
                'hint'  => 'Complete the consent flow on Settings → Clear Books. Until then every '
                    . 'cached list is empty and every document lands in review saying so.',
            ],
        ];

        // Only the providers actually selected. Complaining about an OpenAI key
        // on a site that has chosen Anthropic for both stages is noise, and
        // noise in a checklist is what teaches people to stop reading it.
        $seen = [];

        foreach (LlmFactory::STAGES as $stage) {
            $provider = LlmFactory::provider($stage);

            if (isset($seen[$provider])) {
                continue;
            }

            $seen[$provider] = true;

            $checks[] = [
                'label' => ucfirst($provider) . ' API key',
                'done'  => LlmFactory::isConfigured($stage),
                'hint'  => 'Selected for the ' . $stage . ' stage (' . LlmFactory::model($stage) . ').',
            ];
        }

        return $checks;
    }

    /**
     * The dashboard's "Finish setting up" list: the integrations, plus the one
     * local dependency without which nothing works at all.
     *
     * @return array<int,array{label:string,done:bool,hint:string}>
     */
    public static function setupGaps(): array
    {
        return array_merge([[
            'label' => 'PDF page rendering',
            'done'  => PdfRenderer::isAvailable(),
            'hint'  => 'poppler-utils provides pdftoppm. Without it, nothing can be read.',
        ]], self::integrations());
    }

    /** @return array<int,array<string,string>> */
    private static function integrationRows(): array
    {
        $rows = [];

        foreach (self::integrations() as $check) {
            $rows[] = [
                'group'  => 'Integrations',
                'label'  => $check['label'],
                'status' => $check['done'] ? self::OK : self::WARN,
                'detail' => $check['done'] ? 'configured' : 'not configured',
                'hint'   => $check['hint'],
            ];
        }

        return $rows;
    }

    /**
     * Is the machine actually turning over?
     *
     * A queue with jobs in it and nothing running means the cron entry is
     * missing or failing — the single most common way a working install stops
     * working, and one that reports itself nowhere else.
     *
     * @return array<int,array<string,string>>
     */
    private static function pipeline(): array
    {
        try {
            $queue = PipelineJob::countsByStatus();
        } catch (Throwable) {
            return [];
        }

        $overdue = (int) Database::scalar(
            "SELECT COUNT(*) FROM pipeline_jobs
              WHERE status = 'queued' AND available_at < (NOW() - INTERVAL 15 MINUTE)"
        );

        $rows = [[
            'group'  => 'Pipeline',
            'label'  => 'Queue',
            'status' => $overdue > 0 ? self::WARN : self::OK,
            'detail' => sprintf(
                '%d waiting, %d running, %d failed%s',
                $queue['queued'] ?? 0,
                $queue['running'] ?? 0,
                $queue['failed'] ?? 0,
                $overdue > 0 ? ' — ' . $overdue . ' overdue by more than 15 minutes' : ''
            ),
            'hint'   => $overdue > 0
                ? 'Jobs are due and nothing has taken them. Is the process-queue cron entry installed and running? '
                    . '`manage.sh cron-install` writes it; `manage.sh queue` runs one pass by hand.'
                : '',
        ]];

        $counts = Document::countsByStatus();
        $failed = (int) ($counts[Document::FAILED] ?? 0);

        $rows[] = [
            'group'  => 'Pipeline',
            'label'  => 'Documents',
            'status' => $failed > 0 ? self::WARN : self::OK,
            'detail' => sprintf(
                '%d needing review, %d ready to submit, %d failed',
                $counts[Document::NEEDS_REVIEW] ?? 0,
                $counts[Document::READY_TO_SUBMIT] ?? 0,
                $failed
            ),
            'hint'   => $failed > 0 ? 'Each failure says why on its own page under /documents.' : '',
        ];

        return $rows;
    }
}
