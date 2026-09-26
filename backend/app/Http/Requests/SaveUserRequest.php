<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class SaveUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('users.manage') ?? false;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($this->route('user'))],
            'phone' => ['nullable', 'string', 'max:40'], 'mfa_required' => ['sometimes', 'boolean'], 'status' => ['required', Rule::in(['active', 'disabled'])],
            'password' => [$this->isMethod('POST') ? 'required' : 'nullable', 'string', Password::min(12)->mixedCase()->numbers()],
            'role_ids' => ['required', 'array', 'min:1'], 'role_ids.*' => ['required', 'integer', 'distinct', 'exists:roles,id']];
    }
}
