<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Table for the main Command Blueprint
        Schema::create('commands', function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->string('category_slug'); // e.g., 'ericsson-ucip'
            $blueprint->string('name');          // e.g., 'Get Accumulators'
            $blueprint->string('command_key');   // e.g., 'GetAccumulators'
            $blueprint->string('action')->default('view'); // view, update, etc.
            $blueprint->text('description')->nullable();
            
            // For technical users: The raw XML/JSON/Text template
            $blueprint->text('payload_template')->nullable(); 
            
            // System params like originHostName (stored as JSON)
            $blueprint->json('system_params')->nullable(); 
            
            // Ownership & Flexibility
            $blueprint->boolean('is_custom')->default(false); // true if user-created
            $blueprint->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            
            $blueprint->timestamps();
            
            // Index for faster lookups by category
            $blueprint->index(['category_slug', 'command_key']);
        });

        // 2. Table for the Form/Validation "Specks"
        Schema::create('command_parameters', function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->foreignId('command_id')->constrained('commands')->onDelete('cascade');
            
            // If the parameter is nested (like your 'struct' types), this points to the parent
            $blueprint->foreignId('parent_id')->nullable()->constrained('command_parameters')->onDelete('cascade');
            
            $blueprint->string('name');        // e.g., 'subscriberNumber'
            $blueprint->string('label');       // e.g., 'Subscriber Number (MSISDN)'
            $blueprint->string('type');        // string, int, boolean, struct, date
            $blueprint->boolean('is_mandatory')->default(false);
            $blueprint->string('default_value')->nullable();
            
            // JSON to hold complex validation (regex, min/max, conditional rules)
            $blueprint->json('validation_rules')->nullable(); 
            
            $blueprint->integer('sort_order')->default(0); // To keep form fields in order
            $blueprint->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('command_parameters');
        Schema::dropIfExists('commands');
    }
};