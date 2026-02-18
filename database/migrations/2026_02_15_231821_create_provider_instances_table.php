<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('provider_instances', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // e.g., "Charging System Primary"
            
            // This slug links to our Backend Blueprints (ericsson-ucip, ericsson-cai)
            $table->string('category_slug')->index(); 
            
            // Encrypted JSON to store IP, Port, Username, Password, User-Agent
            $table->text('connection_settings'); 
            
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_instances');
    }
};