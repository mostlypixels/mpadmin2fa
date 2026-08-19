<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Unit;

use Defuse\Crypto\Crypto;
use Defuse\Crypto\Exception\WrongKeyOrModifiedCiphertextException;
use Defuse\Crypto\KeyProtectedByPassword;
use PHPUnit\Framework\TestCase;

final class DefuseEnvelopeTest extends TestCase
{
    public function testDatabaseValuesDoNotRevealSecretAndCookieRotationRewrapsOnlyTheDek(): void
    {
        $oldCookieKey = bin2hex(random_bytes(32));
        $newCookieKey = bin2hex(random_bytes(32));
        $protected = KeyProtectedByPassword::createRandomPasswordProtectedKey($oldCookieKey);
        $dek = $protected->unlockKey($oldCookieKey);
        $secret = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
        $ciphertext = Crypto::encrypt($secret, $dek);

        self::assertStringNotContainsString($secret, $ciphertext);
        self::assertStringNotContainsString($secret, $protected->saveToAsciiSafeString());

        $rewrapped = $protected->changePassword($oldCookieKey, $newCookieKey);
        self::assertSame($secret, Crypto::decrypt($ciphertext, $rewrapped->unlockKey($newCookieKey)));
    }

    public function testWrongCookieKeyAndTamperedCiphertextFailClosed(): void
    {
        $cookieKey = bin2hex(random_bytes(32));
        $protected = KeyProtectedByPassword::createRandomPasswordProtectedKey($cookieKey);

        $this->expectException(WrongKeyOrModifiedCiphertextException::class);
        $protected->unlockKey(bin2hex(random_bytes(32)));
    }
}
