<?php

declare(strict_types=1);

namespace Mpadmin2fa\Security;

use DateTimeImmutable;

final class DashboardActivityWindow
{
    public function hours(DateTimeImmutable $now): int
    {
        return 1 === (int) $now->format('N') ? 96 : 48;
    }

    public function since(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->modify(sprintf('-%d hours', $this->hours($now)));
    }
}
