<?php

declare(strict_types=1);

namespace Mpadmin2fa\Security;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use PragmaRX\Google2FA\Google2FA;

final class TotpService
{
    private readonly Google2FA $google2fa;

    public function __construct()
    {
        $this->google2fa = new Google2FA();
        $this->google2fa->setAlgorithm('sha1');
        $this->google2fa->setOneTimePasswordLength(6);
        $this->google2fa->setKeyRegeneration(30);
        $this->google2fa->setWindow(1);
    }

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    public function verifyNewer(string $secret, string $code, ?int $lastCounter, ?int $counter = null): int|false
    {
        if (1 !== preg_match('/^[0-9]{6}$/D', $code)) {
            return false;
        }

        $result = $this->google2fa->verifyKeyNewer($secret, $code, $lastCounter ?? -1, 1, $counter);

        return is_int($result) ? $result : false;
    }

    public function provisioningUri(string $issuer, string $account, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl($issuer, $account, $secret);
    }

    public function qrDataUri(string $provisioningUri): string
    {
        $renderer = new ImageRenderer(new RendererStyle(260, 4), new SvgImageBackEnd());
        $svg = (new Writer($renderer))->writeString($provisioningUri);

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
