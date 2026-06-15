<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metric_buckets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instance_id')->constrained()->cascadeOnDelete();
            $table->string('type', 60);
            $table->string('key', 500);
            $table->binary('key_hash', 16);
            $table->unsignedInteger('bucket_start');
            $table->unsignedSmallInteger('bucket_seconds');
            $table->unsignedBigInteger('count')->default(0);
            $table->decimal('sum', 20, 2)->nullable();
            $table->decimal('min', 14, 2)->nullable();
            $table->decimal('max', 14, 2)->nullable();

            $table->unique(
                ['instance_id', 'type', 'key_hash', 'bucket_seconds', 'bucket_start'],
                'metric_bucket_identity'
            );
            $table->index(
                ['instance_id', 'type', 'bucket_seconds', 'bucket_start'],
                'metric_bucket_lookup'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_buckets');
    }
};
