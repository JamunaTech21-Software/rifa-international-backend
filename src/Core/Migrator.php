<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Runs database/migrations/*.sql in filename order and records each one in
 * the `migrations` table. Files are never edited after they have run on
 * staging — add a new numbered file instead.
 */
final class Migrator
{
    /** @var callable(string): void */
    private $output;

    public function __construct(
        private readonly Database $db,
        private readonly string $directory,
        ?callable $output = null,
    ) {
        $this->output = $output ?? static fn (string $line) => null;
    }

    /** @return list<string> filenames applied in this run */
    public function migrate(): array
    {
        $this->ensureMigrationsTable();
        $applied = [];

        foreach ($this->pending() as $file) {
            $this->runFile($this->directory . '/' . $file);
            $this->db->insert('migrations', ['filename' => $file]);
            ($this->output)("Migrated: {$file}");
            $applied[] = $file;
        }

        if ($applied === []) {
            ($this->output)('Nothing to migrate.');
        }

        return $applied;
    }

    /**
     * Drop every table and migrate from scratch. Refuses to run in production.
     *
     * @return list<string>
     */
    public function fresh(string $environment): array
    {
        if (in_array($environment, ['production', 'staging'], true)) {
            throw new RuntimeException("Refusing to drop tables in {$environment}.");
        }

        $tables = $this->db->fetchAll(
            'SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE()',
        );
        $this->db->execute('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            $this->db->execute('DROP TABLE IF EXISTS `' . str_replace('`', '', (string) $table['name']) . '`');
        }
        $this->db->execute('SET FOREIGN_KEY_CHECKS = 1');
        ($this->output)('Dropped all tables.');

        return $this->migrate();
    }

    /** @return list<string> */
    public function pending(): array
    {
        $this->ensureMigrationsTable();
        $done = array_column($this->db->fetchAll('SELECT filename FROM migrations'), 'filename');

        return array_values(array_diff($this->files(), $done));
    }

    /** Execute every statement of a .sql file (statements end with ";" at line end). */
    public function runFile(string $path): void
    {
        if (!is_file($path)) {
            throw new RuntimeException("SQL file not found: {$path}");
        }

        foreach ($this->statements((string) file_get_contents($path)) as $statement) {
            $this->db->pdo()->exec($statement);
        }
    }

    /** @return list<string> */
    private function files(): array
    {
        $files = array_map('basename', glob($this->directory . '/*.sql') ?: []);
        sort($files, SORT_STRING);

        return $files;
    }

    /** @return list<string> */
    private function statements(string $sql): array
    {
        $lines = array_filter(
            preg_split('/\R/', $sql) ?: [],
            static fn (string $line) => !str_starts_with(ltrim($line), '--'),
        );
        $parts = preg_split('/;\s*(?:\R|$)/', implode("\n", $lines)) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $s) => $s !== ''));
    }

    private function ensureMigrationsTable(): void
    {
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                filename VARCHAR(191) NOT NULL UNIQUE,
                ran_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }
}
