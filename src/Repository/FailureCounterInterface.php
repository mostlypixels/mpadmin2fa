<?php

declare(strict_types=1);

namespace Mpadmin2fa\Repository;

interface FailureCounterInterface
{
    public function incrementFailure(string $scope, string $subjectHash): int;

    public function rateLimit(string $scope, string $subjectHash): ?array;

    public function clearFailures(string $scope, string $subjectHash): void;
}
