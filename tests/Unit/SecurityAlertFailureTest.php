<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SecurityAlertFailureTest extends TestCase
{
    public function testFalseMailResultIsAuditedWithoutSensitiveDetails(): void
    {
        $result = $this->runFixture('returned_false');

        self::assertTrue($result['completed']);
        self::assertSame(1, $result['audit_attempts']);
        self::assertSame([$this->expectedAudit('send_returned_false')], $result['audits']);
    }

    public function testMailExceptionIsAuditedWithoutSensitiveDetails(): void
    {
        $result = $this->runFixture('exception');

        self::assertTrue($result['completed']);
        self::assertSame(1, $result['audit_attempts']);
        self::assertSame([$this->expectedAudit('exception')], $result['audits']);
        $encodedResult = (string) json_encode($result);
        self::assertFalse(false !== strpos($encodedResult, 'secret'));
        self::assertFalse(false !== strpos($encodedResult, 'recipient@example.test'));
    }

    public function testAuditFailureDoesNotEscapeTheAlertPath(): void
    {
        $result = $this->runFixture('audit_exception');

        self::assertTrue($result['completed']);
        self::assertSame(1, $result['audit_attempts']);
        self::assertSame([], $result['audits']);
    }

    /**
     * @return array<string, mixed>
     */
    private function runFixture(string $mode): array
    {
        $output = [];
        $status = 0;
        exec(
            escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg(__DIR__ . '/../Fixtures/security_alert_failure.php') . ' '
            . escapeshellarg($mode) . ' 2>&1',
            $output,
            $status
        );

        self::assertSame(0, $status, implode("\n", $output));
        $result = json_decode(implode("\n", $output), true);
        self::assertSame(JSON_ERROR_NONE, json_last_error(), implode("\n", $output));

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function expectedAudit(string $category): array
    {
        return [
            'employee_id' => 42,
            'event' => 'alert.delivery_failed',
            'ip' => null,
            'metadata' => [
                'original_event' => 'authentication.succeeded_after_failures',
                'failure_category' => $category,
            ],
        ];
    }
}
