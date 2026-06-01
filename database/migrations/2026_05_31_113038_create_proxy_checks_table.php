<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('proxy_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('proxy_server_id')
                ->constrained('proxy_servers')
                ->cascadeOnDelete();
            $table->string('source', 20);
            $table->string('status', 20);
            $table->timestamp('started_at');
            $table->timestamp('finished_at');
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['proxy_server_id', 'created_at']);
            $table->index('status');
            $table->index('source');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('proxy_checks');
    }
};
