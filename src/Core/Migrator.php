<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Runs plain .sql files from database/migrations in filename order, recording
 * what has already been applied in a `migrations` table.
 */
final class Migrator
{
    private string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? Config::get('app.root') . '/database/migrations';
    }

    public function ensureTable(): void
    {
        Database::run(
            'CREATE TABLE IF NOT EXISTS migrations (
                id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                migration   VARCHAR(191) NOT NULL,
                batch       INT UNSIGNED NOT NULL DEFAULT 1,
                executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_migrations_migration (migration)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** @return array<int,string> Filenames on disk, in order. */
    public function available(): array
    {
        $files = glob($this->directory . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        return array_map('basename', $files);
    }

    /** @return array<int,string> */
    public function applied(): array
    {
        $this->ensureTable();
        $rows = Database::select('SELECT migration FROM migrations ORDER BY id');

        return array_map(static fn (array $r): string => (string) $r['migration'], $rows);
    }

    /** @return array<int,string> */
    public function pending(): array
    {
        return array_values(array_diff($this->available(), $this->applied()));
    }

    /**
     * Apply all pending migrations.
     *
     * @param callable(string,int):void|null $onFile Progress callback (file, statementCount).
     * @return array<int,string> The migrations that were applied.
     */
    public function run(?callable $onFile = null): array
    {
        $this->ensureTable();

        $batch = ((int) Database::scalar('SELECT COALESCE(MAX(batch), 0) FROM migrations')) + 1;
        $done  = [];

        foreach ($this->pending() as $file) {
            $path = $this->directory . '/' . $file;
            $sql  = file_get_contents($path);

            if ($sql === false) {
                throw new RuntimeException('Could not read migration: ' . $file);
            }

            $statements = self::splitStatements($sql);

            foreach ($statements as $statement) {
                try {
                    Database::connection()->exec($statement);
                } catch (\PDOException $e) {
                    throw new RuntimeException(
                        sprintf(
                            "Migration %s failed:\n%s\n\nStatement:\n%s%s",
                            $file,
                            $e->getMessage(),
                            $statement,
                            self::advice($e)
                        ),
                        0,
                        $e
                    );
                }
            }

            Database::insert('migrations', ['migration' => $file, 'batch' => $batch]);
            $done[] = $file;

            if ($onFile !== null) {
                $onFile($file, count($statements));
            }
        }

        return $done;
    }

    /**
     * Turn the handful of database errors that have an obvious cure into
     * instructions, rather than leaving an operator holding a raw SQLSTATE
     * halfway through an upgrade.
     *
     * Only failures with a single, unambiguous fix belong here.
     */
    private static function advice(\PDOException $e): string
    {
        $message = $e->getMessage();

        // 1142: the database user is missing a privilege the migration needs.
        if (str_contains($message, '1142') && str_contains($message, 'command denied')) {
            return "\n\nThe database user is missing a privilege this migration needs."
                . "\nNothing has been changed — fix the grant and run this again:"
                . "\n\n    php bin/migrate.php"
                . "\n\nGrant it as a database administrator:"
                . "\n\n    GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES"
                . "\n      ON `" . (string) Config::get('database.database') . "`.*"
                . "\n      TO '" . (string) Config::get('database.username') . "'@'localhost';"
                . "\n    FLUSH PRIVILEGES;";
        }

        if (str_contains($message, '1142') || str_contains($message, '1044') || str_contains($message, '1045')) {
            return "\n\nThis is a database permissions problem, not a fault in the migration."
                . "\nCheck what the application's user is allowed to do:"
                . "\n\n    SHOW GRANTS FOR '" . (string) Config::get('database.username') . "'@'localhost';";
        }

        return '';
    }

    /**
     * Split a .sql file into individual statements, ignoring semicolons that sit
     * inside quoted strings or comments.
     *
     * @return array<int,string>
     */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $current    = '';
        $length     = strlen($sql);
        $inSingle   = false;
        $inDouble   = false;
        $inBacktick = false;
        $inLineComment  = false;
        $inBlockComment = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($inLineComment) {
                if ($char === "\n") {
                    $inLineComment = false;
                    $current .= $char;
                }
                continue;
            }

            if ($inBlockComment) {
                if ($char === '*' && $next === '/') {
                    $inBlockComment = false;
                    $i++;
                }
                continue;
            }

            if (!$inSingle && !$inDouble && !$inBacktick) {
                if ($char === '-' && $next === '-') {
                    $inLineComment = true;
                    continue;
                }
                if ($char === '#') {
                    $inLineComment = true;
                    continue;
                }
                if ($char === '/' && $next === '*') {
                    $inBlockComment = true;
                    $i++;
                    continue;
                }
                if ($char === ';') {
                    $trimmed = trim($current);
                    if ($trimmed !== '') {
                        $statements[] = $trimmed;
                    }
                    $current = '';
                    continue;
                }
            }

            if ($char === "'" && !$inDouble && !$inBacktick) {
                // Handle the '' escape form.
                if ($inSingle && $next === "'") {
                    $current .= "''";
                    $i++;
                    continue;
                }
                $inSingle = !$inSingle;
            } elseif ($char === '"' && !$inSingle && !$inBacktick) {
                $inDouble = !$inDouble;
            } elseif ($char === '`' && !$inSingle && !$inDouble) {
                $inBacktick = !$inBacktick;
            } elseif ($char === '\\' && ($inSingle || $inDouble)) {
                $current .= $char . $next;
                $i++;
                continue;
            }

            $current .= $char;
        }

        $trimmed = trim($current);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }
}
