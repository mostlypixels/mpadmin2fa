<?php

declare(strict_types=1);

namespace Mpadmin2fa\Security;

use Mpadmin2fa\Exception\MfaSecurityException;

final class CookieKeyProvider
{
    public function current(): string
    {
        if (!defined('_NEW_COOKIE_KEY_') || !is_string(_NEW_COOKIE_KEY_) || strlen(_NEW_COOKIE_KEY_) < 32) {
            throw new MfaSecurityException('_NEW_COOKIE_KEY_ is missing or too short.');
        }

        return _NEW_COOKIE_KEY_;
    }

    public function fingerprint(string $cookieKey): string
    {
        return hash('sha256', 'mpadmin2fa-cookie-key-v1' . $cookieKey);
    }
}
