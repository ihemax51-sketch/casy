<?php

namespace App\Console\Commands;

use App\Models\ServerProfile;
use App\Services\SchemaDiscoveryService;
use Illuminate\Console\Command;
use Throwable;

class DiscoverCasySchema extends Command
{
    protected $signature = 'casy:discover-schema';

    protected $description = 'Test the active SQL Server profile and refresh its schema map';

    public function handle(SchemaDiscoveryService $discovery): int
    {
        $profile = ServerProfile::query()->where('is_active', true)->latest()->first();
        if (! $profile) {
            $this->error('No active CASY profile exists.');
            return self::FAILURE;
        }

        try {
            $result = $discovery->inspect($profile);
            $profile->forceFill([
                'connection_status' => 'connected',
                'connection_error' => null,
                'schema_stats' => $result,
                'schema_fingerprint' => $result['fingerprint'],
                'last_tested_at' => now(),
                'last_connected_at' => now(),
            ])->save();

            $this->info('CONNECTED');
            $this->table(['Metric', 'Value'], [
                ['Tables', $result['table_count']],
                ['Columns', $result['column_count']],
                ['Foreign keys', $result['foreign_key_count']],
                ['Procedures', $result['procedure_count']],
                ['Views', $result['view_count']],
            ]);
            return self::SUCCESS;
        } catch (Throwable $exception) {
            $profile->forceFill([
                'connection_status' => 'failed',
                'connection_error' => $exception->getMessage(),
                'last_tested_at' => now(),
            ])->save();
            $this->error('FAILED: '.$exception->getMessage());
            return self::FAILURE;
        }
    }
}
