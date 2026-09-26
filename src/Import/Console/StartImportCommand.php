<?php

declare(strict_types=1);

namespace App\Import\Console;

use App\Import\Message\ImportRecord;
use App\Import\Run\ImportAlreadyRunningException;
use App\Import\Run\ImportRunLifecycle;
use App\Source\SourceCatalog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'app:import:start', description: 'Starts an import run and queues every record from the source')]
final class StartImportCommand extends Command
{
    public function __construct(
        private readonly SourceCatalog $source,
        private readonly ImportRunLifecycle $lifecycle,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'limit',
            null,
            InputOption::VALUE_REQUIRED,
            'Queue at most this many records from the source, instead of the full run.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $rawLimit = $input->getOption('limit');
        $limit = null;

        if ($rawLimit !== null) {
            if (!\is_string($rawLimit) || preg_match('/^[1-9]\d*$/', $rawLimit) !== 1) {
                $io->error('Option "limit" must be a positive integer.');

                return Command::FAILURE;
            }

            $limit = (int) $rawLimit;
        }

        try {
            $runId = $this->lifecycle->start($this->source->name());
        } catch (ImportAlreadyRunningException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $queued = 0;
        foreach ($this->source->listRecords() as $ref) {
            if ($limit !== null && $queued >= $limit) {
                break;
            }

            $this->bus->dispatch(new ImportRecord($runId, $ref));
            ++$queued;
        }

        $io->success(sprintf(
            'Started import run %d for source "%s" and queued %d record(s).',
            $runId,
            $this->source->name(),
            $queued,
        ));

        return Command::SUCCESS;
    }
}
