<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instance_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('metric_type', 60);
            $table->string('key', 500)->nullable();
            $table->string('aggregate', 10)->default('count');
            $table->string('operator', 2)->default('>');
            $table->decimal('threshold', 14, 2);
            $table->unsignedSmallInteger('window_minutes')->default(15);
            $table->unsignedSmallInteger('cooldown_minutes')->default(30);
            $table->string('webhook_url', 500);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_rules');
    }
};
