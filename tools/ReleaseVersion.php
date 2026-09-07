<?php

declare(strict_types=1);

namespace Mpadmin2faBuild;

use RuntimeException;

final class ReleaseVersion
{
    public static function moduleVersionForTag(string $tag): string
    {
        if (!preg_match('/^v(?<base>1\.[0-9]+\.[0-9]+)(?:-rc\.(?<rc>[1-9][0-9]*))?$/', $tag, $matches)) {
            throw new RuntimeException('PS1.7 tags must use v1.x.y or v1.x.y-rc.N.');
        }
        $version = $matches['base'] . (isset($matches['rc']) ? 'rc' . $matches['rc'] : '');
        if (strlen($version) > 8) {
            throw new RuntimeException('The module version exceeds PrestaShop 1.7 storage (8 characters).');
        }

        return $version;
    }

    public static function tagForModuleVersion(string $version): string
    {
        if (!preg_match('/^(?<base>1\.[0-9]+\.[0-9]+)(?:rc(?<rc>[1-9][0-9]*))?$/', $version, $matches)) {
            throw new RuntimeException('Invalid PS1.7 module release version.');
        }
        $tag = 'v' . $matches['base'] . (isset($matches['rc']) ? '-rc.' . $matches['rc'] : '');
        self::moduleVersionForTag($tag);

        return $tag;
    }
}
