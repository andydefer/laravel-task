<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_execution_debugs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('alias');
            $table->string('fqcn');
            $table->string('status')->default('failed');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->json('data')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('alias');
            $table->index('fqcn');
            $table->index('status');
            $table->index('started_at');
            $table->index('ended_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_execution_debugs');
    }
};
