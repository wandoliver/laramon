<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exception_groups', function (Blueprint $table) {
            $table->timestamp('resolved_at')->nullable()->after('total_count');
            $table->foreignId('resolved_by_user_id')->nullable()->after('resolved_at')->constrained('users')->nullOnDelete();
            $table->text('resolved_comment')->nullable()->after('resolved_by_user_id');

            $table->index(['instance_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::table('exception_groups', function (Blueprint $table) {
            $table->dropIndex(['instance_id', 'resolved_at']);
            $table->dropConstrainedForeignId('resolved_by_user_id');
            $table->dropColumn(['resolved_at', 'resolved_comment']);
        });
    }
};
