<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_rules', function (Blueprint $table) {
            $table->unsignedSmallInteger('trigger_delay_minutes')->default(10);
            $table->unsignedSmallInteger('recovery_delay_minutes')->default(5);
        });

        Schema::table('alert_events', function (Blueprint $table) {
            $table->timestamp('breach_started_at')->nullable();
            $table->timestamp('recovery_started_at')->nullable();
            $table->timestamp('last_evaluated_at')->nullable();
            $table->timestamp('notification_attempted_at')->nullable();
            $table->json('notification_targets')->nullable();
        });

        // Historical incidents have already passed through notification handling.
        // Mark every one as attempted, including failed/internal-only incidents,
        // so deploying delays never replays an old alarm.
        DB::table('alert_events')->update(['notification_attempted_at' => DB::raw('triggered_at')]);
    }

    public function down(): void
    {
        Schema::table('alert_events', function (Blueprint $table) {
            $table->dropColumn([
                'breach_started_at',
                'recovery_started_at',
                'last_evaluated_at',
                'notification_attempted_at',
                'notification_targets',
            ]);
        });

        Schema::table('alert_rules', function (Blueprint $table) {
            $table->dropColumn(['trigger_delay_minutes', 'recovery_delay_minutes']);
        });
    }
};
