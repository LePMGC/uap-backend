<?php

namespace App\Modules\Operations\Jobs;

use App\Modules\Operations\Models\ProvisioningRequest;
use App\Modules\Operations\Services\ProvisioningService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;
use Illuminate\Support\Facades\Log;

class ProcessProvisioningRequestJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;

    public function __construct(
        public string $provisioningRequestId
    ) {
    }

    public function handle(ProvisioningService $service): void
    {
        $request = ProvisioningRequest::with(['reimbursement', 'profile'])
            ->findOrFail($this->provisioningRequestId);

        // Idempotency engine verification guard
        if (in_array($request->status, ['SUCCESS', 'FAILED', 'RUNNING'])) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Proactive Status Escalation Update (Prevents lingering PENDING state)
        |--------------------------------------------------------------------------
        */
        $request->update([
            'status'     => 'RUNNING',
            'started_at' => now()
        ]);

        try {
            $service->executeProvisioningRequest($request);

        } catch (Throwable $e) {
            Log::error(
                'Provisioning request queue execution failed',
                [
                    'request_id' => $request->id,
                    'error'      => $e->getMessage()
                ]
            );

            $request->update([
                'status'        => 'FAILED',
                'error_message' => $e->getMessage(),
                'completed_at'  => now()
            ]);

            if ($request->reimbursement) {
                $request->reimbursement->update(['provisioning_status' => 'FAILED']);
            }

            throw $e;
        }
    }
}
