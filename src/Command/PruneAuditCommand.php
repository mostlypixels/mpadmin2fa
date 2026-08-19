<?php

declare(strict_types=1);

namespace Mpadmin2fa\Command;

use Mpadmin2fa\Repository\SecurityRepository;
use Mpadmin2fa\Security\Policy;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class PruneAuditCommand extends Command
{
    protected static $defaultName = 'mpadmin2fa:audit:prune';
    /** @var SecurityRepository */
    private $repository;

    /** @var Policy */
    private $policy;

    public function __construct(
        SecurityRepository $repository,
        Policy $policy)
    {
        $this->repository = $repository;
        $this->policy = $policy;
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $deleted = $this->repository->pruneAudit($this->policy->auditDays());
        $output->writeln(sprintf('<info>Deleted %d expired audit events.</info>', $deleted));

        return 0;
    }
}
