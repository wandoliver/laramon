<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('monitor_agent_state')) {
            Schema::create('monitor_agent_state', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->string('value');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('monitor_agent_state')) {
            Schema::dropIfExists('monitor_agent_state');
        }
    }
};
