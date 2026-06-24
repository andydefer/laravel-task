<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('alias')->unique();
            $table->string('fqcn');
            $table->json('payload');
            $table->integer('interval_seconds');
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->string('status')->default('waiting');
            $table->timestamp('last_run_at')->nullable();
            $table->integer('failed_attempts')->default(0);
            $table->integer('max_failed_attempts')->default(3);
            $table->json('debug')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_tasks');
    }
};
