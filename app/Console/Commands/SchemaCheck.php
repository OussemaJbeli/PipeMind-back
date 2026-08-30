<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Guards against drift between the live schema and the documented one in
 * PipeMind-data/database/schema.sql.
 *
 * Without this the documentation quietly becomes fiction, and PipeMind-data stops
 * being a reference anyone can trust. Wired into CI in roadmaps/22.
 */
class SchemaCheck extends Command
{
    protected $signature = 'pipemind:schema-check
                            {--path= : Path to schema.sql (default: ../PipeMind-data/database/schema.sql)}';

    protected $description = 'Diff the live database schema against the documented schema.sql';

    /** Laravel-owned tables that schema.sql deliberately does not document. */
    private const IGNORED = [
        'migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
        'sessions', 'password_reset_tokens', 'personal_access_tokens',
    ];

    public function handle(): int
    {
        $path = $this->option('path')
            ?? base_path('../PipeMind-data/database/schema.sql');

        if (! is_file($path)) {
            $this->error("schema.sql not found at {$path}");

            return self::FAILURE;
        }

        $documented = $this->parse(file_get_contents($path));
        $live = $this->live();

        $missing = array_diff(array_keys($documented), array_keys($live));
        $extra = array_diff(array_keys($live), array_keys($documented));

        $columnDrift = [];
        foreach (array_intersect_key($documented, $live) as $table => $cols) {
            $missingCols = array_diff($cols, $live[$table]);
            $extraCols = array_diff($live[$table], $cols);

            foreach ($missingCols as $c) {
                $columnDrift[] = "{$table}.{$c} — documented but not in the database";
            }
            foreach ($extraCols as $c) {
                $columnDrift[] = "{$table}.{$c} — in the database but not documented";
            }
        }

        if (! $missing && ! $extra && ! $columnDrift) {
            $this->info(sprintf('No drift. %d tables match schema.sql.', count($documented)));

            return self::SUCCESS;
        }

        foreach ($missing as $t) {
            $this->error("Table {$t} is documented but missing from the database");
        }
        foreach ($extra as $t) {
            $this->error("Table {$t} exists in the database but is not documented");
        }
        foreach ($columnDrift as $line) {
            $this->warn($line);
        }

        $this->newLine();
        $this->error('Schema drift detected. Update schema.sql or the migrations so they agree.');

        return self::FAILURE;
    }

    /** @return array<string,array<int,string>> */
    private function parse(string $sql): array
    {
        $tables = [];

        preg_match_all(
            '/CREATE TABLE (?:IF NOT EXISTS )?(\w+)\s*\((.*?)\n\);/s',
            $sql,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as [, $table, $body]) {
            $columns = [];

            foreach (explode("\n", $body) as $line) {
                $line = trim($line);

                if ($line === '' || str_starts_with($line, '--')) {
                    continue;
                }

                // Skip table-level constraints; we compare columns only.
                if (preg_match('/^(UNIQUE|PRIMARY KEY|FOREIGN KEY|CHECK|CONSTRAINT)\b/i', $line)) {
                    continue;
                }

                if (preg_match('/^(\w+)\s+/', $line, $m)) {
                    $columns[] = $m[1];
                }
            }

            $tables[$table] = $columns;
        }

        // ALTER TABLE ... ADD COLUMN (used for the vector columns).
        preg_match_all('/ALTER TABLE (\w+) ADD COLUMN (\w+)/i', $sql, $alters, PREG_SET_ORDER);
        foreach ($alters as [, $table, $column]) {
            if (isset($tables[$table])) {
                $tables[$table][] = $column;
            }
        }

        return $tables;
    }

    /** @return array<string,array<int,string>> */
    private function live(): array
    {
        $rows = DB::select("
            SELECT table_name, column_name
            FROM information_schema.columns
            WHERE table_schema = 'public'
            ORDER BY table_name, ordinal_position
        ");

        $tables = [];
        foreach ($rows as $row) {
            if (in_array($row->table_name, self::IGNORED, true)) {
                continue;
            }
            $tables[$row->table_name][] = $row->column_name;
        }

        return $tables;
    }
}
