<?php

declare(strict_types=1);

namespace Mpadmin2fa\Command;

use Mpadmin2fa\Security\KeyManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class KeyHealthCommand extends Command
{
    protected static $defaultName = 'mpadmin2fa:key:health';
    /** @var KeyManager */
    private $keys;

    public function __construct(KeyManager $keys)
    {
        $this->keys = $keys;
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $health = $this->keys->health();
        $output->writeln(json_encode($health, JSON_PRETTY_PRINT));

        return true === ($health['healthy'] ?? false) ? 0 : 1;
    }
}
