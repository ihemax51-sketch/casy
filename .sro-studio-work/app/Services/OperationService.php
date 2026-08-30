<?php

namespace App\Services;

use App\Models\ActionLog;
use Illuminate\Support\Str;

final class OperationService
{
    public function __construct(private readonly CapabilityRegistry $capabilities) {}

    public function begin(string $action, ?int $userId, ?int $profileId, array $context = [], string $risk = 'low'): ActionLog
    {
        $context = $this->sanitize($context);
        if ($this->capabilities->can($action)) {
            $risk = (string) ($this->capabilities->get($action)['risk'] ?? $risk);
        }
        $risk = in_array($risk, ['low', 'medium', 'high', 'critical'], true) ? $risk : 'medium';
        return ActionLog::create([
            'operation_id' => (string) Str::uuid(),
            'user_id' => $userId,
            'server_profile_id' => $profileId,
            'action' => $action,
            'execution_mode' => $context['execution_mode'] ?? 'direct',
            'status' => 'started',
            'risk_level' => $risk,
            'summary' => $context,
            'before_snapshot' => $context['before'] ?? null,
            'backup_reference' => $context['backup_reference'] ?? null,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    public function complete(ActionLog $operation, array $after = [], array $summary = []): ActionLog
    {
        $operation->forceFill([
            'status' => 'success',
            'after_snapshot' => $this->sanitize($after) ?: null,
            'summary' => $this->sanitize(array_merge($operation->summary ?? [], $summary)),
        ])->save();
        return $operation->refresh();
    }

    public function fail(ActionLog $operation, string $message): void
    {
        $operation->forceFill([
            'status' => 'failed',
            'summary' => $this->sanitize(array_merge($operation->summary ?? [], ['error' => $message])),
        ])->save();
    }

    private function sanitize(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && preg_match('/pass(word)?|secret|token|credential|private.?key|connection.?string|dsn/i', $key)) {
            return '[redacted]';
        }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $childKey => $childValue) {
                $result[$childKey] = $this->sanitize($childValue, is_string($childKey) ? $childKey : null);
            }
            return $result;
        }
        return is_scalar($value) || $value === null ? $value : (string) $value;
    }
}
