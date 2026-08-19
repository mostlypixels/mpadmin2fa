<?php

declare(strict_types=1);

namespace Mpadmin2fa\Command;

use Mpadmin2fa\Security\KeyManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mpadmin2fa:key:rotate', description: 'Prepare or commit a two-phase _NEW_COOKIE_KEY_ rotation.')]
final class KeyRotationCommand extends Command
{
    public function __construct(private readonly KeyManager $keys)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('phase', InputArgument::REQUIRED, 'prepare or commit');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $phase = (string) $input->getArgument('phase');
        if ('prepare' === $phase) {
            $newKey = getenv('MP2FA_NEW_COOKIE_KEY');
            if (!is_string($newKey) || '' === $newKey) {
                $output->writeln('<error>Set MP2FA_NEW_COOKIE_KEY in the command environment.</error>');

                return Command::INVALID;
            }
            $this->keys->prepareRotation($newKey);
            $output->writeln('<info>Prepared. Deploy the new _NEW_COOKIE_KEY_, run key:health, then commit.</info>');

            return Command::SUCCESS;
        }

        if ('commit' === $phase) {
            $this->keys->commitPreparedRotation();
            $output->writeln('<info>The prepared wrapping key is now active.</info>');

            return Command::SUCCESS;
        }

        $output->writeln('<error>Phase must be prepare or commit.</error>');

        return Command::INVALID;
    }
}
