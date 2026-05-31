<?php

namespace Tests\Unit;

use App\Support\UniqueConstraintViolationDetector;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UniqueConstraintViolationDetectorTest extends TestCase
{
    #[DataProvider('uniqueConstraintViolations')]
    public function test_it_detects_unique_constraint_violations(QueryException $exception): void
    {
        $this->assertTrue(UniqueConstraintViolationDetector::detects($exception));
    }

    #[DataProvider('nonUniqueQueryExceptions')]
    public function test_it_rejects_non_unique_query_exceptions(QueryException $exception): void
    {
        $this->assertFalse(UniqueConstraintViolationDetector::detects($exception));
    }

    /**
     * @return iterable<string, array{QueryException}>
     */
    public static function uniqueConstraintViolations(): iterable
    {
        yield 'postgres unique violation' => [
            self::queryException(23505, 'SQLSTATE[23505]: unique_violation'),
        ];

        yield 'message mentions unique' => [
            self::queryException(0, 'UNIQUE constraint failed: proxy_servers.identity_hash'),
        ];

        yield 'mysql duplicate entry' => [
            self::queryException(
                23000,
                "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'hash' for key 'proxy_servers_identity_hash_unique'",
            ),
        ];
    }

    /**
     * @return iterable<string, array{QueryException}>
     */
    public static function nonUniqueQueryExceptions(): iterable
    {
        yield 'syntax error' => [
            self::queryException(42000, 'SQLSTATE[42000]: syntax error'),
        ];

        yield 'generic mysql integrity constraint' => [
            self::queryException(23000, 'SQLSTATE[23000]: Integrity constraint violation'),
        ];
    }

    private static function queryException(int $code, string $message): QueryException
    {
        return new QueryException('testing', 'insert into proxy_servers', [], new PDOException($message, $code));
    }
}
