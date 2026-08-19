<?php

declare(strict_types=1);

namespace Mpadmin2fa\Command;

use Mpadmin2fa\Repository\SecurityRepository;
use Mpadmin2fa\Security\MfaManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ResetFactorCommand extends Command
{
    protected static $defaultName = 'mpadmin2fa:factor:reset';
    public function __construct(
        private readonly SecurityRepository $repository,
        private readonly MfaManager $mfa,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Employee email address');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = (string) $input->getArgument('email');
        $employeeId = $this->repository->employeeIdByEmail($email);
        if (null === $employeeId) {
            $output->writeln('<error>Employee not found.</error>');

            return 1;
        }

        $this->mfa->reset($employeeId, null, null, 'cli-reset');
        $output->writeln('<info>Factor reset. Existing sessions will be gated on their next request.</info>');

        return 0;
    }
}
