<?php

namespace App\Modules\Core\Auditing\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class AsyncUapLoggerJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    protected $logData;
    protected $level;

    /**
     * Create a new job instance.
     */
    public function __construct(array $logData, string $level)
    {
        $this->logData = $logData;
        $this->level = $level;

        // Ensure this job goes to a specific 'logging' queue to not block business logic
        $this->onQueue('logging');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            // 1. Write to the file channel (uap)
            $payload = json_encode($this->logData);
            Log::channel('uap')->log($this->level, $payload);

            // 2. Optional: Broadcast to Frontend via Echo/Pusher
            // Only if the class exists to avoid the "Class not found" crash
            if (class_exists(\App\Events\AuditLogReceived::class)) {
                broadcast(new \App\Events\AuditLogReceived($this->logData))->toOthers();
            }
        } catch (Throwable $e) {
            Log::channel('single')->error("ASYNC_LOGGER_WRITE_FAILURE", [
                'reason' => $e->getMessage(),
                'original_data' => $this->logData
            ]);
        }
    }
}
