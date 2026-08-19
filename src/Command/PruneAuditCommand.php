<?php

declare(strict_types=1);

namespace Mpadmin2fa\Command;

use Mpadmin2fa\Repository\SecurityRepository;
use Mpadmin2fa\Security\Policy;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mpadmin2fa:audit:prune', description: 'Delete MFA audit events older than the configured retention.')]
final class PruneAuditCommand extends Command
{
    public function __construct(
        private readonly SecurityRepository $repository,
        private readonly Policy $policy,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $deleted = $this->repository->pruneAudit($this->policy->auditDays());
        $output->writeln(sprintf('<info>Deleted %d expired audit events.</info>', $deleted));

        return Command::SUCCESS;
    }
}
