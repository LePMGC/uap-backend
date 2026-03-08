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
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
            $payload = json_encode($this->logData);
            Log::channel('uap')->log($this->level, $payload);
            broadcast(new \App\Events\AuditLogReceived($this->logData))->toOthers();
        } catch (Throwable $e) {
            // Fallback to default laravel log if the uap channel fails
            Log::error("ASYNC_LOGGER_WRITE_FAILURE", [
                'reason' => $e->getMessage(),
                'original_data' => $this->logData
            ]);
        }
    }
}