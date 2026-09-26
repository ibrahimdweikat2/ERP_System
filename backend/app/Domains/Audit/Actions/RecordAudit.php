<?php

namespace App\Domains\Audit\Actions;

use App\Domains\Audit\Models\AuditLog;
use Illuminate\Support\Facades\Auth;

class RecordAudit
{
    public function execute(string $action, string $entityType, string|int|null $entityId, ?array $before = null, ?array $after = null, ?int $actorId = null): AuditLog
    {
        return AuditLog::create([
            'actor_user_id' => $actorId ?? Auth::id(), 'action' => $action, 'entity_type' => $entityType,
            'entity_id' => $entityId, 'before_json' => $this->mask($before), 'after_json' => $this->mask($after),
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
            'user_agent' => app()->runningInConsole() ? null : mb_substr(request()->userAgent() ?? '', 0, 512),
            'occurred_at' => now(),
        ]);
    }

    private function mask(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }
        foreach ($data as $key => $value) {
            if (preg_match('/password|token|secret|national_id|account_number|iban/i', (string) $key)) {
                $data[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $data[$key] = $this->mask($value);
            }
        }

        return $data;
    }
}
