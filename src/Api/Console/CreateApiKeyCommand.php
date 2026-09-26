<?php

declare(strict_types=1);

namespace App\Api\Console;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:api-key:create',
    description: 'Issue a new API key and print the secret once. Only the SHA-256 hash is stored.',
)]
final class CreateApiKeyCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'A unique operational label for the key.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $nameArgument = $input->getArgument('name');

        if (!\is_string($nameArgument)) {
            $io->error('The "name" argument must be a string.');

            return Command::FAILURE;
        }

        $name = $nameArgument;

        if (trim($name) === '') {
            $io->error('The key name must not be blank.');

            return Command::FAILURE;
        }

        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $keyHashHex = hash('sha256', $secret);

        try {
            $this->connection->executeStatement(
                'INSERT INTO api_key (name, key_hash) VALUES (:name, decode(:keyHashHex, \'hex\'))',
                ['name' => $name, 'keyHashHex' => $keyHashHex],
            );
        } catch (UniqueConstraintViolationException) {
            $io->error(sprintf('An API key named "%s" already exists.', $name));

            return Command::FAILURE;
        }

        $io->writeln($secret);

        return Command::SUCCESS;
    }
}
