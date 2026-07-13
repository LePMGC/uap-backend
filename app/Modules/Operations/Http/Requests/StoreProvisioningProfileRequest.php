<?php

namespace App\Modules\Operations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProvisioningProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:100'
            ],

            'reimbursement_type' => [
                'required',
                Rule::in([
                    'AIRTIME',
                    'BUNDLE'
                ])
            ],

            /**
             * Required only for bundle provisioning.
             * Examples:
             * Data
             * Voice
             * Sms
             */
            'catalog_product_types' => [
                'nullable',
                'array',
                Rule::requiredIf(
                    $this->input('reimbursement_type') === 'BUNDLE'
                ),
            ],

            'catalog_product_types.*' => [
                'string',
                'max:255',
            ],

            'provider_instance_id' => [
                'required',
                'integer',
                'exists:provider_instances,id'
            ],

            'command_id' => [
                'nullable',
                'integer',
                'exists:commands,id'
            ],

            'debit_command_id' => [
                'nullable',
                'integer',
                'exists:commands,id'
            ],

            'execution_mode' => [
                'required',
                Rule::in([
                    'COMMAND',
                    'BATCH'
                ])
            ],

            'funding_account_id' => [
                'required',
                'integer',
                'exists:funding_accounts,id'
            ],

            'is_active' => [
                'boolean'
            ],
        ];
    }
}
