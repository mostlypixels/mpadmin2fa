<?php

declare(strict_types=1);

namespace Mpadmin2fa\Form;

use Mpadmin2fa\Repository\SecurityRepository;

final class ProfileChoicesProvider
{
    public function __construct(
        private readonly SecurityRepository $repository,
        private readonly int $languageId,
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function getChoices(): array
    {
        return $this->repository->profileChoices($this->languageId);
    }
}
