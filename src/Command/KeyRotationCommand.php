<?php

declare(strict_types=1);

namespace Mpadmin2fa\Command;

use Mpadmin2fa\Security\KeyManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class KeyRotationCommand extends Command
{

    /** @var KeyManager */
    private $keys;

    protected static $defaultName = 'mpadmin2fa:key:rotate';
    public function __construct(KeyManager $keys) {
        $this->keys = $keys;
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

                return 2;
            }
            $this->keys->prepareRotation($newKey);
            $output->writeln('<info>Prepared. Deploy the new _NEW_COOKIE_KEY_, run key:health, then commit.</info>');

            return 0;
        }

        if ('commit' === $phase) {
            $this->keys->commitPreparedRotation();
            $output->writeln('<info>The prepared wrapping key is now active.</info>');

            return 0;
        }

        $output->writeln('<error>Phase must be prepare or commit.</error>');

        return 2;
    }
}
