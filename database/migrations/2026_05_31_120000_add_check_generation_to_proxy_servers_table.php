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
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('proxy_servers', function (Blueprint $table): void {
            $table->dropColumn('check_generation');
        });
    }
};
