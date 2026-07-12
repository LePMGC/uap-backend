<?php

namespace App\Modules\Operations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProvisioningProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Enforce your administrative access permission guard here
        return auth()->user()->tokenCan('manage:provisioning-config');
    }

    public function rules(): array
    {
        return [
            'name'                 => 'required|string|max:100',
            'reimbursement_type'   => ['required', Rule::in(['AIRTIME', 'BUNDLE'])],
            'provider_instance_id' => 'required|uuid|exists:provider_instances,id',
            'command_id'           => 'nullable|uuid|exists:commands,id',
            'debit_command_id'     => 'nullable|uuid|exists:commands,id',
            'execution_mode'       => ['required', Rule::in(['COMMAND', 'BATCH'])],
            'funding_account_id'   => 'required|uuid|exists:funding_accounts,id',
            'is_active'            => 'boolean'
        ];
    }
}
