<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CONSTRAINT = 'proxy_checks_status_terminal_check';

    public function up(): void
    {
        if ($this->driver() === 'sqlite') {
            $this->rebuildSqliteTable(withConstraint: true);

            return;
        }

        DB::statement($this->addConstraintSql());
    }

    public function down(): void
    {
        if ($this->driver() === 'sqlite') {
            $this->rebuildSqliteTable(withConstraint: false);

            return;
        }

        DB::statement($this->dropConstraintSql());
    }

    private function driver(): string
    {
        return DB::connection()->getDriverName();
    }

    private function addConstraintSql(): string
    {
        return match ($this->driver()) {
            'mysql', 'mariadb' => 'ALTER TABLE `proxy_checks` ADD CONSTRAINT `'.self::CONSTRAINT."` CHECK (`status` IN ('online', 'offline'))",
            'pgsql' => 'ALTER TABLE "proxy_checks" ADD CONSTRAINT "'.self::CONSTRAINT."\" CHECK (\"status\" IN ('online', 'offline'))",
            'sqlsrv' => 'ALTER TABLE [proxy_checks] ADD CONSTRAINT ['.self::CONSTRAINT."] CHECK ([status] IN ('online', 'offline'))",
            default => throw new RuntimeException("Unsupported database driver [{$this->driver()}]."),
        };
    }

    private function dropConstraintSql(): string
    {
        return match ($this->driver()) {
            'mysql' => 'ALTER TABLE `proxy_checks` DROP CHECK `'.self::CONSTRAINT.'`',
            'mariadb' => 'ALTER TABLE `proxy_checks` DROP CONSTRAINT `'.self::CONSTRAINT.'`',
            'pgsql' => 'ALTER TABLE "proxy_checks" DROP CONSTRAINT IF EXISTS "'.self::CONSTRAINT.'"',
            'sqlsrv' => 'ALTER TABLE [proxy_checks] DROP CONSTRAINT ['.self::CONSTRAINT.']',
            default => throw new RuntimeException("Unsupported database driver [{$this->driver()}]."),
        };
    }

    private function rebuildSqliteTable(bool $withConstraint): void
    {
        $statusColumn = $withConstraint
            ? '"status" varchar(20) not null constraint "'.self::CONSTRAINT.'" check ("status" in (\'online\', \'offline\'))'
            : '"status" varchar(20) not null';

        DB::statement('PRAGMA foreign_keys = OFF');

        try {
            DB::transaction(function () use ($statusColumn): void {
                DB::statement(<<<SQL
CREATE TABLE "proxy_checks_new" (
    "id" integer primary key autoincrement not null,
    "proxy_server_id" integer not null,
    "source" varchar(20) not null,
    {$statusColumn},
    "started_at" datetime not null,
    "finished_at" datetime not null,
    "response_time_ms" integer,
    "http_status" integer,
    "error_code" varchar(64),
    "error_message" text,
    "created_at" datetime,
    "updated_at" datetime,
    foreign key("proxy_server_id") references "proxy_servers"("id") on delete cascade
)
SQL);

                DB::statement(<<<'SQL'
INSERT INTO "proxy_checks_new" (
    "id",
    "proxy_server_id",
    "source",
    "status",
    "started_at",
    "finished_at",
    "response_time_ms",
    "http_status",
    "error_code",
    "error_message",
    "created_at",
    "updated_at"
)
SELECT
    "id",
    "proxy_server_id",
    "source",
    "status",
    "started_at",
    "finished_at",
    "response_time_ms",
    "http_status",
    "error_code",
    "error_message",
    "created_at",
    "updated_at"
FROM "proxy_checks"
SQL);

                DB::statement('DROP TABLE "proxy_checks"');
                DB::statement('ALTER TABLE "proxy_checks_new" RENAME TO "proxy_checks"');
                DB::statement('CREATE INDEX "proxy_checks_proxy_server_id_created_at_index" ON "proxy_checks" ("proxy_server_id", "created_at")');
                DB::statement('CREATE INDEX "proxy_checks_status_index" ON "proxy_checks" ("status")');
                DB::statement('CREATE INDEX "proxy_checks_source_index" ON "proxy_checks" ("source")');
            });
        } finally {
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }
};
