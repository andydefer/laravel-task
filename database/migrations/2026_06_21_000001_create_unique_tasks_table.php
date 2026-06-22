<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unique_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('alias');
            $table->string('fqcn');
            $table->json('payload');
            $table->timestamp('scheduled_at');
            $table->integer('grace_period_seconds')->default(86400);
            $table->string('status')->default('pending');
            $table->integer('attempts')->default(0);
            $table->integer('max_attempts')->default(3);
            $table->json('debug')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unique_tasks');
    }
};
