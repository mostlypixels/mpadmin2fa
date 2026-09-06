<?php

declare(strict_types=1);

namespace Mpadmin2faBuild;

use RuntimeException;

final class ReleaseVersion
{
    public static function moduleVersionForTag(string $tag): string
    {
        if (!preg_match('/^v(?<base>2\.[0-9]+\.[0-9]+)(?:-rc\.(?<rc>[1-9][0-9]*))?$/', $tag, $matches)) {
            throw new RuntimeException('PS8 tags must use v2.x.y or v2.x.y-rc.N.');
        }
        $version = $matches['base'] . (isset($matches['rc']) ? 'rc' . $matches['rc'] : '');
        if (strlen($version) > 8) {
            throw new RuntimeException('The module version exceeds PrestaShop 8 storage (8 characters).');
        }

        return $version;
    }

    public static function tagForModuleVersion(string $version): string
    {
        if (!preg_match('/^(?<base>2\.[0-9]+\.[0-9]+)(?:rc(?<rc>[1-9][0-9]*))?$/', $version, $matches)) {
            throw new RuntimeException('Invalid PS8 module release version.');
        }
        $tag = 'v' . $matches['base'] . (isset($matches['rc']) ? '-rc.' . $matches['rc'] : '');
        self::moduleVersionForTag($tag);

        return $tag;
    }
}
