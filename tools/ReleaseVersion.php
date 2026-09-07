<?php

declare(strict_types=1);

namespace Mpadmin2faBuild;

use RuntimeException;

final class ReleaseVersion
{
    public static function moduleVersionForTag(string $tag): string
    {
        if (!preg_match('/^v(?<base>3\.[0-9]+\.[0-9]+)(?:-rc\.(?<rc>[1-9][0-9]*))?$/', $tag, $matches)) {
            throw new RuntimeException('PS9 tags must use v3.x.y or v3.x.y-rc.N.');
        }

        return $matches['base'] . (isset($matches['rc']) ? 'rc' . $matches['rc'] : '');
    }

    public static function tagForModuleVersion(string $version): string
    {
        if (!preg_match('/^(?<base>3\.[0-9]+\.[0-9]+)(?:rc(?<rc>[1-9][0-9]*))?$/', $version, $matches)) {
            throw new RuntimeException('Invalid PS9 module release version.');
        }

        return 'v' . $matches['base'] . (isset($matches['rc']) ? '-rc.' . $matches['rc'] : '');
    }
}
