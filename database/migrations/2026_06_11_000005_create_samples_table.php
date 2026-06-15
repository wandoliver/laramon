<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instance_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 30);
            $table->string('fingerprint', 32);
            $table->json('payload');
            $table->timestamp('occurred_at');

            $table->index(['instance_id', 'kind', 'fingerprint', 'occurred_at'], 'sample_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('samples');
    }
};
