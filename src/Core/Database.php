<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * Thin PDO wrapper. Every query here is a prepared statement — no string
 * concatenation of user input into SQL anywhere in the application.
 *
 * The target database is MariaDB. The DSN prefix below is still `mysql:` and
 * the required extension is still `pdo_mysql` — those are the names of PDO's
 * driver and PHP's extension, which MariaDB uses too. Do not "correct" them
 * to `mariadb:`; no such PDO driver exists.
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $config = Config::get('database');

        // `mysql:` is PDO's driver name; it is the correct DSN for MariaDB.
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['database'],
            $config['charset']
        );

        try {
            self::$pdo = new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Database connection failed: ' . $e->getMessage(), (int) $e->getCode(), $e);
        }

        return self::$pdo;
    }

    /** @param array<string|int,mixed> $params */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        $statement = self::connection()->prepare($sql);
        $statement->execute($params);

        return $statement;
    }

    /**
     * @param array<string|int,mixed> $params
     * @return array<string,mixed>|null
     */
    public static function selectOne(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string|int,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    public static function select(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** @param array<string|int,mixed> $params */
    public static function scalar(string $sql, array $params = []): mixed
    {
        $value = self::run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * Insert a row from an associative array and return the new id.
     *
     * Column names come from application code only (never from user input);
     * all values are bound as parameters.
     *
     * @param array<string,mixed> $data
     */
    public static function insert(string $table, array $data): int
    {
        $columns      = array_keys($data);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);

        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $columns)),
            implode(', ', $placeholders)
        );

        self::run($sql, self::bindings($data));

        return (int) self::connection()->lastInsertId();
    }

    /**
     * Update a row by primary key. Returns the number of affected rows.
     *
     * @param array<string,mixed> $data
     */
    public static function update(string $table, array $data, int $id, string $key = 'id'): int
    {
        if ($data === []) {
            return 0;
        }

        $sets = [];
        foreach (array_keys($data) as $column) {
            $sets[] = sprintf('`%s` = :%s', $column, $column);
        }

        $sql = sprintf(
            'UPDATE `%s` SET %s WHERE `%s` = :__id',
            $table,
            implode(', ', $sets),
            $key
        );

        $params = self::bindings($data);
        $params['__id'] = $id;

        return self::run($sql, $params)->rowCount();
    }

    public static function beginTransaction(): void
    {
        self::connection()->beginTransaction();
    }

    public static function commit(): void
    {
        self::connection()->commit();
    }

    public static function rollBack(): void
    {
        if (self::connection()->inTransaction()) {
            self::connection()->rollBack();
        }
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function bindings(array $data): array
    {
        $params = [];
        foreach ($data as $column => $value) {
            if (is_bool($value)) {
                $value = $value ? 1 : 0;
            }
            $params[$column] = $value;
        }

        return $params;
    }
}
