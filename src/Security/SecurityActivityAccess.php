<?php

declare(strict_types=1);

namespace Mpadmin2fa\Security;

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class SecurityActivityAccess
{

    /** @var AuthorizationCheckerInterface */
    private $authorizationChecker;

    public function __construct(
        AuthorizationCheckerInterface $authorizationChecker
    ) {
        $this->authorizationChecker = $authorizationChecker;
    }

    public function canRead(): bool
    {
        return $this->authorizationChecker->isGranted('read', 'AdminMpAdmin2faSecurityActivity');
    }
}
