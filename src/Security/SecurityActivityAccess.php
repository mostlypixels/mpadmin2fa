<?php

declare(strict_types=1);

namespace Mpadmin2fa\Security;

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class SecurityActivityAccess
{
    public function __construct(
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    public function canRead(): bool
    {
        return $this->authorizationChecker->isGranted('read', 'AdminMpAdmin2faSecurityActivity');
    }
}
