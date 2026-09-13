<?php

declare(strict_types=1);

namespace Semitexa\Storage\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Environment;
use Semitexa\Storage\Driver\LocalDriver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'storage:migrate-metadata')]
final class StorageMigrateMetadataCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('storage:migrate-metadata')
            ->setDescription('Move pre-.meta/ object metadata into the reserved metadata subtree.')
            ->addOption(
                name: 'apply',
                mode: InputOption::VALUE_NONE,
                description: 'Actually move the files. Without it nothing is touched and the plan is printed.',
            )
            ->addOption(
                name: 'path',
                mode: InputOption::VALUE_REQUIRED,
                description: 'Storage root to migrate. Defaults to the configured local storage root.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $apply = (bool) $input->getOption('apply');
        /** @var string|null $path */
        $path = $input->getOption('path');

        // This command only means anything for the local driver. On an s3
        // install it would otherwise scan an empty var/uploads and report
        // "nothing to migrate", which reads as "you are done" rather than
        // "this does not apply here".
        $configured = Environment::getEnvValue('STORAGE_DRIVER', 'local') ?? 'local';
        if ($path === null && strtolower(trim($configured)) !== 'local') {
            $output->writeln(sprintf(
                '<comment>STORAGE_DRIVER is %s, so there is no local metadata to migrate.</comment>',
                $configured,
            ));
            $output->writeln('Pass --path to migrate a specific local root anyway.');

            return Command::SUCCESS;
        }

        $driver = new LocalDriver($path);
        $report = $driver->migrateLegacyMetadata($apply);

        if ($report->isEmpty()) {
            $output->writeln('<info>Nothing to migrate: no legacy metadata found.</info>');
            return Command::SUCCESS;
        }

        foreach ($report->moved as $key) {
            $output->writeln(sprintf('  %s %s', $apply ? 'moved' : 'would move', $key));
        }

        foreach ($report->skipped as $file => $why) {
            $output->writeln(sprintf('  <comment>left alone</comment> %s — %s', $file, $why));
        }

        if ($report->alreadyMigrated > 0) {
            // Said the same way in both modes: a dry run that does not mention
            // the removal is not a plan of what --apply does.
            $output->writeln(sprintf(
                '  %d already had metadata in the reserved subtree; %s the leftover beside the object.',
                $report->alreadyMigrated,
                $apply ? 'removed' : 'would remove',
            ));
        }

        $output->writeln(sprintf(
            '<info>%s %d, left alone %d.</info>',
            $apply ? 'Moved' : 'Would move',
            $report->movedCount(),
            $report->skippedCount(),
        ));

        if (!$apply) {
            $output->writeln('Nothing was changed. Re-run with --apply to do it.');
        }

        return Command::SUCCESS;
    }
}
