<?php

declare(strict_types=1);

namespace OCA\S3ShadowMigrator\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use OCA\S3ShadowMigrator\Service\S3MigrationService;
use OCA\S3ShadowMigrator\Db\FileCacheUpdater;
use OCP\IConfig;
use OCP\IDBConnection;

class MigrateFileCommand extends Command {
    private S3MigrationService $migrationService;
    private FileCacheUpdater $fileCacheUpdater;
    private IConfig $config;
    private IDBConnection $db;

    public function __construct(
        S3MigrationService $migrationService,
        FileCacheUpdater $fileCacheUpdater,
        IConfig $config,
        IDBConnection $db
    ) {
        parent::__construct();
        $this->migrationService = $migrationService;
        $this->fileCacheUpdater = $fileCacheUpdater;
        $this->config = $config;
        $this->db = $db;
    }

    protected function configure(): void {
        $this
            ->setName('s3shadowmigrator:migrate-file')
            ->setDescription('Manually migrates a single specific file to the configured S3 bucket.')
            ->addArgument('fileid', InputArgument::REQUIRED, 'The numeric oc_filecache ID of the file to migrate');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $fileId = (int)$input->getArgument('fileid');

        if ($fileId <= 0) {
            $output->writeln('<error>Invalid file ID. Must be a positive integer.</error>');
            return Command::FAILURE;
        }

        $s3BucketIdentifier = $this->config->getAppValue('s3shadowmigrator', 's3_bucket_identifier', '');
        if (empty($s3BucketIdentifier)) {
            $output->writeln('<error>S3 Bucket Identifier is not configured. Set it via Admin Settings or: occ config:app:set s3shadowmigrator s3_bucket_identifier --value="amazon::external::..."</error>');
            return Command::FAILURE;
        }

        // BUG FIX: The old command called migrateBatch(1) which picks the FIRST file
        // in the queue, not the specific fileId the user requested!
        // This command must fetch the specific file record and call migrateFile() directly.
        $query = $this->db->getQueryBuilder();
        $query->select('*')
              ->from('filecache')
              ->where($query->expr()->eq('fileid', $query->createNamedParameter($fileId)));

        $fileRecord = $query->executeQuery()->fetch();

        if (!$fileRecord) {
            $output->writeln("<error>File ID {$fileId} not found in oc_filecache.</error>");
            return Command::FAILURE;
        }

        $s3StorageId = $this->fileCacheUpdater->getS3StorageId($s3BucketIdentifier);
        if ($s3StorageId === null) {
            $output->writeln("<error>Could not find a storage with identifier '{$s3BucketIdentifier}' in oc_storages. Is the External Storage mounted correctly?</error>");
            return Command::FAILURE;
        }

        // Check if already on S3
        if ((int)$fileRecord['storage'] === $s3StorageId) {
            $output->writeln("<comment>File ID {$fileId} is already on the target S3 storage. Nothing to do.</comment>");
            return Command::SUCCESS;
        }

        $output->writeln("Migrating File ID {$fileId} (path: {$fileRecord['path']}) to S3...");

        $success = $this->migrationService->migrateFile(
            $fileId,
            $fileRecord['path'],
            $fileRecord,
            $s3StorageId
        );

        if ($success) {
            $output->writeln("<info>Successfully migrated File ID {$fileId} to S3!</info>");
            return Command::SUCCESS;
        } else {
            $output->writeln("<error>Migration failed for File ID {$fileId}. Check nextcloud.log for details.</error>");
            return Command::FAILURE;
        }
    }
}
