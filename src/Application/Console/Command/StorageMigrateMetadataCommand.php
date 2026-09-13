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
                name: 'remove-legacy',
                mode: InputOption::VALUE_NONE,
                description: 'Also delete each legacy file after copying it. Without this nothing leaves the object namespace.',
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
        $removeLegacy = (bool) $input->getOption('remove-legacy');
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
        $report = $driver->migrateLegacyMetadata($apply, $removeLegacy);

        if ($report->unreadableRoot !== null) {
            // Not the same as finding nothing. Reporting success here told an
            // operator their storage was clean when it had not been read at all.
            $output->writeln(sprintf(
                '<error>Storage root does not exist or cannot be resolved: %s</error>',
                $report->unreadableRoot,
            ));

            return Command::FAILURE;
        }

        if ($report->isEmpty()) {
            $output->writeln('<info>Nothing to migrate: no legacy metadata found.</info>');
            return Command::SUCCESS;
        }

        foreach ($report->moved as $key) {
            $output->writeln(sprintf(
                '  %s %s',
                $apply ? ($removeLegacy ? 'moved' : 'copied') : ($removeLegacy ? 'would move' : 'would copy'),
                $key,
            ));
        }

        foreach ($report->skipped as $file => $why) {
            $output->writeln(sprintf('  <comment>left alone</comment> %s — %s', $file, $why));
        }

        if ($report->alreadyMigrated > 0) {
            // Said the same way in both modes: a dry run that does not mention
            // the removal is not a plan of what --apply does.
            $output->writeln(sprintf(
                '  %d already had metadata in the reserved subtree; %s',
                $report->alreadyMigrated,
                $removeLegacy
                    ? ($apply ? 'removed the leftover beside the object.' : 'would remove the leftover beside the object.')
                    : 'the leftover beside the object is left in place.',
            ));
        }

        $output->writeln(sprintf(
            '<info>%s %d, left alone %d.</info>',
            $apply ? ($removeLegacy ? 'Moved' : 'Copied') : ($removeLegacy ? 'Would move' : 'Would copy'),
            $report->movedCount(),
            $report->skippedCount(),
        ));

        if (!$apply) {
            $output->writeln('Nothing was changed. Re-run with --apply to do it.');
        } elseif (!$removeLegacy) {
            // Said plainly, because a copy that leaves both files looks like a
            // half-finished job unless the operator is told it is the point: a
            // legacy file and a caller's own object are the same kind of file,
            // so nothing leaves the object namespace on the tool's own
            // judgement. Once the list above has been read, --remove-legacy
            // does that half.
            $output->writeln(
                'The legacy files were left where they are; .meta/ is read first, so nothing depends on them now. '
                . 'Re-run with --apply --remove-legacy to delete them once you have read the list.',
            );
        }

        return Command::SUCCESS;
    }
}
