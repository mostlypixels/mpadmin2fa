<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class FactorConfirmationThrottlingTest extends TestCase
{
    public function testPasswordFailuresShareTheFactorChangeBudgetAndAreAudited(): void
    {
        $output = [];
        $status = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../Fixtures/factor_confirmation.php') . ' 2>&1', $output, $status);
        self::assertSame(0, $status, implode("\n", $output));
        $result = json_decode(implode("\n", $output), true);
        self::assertSame([false, false, false, false, false], $result['password_failures']);
        self::assertSame([true, true], $result['blocked']);
        self::assertSame([5, 0], $result['checks_before_reset']);
        self::assertCount(5, $result['password_audits']);
        foreach ($result['password_audits'] as $event) {
            self::assertSame(['employee_id' => 42, 'event' => 'factor_change.password_failed', 'ip' => '127.0.0.1', 'metadata' => []], $event);
        }
        self::assertSame(['authentication.repeated_failures'], $result['alerts']);
        self::assertFalse($result['wrong_totp']);
        self::assertSame([1, 1], $result['counts_after_wrong_totp']);
        self::assertTrue($result['both_valid']);
        self::assertSame([], $result['counts_after_success']);
        self::assertTrue($result['fresh_session']);
        self::assertSame(0, $result['fresh_password_checks']);
    }
}
