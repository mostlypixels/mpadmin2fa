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
        if (!str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || ('' !== $basePath && $path !== $basePath && !str_starts_with($path, $basePath . '/'))
        ) {
            return null;
        }

        return $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
}
