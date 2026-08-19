<?php

declare(strict_types=1);

namespace Mpadmin2fa\Security;

final class ReturnTargetPolicy
{
    public function fromReferer(?string $referer, string $expectedHost, string $basePath): ?string
    {
        if (null === $referer || '' === $referer) {
            return null;
        }

        $parts = parse_url($referer);
        if (!is_array($parts) || ($parts['host'] ?? null) !== $expectedHost) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '');
        if (0 !== strpos($path, '/')
            || 0 === strpos($path, '//')
            || ('' !== $basePath && $path !== $basePath && 0 !== strpos($path, $basePath . '/'))
        ) {
            return null;
        }

        return $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
}
