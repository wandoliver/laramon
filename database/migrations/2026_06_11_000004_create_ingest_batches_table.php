<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingest_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instance_id')->constrained()->cascadeOnDelete();
            $table->uuid('batch_uuid');
            $table->unsignedInteger('bucket_count')->default(0);
            $table->timestamp('received_at');

            $table->unique(['instance_id', 'batch_uuid']);
            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingest_batches');
    }
};
