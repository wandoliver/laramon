<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('instance_id')->constrained()->cascadeOnDelete();
            $table->decimal('value', 14, 2);
            $table->timestamp('triggered_at');
            $table->timestamp('resolved_at')->nullable();
            $table->boolean('notified')->default(false);

            $table->index(['alert_rule_id', 'instance_id', 'resolved_at']);
            $table->index(['instance_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_events');
    }
};
