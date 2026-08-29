<?php

declare(strict_types=1);

namespace Mpadmin2fa\Exception;

use RuntimeException;

final class EnrollmentApprovalDenied extends RuntimeException
{
    /** @var string */
    private $reason;

    public function __construct(string $reason, string $message)
    {
        parent::__construct($message);
        $this->reason = $reason;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
