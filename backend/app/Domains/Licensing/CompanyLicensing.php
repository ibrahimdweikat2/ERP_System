<?php

namespace App\Domains\Licensing;

use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Platform\Models\Company;
use App\Models\User;
use App\Support\BusinessException;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A company's LicenseBox license. Without a valid one, its users hold no permission
 * (User::hasPermission) and reach only the dashboard and, for the owner, the license page.
 *
 * Validity is read from the server-signed local key, not from the table's columns, so
 * editing the row cannot extend a license. The key is re-verified online when its
 * next_check_at passes (hourly schedule, or the owner's "check now"); if the server
 * cannot be reached the license stays valid until the key's valid_until (grace period).
 *
 * Scoped: queue workers and requests each start with a fresh validity cache.
 */
class CompanyLicensing
{
    /** Server rejections after which the company must activate again (as in the LicenseBox SDK). */
    private const FATAL = ['invalid_license', 'invalid_activation', 'license_revoked', 'license_expired', 'domain_mismatch', 'domain_not_allowed'];

    private const MESSAGES = [
        'invalid_license' => 'مفتاح الترخيص غير صحيح.',
        'invalid_activation' => 'تفعيل هذه الشركة لم يعد معروفاً لدى خادم التراخيص. فعّل الترخيص من جديد.',
        'license_suspended' => 'الترخيص موقوف مؤقتاً لدى خادم التراخيص.',
        'license_revoked' => 'الترخيص ملغى.',
        'license_expired' => 'انتهت صلاحية الترخيص.',
        'activation_limit' => 'وصل هذا الترخيص إلى الحد الأقصى لعدد التفعيلات.',
        'domain_not_allowed' => 'الترخيص غير صالح لنطاق هذا النظام.',
        'domain_mismatch' => 'الترخيص مفعّل على نطاق آخر.',
        'ip_not_allowed' => 'الترخيص غير صالح لعنوان IP هذا الخادم.',
        'invalid_product' => 'المنتج غير معروف لدى خادم التراخيص. راجع إعدادات الاتصال.',
        'product_inactive' => 'المنتج غير متاح حالياً لدى خادم التراخيص.',
        'invalid_api_key' => 'مفتاح الاتصال بخادم التراخيص غير صحيح. راجع إدارة المنصة.',
        'forbidden' => 'مفتاح الاتصال بخادم التراخيص لا يخص هذا المنتج. راجع إدارة المنصة.',
    ];

    /** @var array<int, bool> */
    private array $valid = [];

    public function __construct(private LicenseServer $server, private RecordAudit $audit, private CompanyContext $context) {}

    public function enforced(): bool
    {
        return (bool) config('erp.license.enforce');
    }

    public function isValid(?int $companyId): bool
    {
        if (! $this->enforced()) {
            return true;
        }

        return $companyId !== null && ($this->valid[$companyId] ??= $this->state($this->row($companyId))['valid']);
    }

    /** Everything the license page shows. The key itself is masked. */
    public function status(int $companyId): array
    {
        $row = $this->row($companyId);
        $state = $this->state($row);
        $data = $row ? (array) json_decode((string) $row->data, true) : [];
        $key = $state['key'];

        return [
            'enforced' => $this->enforced(), 'server_configured' => $this->server->configured(), 'domain' => $this->server->domain(),
            'valid' => $state['valid'], 'state' => $state['state'],
            'license_key' => $key ? substr($key, 0, 4).str_repeat('•', max(strlen($key) - 8, 4)).substr($key, -4) : null,
            'license_type' => $data['license_type'] ?? null, 'expires_at' => $data['expires_at'] ?? null,
            'updates_expire_at' => $data['updates_expire_at'] ?? null,
            'activations_used' => $data['activations_used'] ?? null, 'max_activations' => $data['max_activations'] ?? null,
            'valid_until' => isset($state['payload']['valid_until']) ? Carbon::createFromTimestamp($state['payload']['valid_until'])->toIso8601String() : null,
            'activated_at' => $row?->activated_at, 'last_checked_at' => $row?->last_checked_at, 'next_check_at' => $row?->next_check_at,
            'reason' => $row?->reason, 'message' => $row?->reason ? $this->message($row->reason, $row->last_error) : null,
            'last_error' => $row?->last_error,
        ];
    }

    public function activate(Company $company, string $licenseKey, User $actor): void
    {
        $key = strtoupper(trim($licenseKey));
        $this->requireServer();
        $hash = hash('sha256', $key);
        if (DB::table('company_licenses')->where('license_hash', $hash)->where('company_id', '!=', $company->id)->exists()) {
            throw new BusinessException('LICENSE_IN_USE', 'هذا الترخيص مستخدم لشركة أخرى على المنصة.', 409);
        }

        $response = $this->send('activate_license', ['license_key' => $key, 'client_name' => $company->name, 'domain' => $this->server->domain()]);
        if (! $response['status']) {
            throw new BusinessException('LICENSE_REJECTED', $this->message($response['reason'] ?? null, $response['message']), 422);
        }
        $payload = $this->server->decodeLocalKey((string) ($response['data']['local_key'] ?? ''), $key);
        if (! $payload || $payload['instance_id'] !== ($response['data']['instance_id'] ?? null)) {
            throw new BusinessException('LICENSE_UNTRUSTED', 'تعذر التحقق من توقيع الترخيص الصادر عن الخادم.', 502);
        }

        $previous = $this->row($company->id);
        try {
            DB::transaction(function () use ($company, $key, $hash, $response, $payload, $actor, $previous) {
                DB::table('company_licenses')->updateOrInsert(['company_id' => $company->id], [
                    'license_key' => Crypt::encryptString($key), 'license_hash' => $hash,
                    'instance_id' => $payload['instance_id'], 'local_key' => $response['data']['local_key'],
                    'data' => json_encode(array_diff_key($response['data'], ['local_key' => 1]), JSON_UNESCAPED_UNICODE),
                    'status' => 'active', 'reason' => null, 'last_error' => null,
                    'activated_at' => now(), 'last_checked_at' => now(), 'next_check_at' => Carbon::createFromTimestamp($payload['next_check_at']),
                    'activated_by' => $actor->id, 'created_at' => $previous?->created_at ?? now(), 'updated_at' => now(),
                ]);
                $this->audit->execute('license.activated', 'company', $company->id, $previous ? ['license_hash' => $previous->license_hash] : null,
                    ['license_hash' => $hash, 'license_type' => $response['data']['license_type'] ?? null, 'expires_at' => $response['data']['expires_at'] ?? null], $actor->id);
            });
        } catch (UniqueConstraintViolationException) {
            throw new BusinessException('LICENSE_IN_USE', 'هذا الترخيص مستخدم لشركة أخرى على المنصة.', 409);
        }
        unset($this->valid[$company->id]);

        // A replaced key frees its slot on the server; failing to do so does not undo the new license.
        if ($previous && $previous->license_hash !== $hash && ($oldKey = $this->decryptKey($previous->license_key))) {
            try {
                $this->server->call('deactivate_license', ['license_key' => $oldKey, 'instance_id' => $previous->instance_id]);
            } catch (RuntimeException) {
            }
        }
    }

    /**
     * Re-checks the license online and stores the outcome. A server that cannot be reached
     * leaves the license as it is (valid until its grace period ends) and records the error.
     */
    public function verify(int $companyId): void
    {
        $row = $this->row($companyId);
        $key = $row ? $this->decryptKey($row->license_key) : null;
        if (! $row || ! $key || $row->status === 'invalid') {
            return;
        }
        unset($this->valid[$companyId]);
        try {
            $response = $this->server->call('verify_license', ['license_key' => $key, 'instance_id' => $row->instance_id, 'domain' => $this->server->domain()]);
        } catch (RuntimeException $e) {
            DB::table('company_licenses')->where('id', $row->id)->update(['last_error' => mb_substr($e->getMessage(), 0, 500), 'last_checked_at' => now(), 'updated_at' => now()]);

            return;
        }

        if ($response['status'] && ($payload = $this->server->decodeLocalKey((string) ($response['data']['local_key'] ?? ''), $key))) {
            DB::table('company_licenses')->where('id', $row->id)->update([
                'local_key' => $response['data']['local_key'], 'instance_id' => $payload['instance_id'],
                'data' => json_encode(array_diff_key($response['data'], ['local_key' => 1]), JSON_UNESCAPED_UNICODE),
                'status' => 'active', 'reason' => null, 'last_error' => null,
                'last_checked_at' => now(), 'next_check_at' => Carbon::createFromTimestamp($payload['next_check_at']), 'updated_at' => now(),
            ]);

            return;
        }

        $reason = $response['status'] ? 'untrusted_key' : ($response['reason'] ?? 'rejected');
        $status = in_array($reason, self::FATAL, true) ? 'invalid' : 'rejected';
        DB::table('company_licenses')->where('id', $row->id)->update([
            'status' => $status, 'reason' => mb_substr($reason, 0, 60), 'last_error' => mb_substr((string) $response['message'], 0, 500),
            'last_checked_at' => now(), 'updated_at' => now(),
        ]);
        if ($row->status !== $status) {
            $this->context->run($companyId, fn () => $this->audit->execute('license.'.$status, 'company', $companyId, ['status' => $row->status], ['status' => $status, 'reason' => $reason]));
        }
    }

    /** Re-checks every license whose next online check is due. Run by the scheduler. */
    public function verifyDue(): int
    {
        $ids = DB::table('company_licenses')->whereIn('status', ['active', 'rejected'])->where('next_check_at', '<=', now())->pluck('company_id');
        foreach ($ids as $companyId) {
            $this->verify((int) $companyId);
        }

        return $ids->count();
    }

    /** Frees this company's activation on the server and removes the license. */
    public function deactivate(int $companyId, User $actor): void
    {
        $row = $this->row($companyId);
        if (! $row) {
            throw new BusinessException('LICENSE_MISSING', 'لا يوجد ترخيص مفعّل لهذه الشركة.', 404);
        }
        if ($key = $this->decryptKey($row->license_key)) {
            $this->requireServer();
            $response = $this->send('deactivate_license', ['license_key' => $key, 'instance_id' => $row->instance_id]);
            // The server may already have forgotten this activation; the local row goes either way then.
            if (! $response['status'] && ! in_array($response['reason'] ?? '', ['invalid_activation', 'invalid_license'], true)) {
                throw new BusinessException('LICENSE_REJECTED', $this->message($response['reason'] ?? null, $response['message']), 422);
            }
        }
        DB::transaction(function () use ($row, $companyId, $actor) {
            DB::table('company_licenses')->where('id', $row->id)->delete();
            $this->audit->execute('license.deactivated', 'company', $companyId, ['license_hash' => $row->license_hash, 'status' => $row->status], null, $actor->id);
        });
        unset($this->valid[$companyId]);
    }

    public function isDue(int $companyId): bool
    {
        $row = $this->row($companyId);

        return $row && in_array($row->status, ['active', 'rejected'], true) && $row->next_check_at && Carbon::parse($row->next_check_at)->isPast();
    }

    private function row(int $companyId): ?object
    {
        return DB::table('company_licenses')->where('company_id', $companyId)->first();
    }

    /** @return array{valid: bool, state: string, key: ?string, payload: ?array} */
    private function state(?object $row): array
    {
        if (! $row) {
            return ['valid' => false, 'state' => 'missing', 'key' => null, 'payload' => null];
        }
        $key = $this->decryptKey($row->license_key);
        $payload = $key ? $this->server->decodeLocalKey($row->local_key, $key) : null;
        if (! $payload || $payload['instance_id'] !== $row->instance_id) {
            return ['valid' => false, 'state' => 'untrusted', 'key' => $key, 'payload' => null];
        }
        if ($row->status !== 'active') {
            return ['valid' => false, 'state' => $row->status, 'key' => $key, 'payload' => $payload];
        }
        // Past the grace period without a successful online check (or past the license's own expiry).
        if (now()->timestamp >= (int) $payload['valid_until']) {
            return ['valid' => false, 'state' => 'lapsed', 'key' => $key, 'payload' => $payload];
        }

        return ['valid' => true, 'state' => 'active', 'key' => $key, 'payload' => $payload];
    }

    private function decryptKey(string $encrypted): ?string
    {
        try {
            return Crypt::decryptString($encrypted);
        } catch (DecryptException) {
            return null;
        }
    }

    private function requireServer(): void
    {
        if (! $this->server->configured()) {
            throw new BusinessException('LICENSE_SERVER_NOT_CONFIGURED', 'لم يُضبط الاتصال بخادم التراخيص على هذا النظام. راجع إدارة المنصة.', 503);
        }
    }

    private function send(string $endpoint, array $data): array
    {
        try {
            return $this->server->call($endpoint, $data);
        } catch (RuntimeException $e) {
            report($e);
            throw new BusinessException('LICENSE_SERVER_UNREACHABLE', 'تعذر الاتصال بخادم التراخيص أو التحقق من رده. حاول مرة أخرى لاحقاً.', 502);
        }
    }

    private function message(?string $reason, ?string $fallback): string
    {
        return self::MESSAGES[$reason ?? ''] ?? ($reason === 'untrusted_key' ? 'تعذر التحقق من توقيع الترخيص الصادر عن الخادم.' : 'رفض خادم التراخيص الطلب: '.($fallback ?: $reason));
    }
}
