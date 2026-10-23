<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
            // A new address is confirmed by link, and asking for it needs the password, so a hijacked session cannot redirect the account.
            'current_password' => [
                Rule::requiredIf(fn () => $this->changesEmail()),
                'current_password',
            ],
        ];
    }

    /** Whether the submitted address differs from the account's current one. */
    public function changesEmail(): bool
    {
        return strcasecmp((string) $this->input('email'), $this->user()->email) !== 0;
    }
}
