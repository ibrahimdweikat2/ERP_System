<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\DB;

class IdempotentRequest
{
    public function execute(int $userId, string $scope, string $key, array $payload, Closure $action): array
    {
        if (! preg_match('/^[A-Za-z0-9_.:-]{8,100}$/', $key)) {
            throw new BusinessException('IDEMPOTENCY_KEY_REQUIRED', 'أعد إرسال العملية بمفتاح طلب صالح.');
        }
        $hash = hash('sha256', json_encode($this->canonical($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return DB::transaction(function () use ($userId, $scope, $key, $hash, $action) {
            DB::table('idempotency_keys')->insertOrIgnore(['user_id' => $userId, 'scope' => $scope, 'request_key' => $key, 'payload_hash' => $hash, 'created_at' => now()]);
            $record = DB::table('idempotency_keys')->where(['user_id' => $userId, 'scope' => $scope, 'request_key' => $key])->lockForUpdate()->first();
            if (! hash_equals($record->payload_hash, $hash)) {
                throw new BusinessException('IDEMPOTENCY_PAYLOAD_CONFLICT', 'استُخدم مفتاح الطلب لبيانات مختلفة.', 409);
            }
            if ($record->result_json !== null) {
                return json_decode($record->result_json, true, 512, JSON_THROW_ON_ERROR);
            }
            $result = $action();
            DB::table('idempotency_keys')->where('id', $record->id)->update(['result_json' => json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);

            return $result;
        }, 5);
    }

    private function canonical(array $data): array
    {
        if (! array_is_list($data)) {
            ksort($data);
        }foreach ($data as $key => $v) {
            if (is_array($v)) {
                $data[$key] = $this->canonical($v);
            }
        }

return $data;
    }
}
