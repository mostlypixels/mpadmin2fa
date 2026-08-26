<?php

declare(strict_types=1);

namespace Mpadmin2fa\Form;

use Mpadmin2fa\Repository\SecurityRepository;

final class ProfileChoicesProvider
{

    /** @var SecurityRepository */
    private $repository;

    /** @var int */
    private $languageId;

    public function __construct(
        SecurityRepository $repository,
        int $languageId
    ) {
        $this->repository = $repository;
        $this->languageId = $languageId;
    }

    /**
     * @return array<string, int>
     */
    public function getChoices(): array
    {
        return $this->repository->profileChoices($this->languageId);
    }
}
