<?php

declare(strict_types=1);

namespace App\Import\Console;

use App\Import\Run\ImportRunLifecycle;
use App\Import\Run\ImportRunNotRunningException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:import:finish', description: 'Closes an import run as completed or failed')]
final class FinishImportCommand extends Command
{
    public function __construct(private readonly ImportRunLifecycle $lifecycle)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('runId', InputArgument::REQUIRED, 'The import_run.id to close')
            ->addOption('failed', null, InputOption::VALUE_NONE, 'Close the run as failed instead of completed');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $rawRunId = $input->getArgument('runId');
        if (!is_string($rawRunId) && !is_int($rawRunId)) {
            $io->error('The "runId" argument must be a number.');

            return Command::FAILURE;
        }

        $runId = (int) $rawRunId;
        $failed = (bool) $input->getOption('failed');

        try {
            if ($failed) {
                $this->lifecycle->fail($runId);
            } else {
                $this->lifecycle->complete($runId);
            }
        } catch (ImportRunNotRunningException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Import run %d marked as %s.', $runId, $failed ? 'failed' : 'completed'));

        return Command::SUCCESS;
    }
}
