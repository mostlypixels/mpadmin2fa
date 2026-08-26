<?php

declare(strict_types=1);

namespace Mpadmin2fa\Security;

final class ProfilePolicyCleaner
{
    public static function removeFromList(string $profileIds, int $deletedProfileId): string
    {
        $ids = array_unique(array_filter(
            array_map('intval', explode(',', $profileIds)),
            static function (int $profileId) use ($deletedProfileId): bool { return $profileId > 0 && $profileId !== $deletedProfileId; }
        ));

        return implode(',', $ids);
    }

    private function __construct()
    {
    }
}
