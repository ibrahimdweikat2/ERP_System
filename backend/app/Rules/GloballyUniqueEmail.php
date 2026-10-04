<?php

namespace App\Rules;

use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An email signs in to exactly one account on the whole platform, because the
 * login page finds the company from the email. A company-scoped unique check
 * would miss accounts in other companies.
 */
class GloballyUniqueEmail implements ValidationRule
{
    public function __construct(private ?int $ignoreUserId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $taken = app(CompanyContext::class)->bypass(fn () => User::where('email', $value)
            ->when($this->ignoreUserId, fn ($q) => $q->where('id', '!=', $this->ignoreUserId))->exists());
        if ($taken) {
            $fail('هذا البريد الإلكتروني مستخدم في حساب آخر.');
        }
    }
}
