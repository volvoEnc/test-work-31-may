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
        Schema::create('proxy_servers', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120)->nullable();
            $table->string('scheme', 10);
            $table->string('host', 255);
            $table->unsignedSmallInteger('port');
            $table->string('username', 255)->nullable();
            $table->text('password')->nullable();
            $table->char('identity_hash', 64)->unique();
            $table->string('status', 20)->default('unknown');
            $table->timestamp('checking_started_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['status', 'last_checked_at']);
            $table->index('host');
            $table->index('scheme');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('proxy_servers');
    }
};
