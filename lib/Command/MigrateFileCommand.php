<?php

declare(strict_types=1);

namespace OCA\S3ShadowMigrator\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use OCA\S3ShadowMigrator\Service\S3MigrationService;
use OCA\S3ShadowMigrator\Db\FileCacheUpdater;
use OCP\IConfig;

class MigrateFileCommand extends Command {
    private S3MigrationService $migrationService;
    private FileCacheUpdater $fileCacheUpdater;
    private IConfig $config;

    public function __construct(
        S3MigrationService $migrationService,
        FileCacheUpdater $fileCacheUpdater,
        IConfig $config
    ) {
        parent::__construct();
        $this->migrationService = $migrationService;
        $this->fileCacheUpdater = $fileCacheUpdater;
        $this->config = $config;
    }

    protected function configure(): void {
        $this
            ->setName('s3shadowmigrator:migrate-file')
            ->setDescription('Manually migrates a single specific file to S3, or runs a batch drain.')
            ->addArgument('fileid', InputArgument::OPTIONAL, 'The numeric oc_filecache fileid to migrate. Omit to run a batch.')
            ->addOption('batch', 'b', InputOption::VALUE_OPTIONAL, 'Number of files to migrate in batch mode', 100)
            ->addOption('max-size', null, InputOption::VALUE_OPTIONAL, 'Only migrate files smaller than this size in bytes (0 = no limit)', 0);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $fileIdArg = $input->getArgument('fileid');

        if ($fileIdArg !== null) {
            // Single file mode
            $fileId = (int)$fileIdArg;
            if ($fileId <= 0) {
                $output->writeln('<error>Invalid file ID. Must be a positive integer.</error>');
                return Command::FAILURE;
            }

            $output->writeln("Migrating File ID {$fileId} to S3...");
            $success = $this->migrationService->migrateFileById($fileId);

            if ($success) {
                $output->writeln("<info>✓ File ID {$fileId} successfully migrated to S3.</info>");
                return Command::SUCCESS;
            } else {
                $output->writeln("<error>✗ Migration failed for File ID {$fileId}. Check nextcloud.log for details.</error>");
                return Command::FAILURE;
            }
        } else {
            // Batch mode
            $batchLimit  = max(1, min(50000, (int)$input->getOption('batch')));
            $maxSizeBytes = max(0, (int)$input->getOption('max-size'));
            $sizeLabel = $maxSizeBytes > 0 ? ' (max ' . round($maxSizeBytes/1024/1024, 1) . ' MB each)' : '';
            $output->writeln("Running batch drain of up to {$batchLimit} files{$sizeLabel}...");

            $filesToMigrate = $this->migrationService->getLocalFilesToMigrate($batchLimit, $maxSizeBytes);
            $count = count($filesToMigrate);

            if ($count === 0) {
                $output->writeln("<info>No local files found to migrate. Drain is complete.</info>");
                return Command::SUCCESS;
            }

            $progressBar = new \Symfony\Component\Console\Helper\ProgressBar($output, $count);
            $progressBar->start();

            $migrated = $this->migrationService->migrateBatch($batchLimit, $maxSizeBytes, function() use ($progressBar) {
                $progressBar->advance();
            });

            $progressBar->finish();
            $output->writeln("");
            $output->writeln("Batch complete: {$migrated} files migrated.");
            return Command::SUCCESS;
        }
    }
}
