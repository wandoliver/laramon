<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('triggered_subject', 200);
            $table->text('triggered_body');
            $table->string('resolved_subject', 200);
            $table->text('resolved_body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_email_templates');
    }
};
