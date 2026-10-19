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
            $table->string('webhook_url', 500)->nullable()->change();
            $table->json('email_recipients')->nullable();
        });

        Schema::table('alert_events', function (Blueprint $table) {
            $table->json('notification_status')->nullable();
        });
    }

    public function down(): void
    {
        // Rules created without Teams must remain valid when restoring the
        // previous non-nullable schema. Preserve every existing webhook.
        DB::table('alert_rules')->whereNull('webhook_url')->update(['webhook_url' => '']);

        Schema::table('alert_rules', function (Blueprint $table) {
            $table->string('webhook_url', 500)->nullable(false)->change();
            $table->dropColumn('email_recipients');
        });

        Schema::table('alert_events', function (Blueprint $table) {
            $table->dropColumn('notification_status');
        });
    }
};
