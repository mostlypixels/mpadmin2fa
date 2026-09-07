<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class MfaAlertTimezoneTest extends TestCase
{
    public function testSuccessfulChallengeReportsElapsedUtcTimeAcrossTimezonesAndDst(): void
    {
        $output = [];
        $status = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../Fixtures/mfa_alert_timezone.php') . ' 2>&1', $output, $status);
        self::assertSame(0, $status, implode("\n", $output));
        $results = json_decode(implode("\n", $output), true);
        self::assertCount(8, $results);
        foreach ($results as $result) {
            self::assertTrue($result['verified']);
            self::assertCount(1, $result['notifications']);
            $notification = $result['notifications'][0];
            self::assertSame('authentication.succeeded_after_failures', $notification['event']);
            self::assertSame(300, $notification['metadata']['elapsed_seconds'], $result['timezone']);
            self::assertSame(5, $notification['metadata']['failures']);
        }
    }
}
