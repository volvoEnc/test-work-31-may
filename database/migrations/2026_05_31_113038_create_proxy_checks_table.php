<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement("
                CREATE TABLE proxy_checks (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    proxy_server_id INTEGER NOT NULL,
                    source VARCHAR(20) NOT NULL,
                    status VARCHAR(20) NOT NULL,
                    started_at DATETIME NOT NULL,
                    finished_at DATETIME NOT NULL,
                    response_time_ms INTEGER,
                    http_status INTEGER,
                    error_code VARCHAR(64),
                    error_message TEXT,
                    created_at DATETIME,
                    updated_at DATETIME,
                    CONSTRAINT proxy_checks_status_check CHECK (status IN ('online', 'offline')),
                    FOREIGN KEY (proxy_server_id) REFERENCES proxy_servers(id) ON DELETE CASCADE
                )
            ");

            DB::statement('CREATE INDEX proxy_checks_proxy_server_id_created_at_index ON proxy_checks (proxy_server_id, created_at)');
            DB::statement('CREATE INDEX proxy_checks_status_index ON proxy_checks (status)');
            DB::statement('CREATE INDEX proxy_checks_source_index ON proxy_checks (source)');

            return;
        }

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

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'pgsql'], true)) {
            DB::statement("ALTER TABLE proxy_checks ADD CONSTRAINT proxy_checks_status_check CHECK (status IN ('online', 'offline'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('proxy_checks');
    }
};
