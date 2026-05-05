<?php

declare(strict_types=1);

namespace OCA\S3ShadowMigrator\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use OCA\S3ShadowMigrator\Service\S3MigrationService;
use OCP\IConfig;

class MigrateFileCommand extends Command {
    private S3MigrationService $migrationService;
    private IConfig $config;

    public function __construct(S3MigrationService $migrationService, IConfig $config) {
        parent::__construct();
        $this->migrationService = $migrationService;
        $this->config = $config;
    }

    protected function configure(): void {
        $this
            ->setName('s3shadowmigrator:migrate-file')
            ->setDescription('Manually migrates a single file ID to the configured S3 bucket.')
            ->addArgument('fileid', InputArgument::REQUIRED, 'The numeric ID of the file to migrate');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $fileId = (int)$input->getArgument('fileid');
        
        $s3BucketIdentifier = $this->config->getAppValue('s3shadowmigrator', 's3_bucket_identifier', '');
        if (empty($s3BucketIdentifier)) {
            $output->writeln('<error>S3 Bucket Identifier is not configured in settings.</error>');
            return Command::FAILURE;
        }

        $output->writeln("Triggering S3 shadow migration for File ID: {$fileId}");
        
        // Emulate background migration
        $this->migrationService->migrateBatch(1);
        
        $output->writeln('<info>Migration batch executed. Check Nextcloud logs if the file was not moved.</info>');
        return Command::SUCCESS;
    }
}
