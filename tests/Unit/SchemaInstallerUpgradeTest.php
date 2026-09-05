<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Unit;

use Mpadmin2fa\Install\SchemaInstaller;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class SchemaInstallerUpgradeTest extends TestCase
{
    /**
     * @dataProvider migrationCases
     */
    public function testMigrationPreservesHistoryAndPropagatesFailures(
        bool $modern,
        bool $legacy,
        ?string $failure,
        bool $success,
        array $expectedWrites
    ): void {
        require dirname(__DIR__) . '/Fixtures/schema_database.php';
        define('_DB_PREFIX_', 'ps_');
        $database = new class($modern, $legacy, $failure) {
            public $writes = [];
            private $modern;
            private $legacy;
            private $failure;

            public function __construct(bool $modern, bool $legacy, ?string $failure)
            {
                $this->modern = $modern;
                $this->legacy = $legacy;
                $this->failure = $failure;
            }

            public function getValue(string $sql)
            {
                if (false !== strpos($sql, 'COLUMN_NAME = "last_failure_at"')) {
                    return $this->modern ? 'last_failure_at' : false;
                }

                return $this->legacy ? 'date_upd' : false;
            }

            public function execute(string $sql): bool
            {
                $this->writes[] = $sql;

                return null === $this->failure || false === strpos($sql, $this->failure);
            }
        };
        \Db::$instance = $database;

        self::assertSame($success, (new SchemaInstaller())->upgradeRateLimitTable());
        self::assertSame($expectedWrites, $database->writes);
    }

    public function migrationCases(): array
    {
        $add = 'ALTER TABLE ps_mp2fa_rate_limit ADD last_failure_at DATETIME NULL AFTER blocked_until';
        $copy = 'UPDATE ps_mp2fa_rate_limit SET last_failure_at = date_upd WHERE last_failure_at IS NULL';
        $drop = 'ALTER TABLE ps_mp2fa_rate_limit DROP COLUMN date_upd';

        return [
            'historical required timestamp' => [false, true, null, true, [$add, $copy, $drop]],
            'already migrated' => [true, false, null, true, []],
            'resume after column addition' => [true, true, null, true, [$copy, $drop]],
            'failed column addition' => [false, true, 'ADD', false, [$add]],
            'failed history copy keeps legacy column' => [true, true, 'UPDATE', false, [$copy]],
            'failed legacy cleanup reports failure' => [true, true, 'DROP', false, [$copy, $drop]],
        ];
    }
}
