<?php

namespace App\Modules\Operations\Observers;

use App\Modules\Operations\Models\ProvisioningRequest;

class ProvisioningRequestObserver
{
    /**
     * Handle the ProvisioningRequest "saved" or "updated" event.
     */
    public function saved(ProvisioningRequest $request): void
    {
        $reimbursement = $request->reimbursement;

        if (!$reimbursement) {
            return;
        }

        // Map the provisioning request's technical state to the user-facing status
        $newStatus = match ($request->status) {
            'ready' => 'pending_provisioning',
            'running' => 'provisioning_ongoing',
            'sucess' => 'fully_provisioned',
            'failed'  => 'provisioning_failed',
            default   => $reimbursement->status,
        };

        // Only save if the status is actually changing to prevent infinite database loops
        if ($reimbursement->status !== $newStatus) {
            $reimbursement->update(['status' => $newStatus]);
        }
    }
}
