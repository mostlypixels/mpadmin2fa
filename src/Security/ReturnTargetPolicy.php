<?php

declare(strict_types=1);

namespace Mpadmin2fa\Security;

final class ReturnTargetPolicy
{
    public function fromReferer(?string $referer, string $expectedHost, string $basePath): ?string
    {
        if (null === $referer || '' === $referer || $this->containsUnsafeCharacters($referer)) {
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

    private function containsUnsafeCharacters(string $value): bool
    {
        return 1 === preg_match('/[\\\\\x00-\x1F\x7F]/', $value)
            || 1 === preg_match('/%(?:25)*(?:5c|0[0-9a-f]|1[0-9a-f]|7f)/i', $value);
    }
}
