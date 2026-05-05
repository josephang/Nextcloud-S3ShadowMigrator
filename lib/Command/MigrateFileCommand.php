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
            ->addOption('batch', 'b', InputOption::VALUE_OPTIONAL, 'Number of files to migrate in batch mode', 100);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $s3BucketIdentifier = $this->config->getAppValue('s3shadowmigrator', 's3_bucket_identifier', '');
        if (empty($s3BucketIdentifier)) {
            $output->writeln('<error>S3 Bucket Identifier is not configured. Run: occ config:app:set s3shadowmigrator s3_bucket_identifier --value="amazon::external::..."</error>');
            return Command::FAILURE;
        }

        $s3StorageId = $this->fileCacheUpdater->getS3StorageId($s3BucketIdentifier);
        if ($s3StorageId === null) {
            $output->writeln("<error>Storage '{$s3BucketIdentifier}' not found in oc_storages. Is the External Storage mounted and accessible?</error>");
            return Command::FAILURE;
        }

        $fileIdArg = $input->getArgument('fileid');

        if ($fileIdArg !== null) {
            // Single file mode
            $fileId = (int)$fileIdArg;
            if ($fileId <= 0) {
                $output->writeln('<error>Invalid file ID. Must be a positive integer.</error>');
                return Command::FAILURE;
            }

            $output->writeln("Migrating File ID {$fileId} to S3 (bucket identifier: {$s3BucketIdentifier})...");
            $success = $this->migrationService->migrateFileById($fileId, $s3StorageId);

            if ($success) {
                $output->writeln("<info>✓ File ID {$fileId} successfully migrated to S3.</info>");
                return Command::SUCCESS;
            } else {
                $output->writeln("<error>✗ Migration failed for File ID {$fileId}. Check nextcloud.log for details.</error>");
                return Command::FAILURE;
            }
        } else {
            // Batch mode
            $batchLimit = max(1, min(5000, (int)$input->getOption('batch')));
            $output->writeln("Running batch drain of up to {$batchLimit} files from local storage to S3...");

            $migrated = $this->migrationService->migrateBatch($batchLimit);
            $output->writeln("<info>✓ Batch complete: {$migrated} files migrated.</info>");
            return Command::SUCCESS;
        }
    }
}
