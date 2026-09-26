<?php

declare(strict_types=1);

namespace App\Catalog\Console;

use App\Catalog\Index\SearchIndexRefresher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:search:refresh', description: 'Rebuilds the search index from the taxonomy tables')]
final class RefreshSearchIndexCommand extends Command
{
    public function __construct(private readonly SearchIndexRefresher $refresher)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->refresher->refresh();

        $io->success('Search index refreshed.');

        return Command::SUCCESS;
    }
}
