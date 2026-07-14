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
                'max:100',
            ],

            'reimbursement_type' => [
                'required',
                Rule::in([
                    'AIRTIME',
                    'BUNDLE',
                ]),
            ],

            /**
             * Required only for bundle provisioning.
             * Examples:
             * - DATA
             * - VOICE
             * - SMS
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

            /*
             * Provisioning execution pipeline.
             */
            'provisioning_provider_instance_id' => [
                'required',
                'integer',
                'exists:provider_instances,id',
            ],

            'provisioning_command_id' => [
                'nullable',
                'integer',
                'exists:commands,id',
            ],

            /*
             * Debit execution pipeline.
             */
            'debit_using_provisioning_provider' => [
                'boolean',
            ],

            'debit_provider_instance_id' => [
                Rule::requiredIf(
                    ! $this->boolean('debit_using_provisioning_provider')
                ),
                'nullable',
                'integer',
                'exists:provider_instances,id',
            ],

            'debit_command_id' => [
                'nullable',
                'integer',
                'exists:commands,id',
            ],

            'execution_mode' => [
                'required',
                Rule::in([
                    'COMMAND',
                    'BATCH',
                ]),
            ],

            'funding_account_id' => [
                'required',
                'integer',
                'exists:funding_accounts,id',
            ],

            'is_active' => [
                'boolean',
            ],
        ];
    }
}
