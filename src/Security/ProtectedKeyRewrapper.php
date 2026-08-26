<?php

declare(strict_types=1);

namespace Mpadmin2fa\Security;

use Defuse\Crypto\Core;
use Defuse\Crypto\Crypto;
use Defuse\Crypto\Encoding;
use Defuse\Crypto\KeyProtectedByPassword;

final class ProtectedKeyRewrapper
{
    public function rewrap(
        KeyProtectedByPassword $protectedKey,
        string $currentPassword,
        string $newPassword
    ): KeyProtectedByPassword {
        $innerKey = $protectedKey->unlockKey($currentPassword);
        $encryptedKey = Crypto::encryptWithPassword(
            $innerKey->saveToAsciiSafeString(),
            hash(Core::HASH_FUNCTION_NAME, $newPassword, true),
            true
        );

        return KeyProtectedByPassword::loadFromAsciiSafeString(
            Encoding::saveBytesToChecksummedAsciiSafeString(
                KeyProtectedByPassword::PASSWORD_KEY_CURRENT_VERSION,
                $encryptedKey
            )
        );
    }
}
