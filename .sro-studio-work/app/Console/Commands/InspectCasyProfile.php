<?php

namespace App\Console\Commands;

use App\Models\ServerProfile;
use Illuminate\Console\Command;

class InspectCasyProfile extends Command
{
    protected $signature = 'casy:inspect-profile {--match= : Show discovered table names containing this text}';

    protected $description = 'Show non-sensitive CASY server profile and schema status';

    public function handle(): int
    {
        $profile = ServerProfile::query()->latest()->first();
        if (! $profile) {
            $this->error('NO_PROFILE');
            return self::FAILURE;
        }

        $this->line(json_encode([
            'profile' => $profile->only([
                'id', 'name', 'host', 'port', 'account_database', 'shard_database',
                'log_database', 'proxy_database', 'icon_root', 'connection_status',
                'connection_error', 'last_tested_at',
            ]),
            'schema' => [
                'has_schema' => is_array($profile->schema_stats),
                'tables' => data_get($profile->schema_stats, 'table_count'),
                'relations' => data_get($profile->schema_stats, 'foreign_key_count'),
                'capabilities' => data_get($profile->schema_stats, 'capabilities'),
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($match = trim((string) $this->option('match'))) {
            $this->newLine();
            $this->info('MATCHING TABLES');
            foreach (data_get($profile->schema_stats, 'databases', []) as $database => $info) {
                $matches = array_values(array_filter(
                    $info['tables'] ?? [],
                    fn (string $table) => str_contains(strtolower($table), strtolower($match))
                ));
                foreach ($matches as $table) {
                    $this->line($database.'.dbo.'.$table);
                }
            }
        }

        return self::SUCCESS;
    }
}
