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
        Schema::table('proxy_servers', function (Blueprint $table): void {
            $table->string('check_generation', 36)->nullable()->after('checking_started_at');
            $table->string('check_source', 16)->nullable()->after('check_generation');
            $table->string('check_job_token', 36)->nullable()->after('check_source');
            $table->string('check_job_source', 16)->nullable()->after('check_job_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('proxy_servers', function (Blueprint $table): void {
            $table->dropColumn(['check_generation', 'check_source', 'check_job_token', 'check_job_source']);
        });
    }
};
