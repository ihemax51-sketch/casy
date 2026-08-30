<?php

namespace App\Console\Commands;

use App\Models\ServerProfile;
use App\Services\KmtGuardCommandService;
use Illuminate\Console\Command;

final class TestCasyLive extends Command
{
    protected $signature = 'casy:test-live';

    protected $description = 'Run a read-only smoke test for the visible KMTGuard Live Control contract';

    public function handle(KmtGuardCommandService $commands): int
    {
        $profile = ServerProfile::query()->where('is_active', true)->where('connection_status', 'connected')->latest()->firstOrFail();
        $procedures = $commands->procedures($profile);
        $this->table(['Action', 'Procedure', 'Parameters'], array_map(static fn (array $procedure): array => [
            $procedure['label'],
            $procedure['name'],
            implode(', ', array_column($procedure['parameters'], 'name')) ?: 'None',
        ], $procedures));

        if ($procedures === []) {
            $this->error('No allowlisted Live Control procedures were discovered.');
            return self::FAILURE;
        }
        $this->info(count($procedures).' allowlisted live action(s) discovered. No command was executed.');
        return self::SUCCESS;
    }
}
