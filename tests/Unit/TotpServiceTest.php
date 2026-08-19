<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Unit;

use Mpadmin2fa\Security\TotpService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PragmaRX\Google2FA\Google2FA;

final class TotpServiceTest extends TestCase
{
    private TotpService $service;

    protected function setUp(): void
    {
        $this->service = new TotpService();
    }

    #[DataProvider('vectors')]
    public function testRfc6238Vectors(int $unixTime, string $expected): void
    {
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $counter = intdiv($unixTime, 30);

        self::assertSame($counter, $this->service->verifyNewer($secret, $expected, null, $counter));
        self::assertFalse($this->service->verifyNewer($secret, $expected, $counter, $counter));
    }

    public static function vectors(): iterable
    {
        yield [59, '287082'];
        yield [1111111109, '081804'];
        yield [1111111111, '050471'];
        yield [1234567890, '005924'];
        yield [2000000000, '279037'];
        yield [20000000000, '353130'];
    }

    public function testWindowAndInputValidation(): void
    {
        $google = new Google2FA();
        $secret = $google->generateSecretKey(32);
        $current = $google->getTimestamp();
        $previousCode = $google->oathTotp($secret, $current - 1);

        self::assertSame($current - 1, $this->service->verifyNewer($secret, $previousCode, null, $current));
        self::assertFalse($this->service->verifyNewer($secret, '12345x', null, $current));
        self::assertFalse($this->service->verifyNewer($secret, $google->oathTotp($secret, $current - 2), null, $current));
    }

    public function testQrCodeIsGeneratedLocally(): void
    {
        $uri = $this->service->provisioningUri('Test Shop', 'admin@example.test', str_repeat('A', 32));
        $dataUri = $this->service->qrDataUri($uri);

        self::assertStringStartsWith('otpauth://totp/', $uri);
        self::assertStringStartsWith('data:image/svg+xml;base64,', $dataUri);
        self::assertStringContainsString('<svg', base64_decode(substr($dataUri, strlen('data:image/svg+xml;base64,')), true));
    }
}
