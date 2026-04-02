<?php

namespace App\Listeners;

use App\Events\AuditLogReceived;
use Illuminate\Support\Facades\Log;

class StoreAuditLog
{
    public function handle(AuditLogReceived $event): void
    {
        // We wrap the data in JSON so your LogParserService (attached)
        // can correctly decode it using its regex.
        Log::channel('uap')->log(
            $event->level,
            json_encode($event->logData)
        );
    }
}
