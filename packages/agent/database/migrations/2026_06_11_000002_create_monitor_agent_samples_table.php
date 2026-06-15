<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('monitor_agent_samples')) {
            Schema::create('monitor_agent_samples', function (Blueprint $table) {
                $table->id();
                $table->string('kind', 30);
                $table->string('fingerprint', 32);
                $table->text('payload');
                $table->unsignedInteger('occurred_at');

                // Latest occurrence per fingerprint; the upsert keeps the
                // table tiny between exports.
                $table->unique(['kind', 'fingerprint']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('monitor_agent_samples')) {
            Schema::dropIfExists('monitor_agent_samples');
        }
    }
};
