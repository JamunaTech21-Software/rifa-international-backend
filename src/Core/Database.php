<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;
use PDO;
use PDOStatement;
use Throwable;

/**
 * Thin PDO wrapper. Always pass values as parameters — never concatenate
 * user input into SQL. Native prepares are used, so a named placeholder can
 * appear only once per statement (use :from1/:from2 or positional ?).
 */
final class Database
{
    private ?PDO $pdo = null;

    private int $transactionDepth = 0;

    /**
     * @param array{host: string, port: int|string, database: string, username: string, password: ?string,
     *              charset?: string, collation?: string, timezone?: string} $config
     */
    public function __construct(private readonly array $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $c = $this->config;
            $charset = $c['charset'] ?? 'utf8mb4';
            $dsn = "mysql:host={$c['host']};port={$c['port']};dbname={$c['database']};charset={$charset}";

            $this->pdo = new PDO($dsn, $c['username'], $c['password'] ?? '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);

            $collation = $c['collation'] ?? 'utf8mb4_unicode_ci';
            $this->pdo->exec("SET NAMES {$charset} COLLATE {$collation}");
            $this->pdo->exec('SET time_zone = ' . $this->pdo->quote($c['timezone'] ?? '+06:00'));
        }

        return $this->pdo;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /** @param array<int|string, mixed> $params */
    public function fetchValue(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * Run INSERT/UPDATE/DELETE. Returns the number of affected rows.
     *
     * @param array<int|string, mixed> $params
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    /**
     * Insert one row and return its auto-increment id.
     *
     * @param array<string, mixed> $data column => value
     */
    public function insert(string $table, array $data): int
    {
        $this->assertIdentifier($table);
        $columns = array_keys($data);
        array_walk($columns, fn (string $column) => $this->assertIdentifier($column));

        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $table,
            implode('`, `', $columns),
            implode(', ', array_fill(0, count($columns), '?')),
        );
        $this->run($sql, array_values($data));

        return $this->lastInsertId();
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * Run $callback inside a transaction; commits on success, rolls back and
     * rethrows on any exception. Nested calls join the outer transaction.
     *
     * @template T
     * @param callable(Database): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();
        try {
            $result = $callback($this);
            $this->commit();

            return $result;
        } catch (Throwable $e) {
            $this->rollBack();
            throw $e;
        }
    }

    public function beginTransaction(): void
    {
        if ($this->transactionDepth === 0) {
            $this->pdo()->beginTransaction();
        }
        $this->transactionDepth++;
    }

    public function commit(): void
    {
        $this->transactionDepth = max(0, $this->transactionDepth - 1);
        if ($this->transactionDepth === 0 && $this->pdo()->inTransaction()) {
            $this->pdo()->commit();
        }
    }

    public function rollBack(): void
    {
        $this->transactionDepth = 0;
        if ($this->pdo()->inTransaction()) {
            $this->pdo()->rollBack();
        }
    }

    public function inTransaction(): bool
    {
        return $this->transactionDepth > 0;
    }

    /** @param array<int|string, mixed> $params */
    private function run(string $sql, array $params): PDOStatement
    {
        $statement = $this->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue(
                is_int($key) ? $key + 1 : ':' . ltrim($key, ':'),
                is_bool($value) ? (int) $value : $value,
                match (true) {
                    is_int($value), is_bool($value) => PDO::PARAM_INT,
                    $value === null => PDO::PARAM_NULL,
                    default => PDO::PARAM_STR,
                },
            );
        }
        $statement->execute();

        return $statement;
    }

    private function assertIdentifier(string $name): void
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/', $name)) {
            throw new InvalidArgumentException("Invalid SQL identifier [{$name}].");
        }
    }
}
