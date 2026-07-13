<?php

namespace App\Modules\Operations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreFundingAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'                 => 'required|string|max:100',
            'msisdn'               => 'required|string|regex:/^\d{10,15}$/|unique:funding_accounts,msisdn,' . ($this->funding_account?->id ?? 'NULL'),
            'description'          => 'nullable|string',
            'is_active'            => 'boolean'
        ];
    }
}
