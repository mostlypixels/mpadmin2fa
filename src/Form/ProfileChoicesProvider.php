<?php

declare(strict_types=1);

namespace Mpadmin2fa\Form;

use Mpadmin2fa\Repository\SecurityRepository;
use PrestaShop\PrestaShop\Core\Context\LanguageContext;

final class ProfileChoicesProvider
{
    public function __construct(
        private readonly SecurityRepository $repository,
        private readonly LanguageContext $languageContext,
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function getChoices(): array
    {
        return $this->repository->profileChoices($this->languageContext->getId());
    }
}
