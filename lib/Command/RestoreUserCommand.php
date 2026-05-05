<?php

declare(strict_types=1);

namespace OCA\S3ShadowMigrator\Command;

use OCP\IDBConnection;
use OCP\IConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Aws\S3\S3Client;

class RestoreUserCommand extends Command {
    private IDBConnection $db;
    private IConfig $config;

    public function __construct(IDBConnection $db, IConfig $config) {
        parent::__construct();
        $this->db = $db;
        $this->config = $config;
    }

    protected function configure(): void {
        $this->setName('s3shadowmigrator:restore-user')
             ->setDescription('Restores a specific user\'s files from S3 back to the local Azure drive.')
             ->addArgument('username', InputArgument::REQUIRED, 'The Nextcloud username (e.g., "Jin Kim")');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $username = $input->getArgument('username');
        $output->writeln("<info>Starting S3 rollback for user: {$username}</info>");

        $bucketName = $this->config->getAppValue('s3shadowmigrator', 's3_bucket_name', '');
        if (empty($bucketName)) {
            $output->writeln('<error>Bucket name not configured.</error>');
            return Command::FAILURE;
        }

        $dataDir = rtrim($this->config->getSystemValue('datadirectory', '/var/www/nextcloud/data'), '/');
        $storageIdString = 'home::' . $username;

        // Find all files belonging to this user that are currently sparse
        $query = $this->db->getQueryBuilder();
        $query->select('fc.fileid', 'fc.path', 'fc.size', 'sf.s3_key')
              ->from('filecache', 'fc')
              ->innerJoin('fc', 'storages', 's', $query->expr()->eq('fc.storage', 's.numeric_id'))
              ->innerJoin('fc', 's3shadow_files', 'sf', $query->expr()->eq('fc.fileid', 'sf.fileid'))
              ->where($query->expr()->eq('s.id', $query->createNamedParameter($storageIdString)));

        $files = $query->executeQuery()->fetchAll();

        if (empty($files)) {
            $output->writeln("<info>No migrated files found for user {$username}.</info>");
            return Command::SUCCESS;
        }

        $count = count($files);
        $output->writeln("Found {$count} migrated files. Beginning restoration...");

        $progressBar = new ProgressBar($output, $count);
        $progressBar->start();

        $s3 = $this->getS3Client();
        $restored = 0;
        $failed = 0;

        foreach ($files as $file) {
            $fileId = (int)$file['fileid'];
            $path = $file['path'];
            $expectedSize = (int)$file['size'];
            $s3Key = $file['s3_key'];

            if (empty($s3Key)) {
                // If s3_key is missing, fall back to default path structure
                $s3Key = $username . '/' . ltrim($path, '/');
            }

            $localPhysicalPath = $dataDir . '/' . $username . '/' . $path;

            // Use a temporary file for safe download
            $tempPath = $localPhysicalPath . '.s3restore';

            try {
                // Download from S3 directly to temporary file
                $s3->getObject([
                    'Bucket' => $bucketName,
                    'Key'    => $s3Key,
                    'SaveAs' => $tempPath
                ]);

                // Verify file size
                if (file_exists($tempPath) && filesize($tempPath) === $expectedSize) {
                    // Safe to overwrite the sparse file
                    rename($tempPath, $localPhysicalPath);

                    // Delete the S3 object
                    $s3->deleteObject([
                        'Bucket' => $bucketName,
                        'Key'    => $s3Key
                    ]);

                    // Remove tracking record
                    $deleteQuery = $this->db->getQueryBuilder();
                    $deleteQuery->delete('s3shadow_files')
                                ->where($deleteQuery->expr()->eq('fileid', $deleteQuery->createNamedParameter($fileId)))
                                ->executeStatement();

                    $restored++;
                } else {
                    $output->writeln("\n<error>Verification failed for {$path}. Expected: {$expectedSize}, Downloaded: " . filesize($tempPath) . "</error>");
                    if (file_exists($tempPath)) {
                        unlink($tempPath);
                    }
                    $failed++;
                }
            } catch (\Exception $e) {
                $output->writeln("\n<error>Failed to restore {$path}: " . $e->getMessage() . "</error>");
                if (file_exists($tempPath)) {
                    unlink($tempPath);
                }
                $failed++;
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $output->writeln("\n<info>Rollback complete. Restored: {$restored}, Failed: {$failed}</info>");

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    private function getS3Client(): S3Client {
        $region   = $this->config->getAppValue('s3shadowmigrator', 's3_region', 'us-east-1');
        $endpoint = $this->config->getAppValue('s3shadowmigrator', 's3_endpoint', '');
        $key      = $this->config->getAppValue('s3shadowmigrator', 's3_key', '');
        $secret   = $this->config->getAppValue('s3shadowmigrator', 's3_secret', '');

        $s3Config = [
            'version'                 => 'latest',
            'region'                  => $region,
            'use_path_style_endpoint' => true,
            'credentials'             => [
                'key'    => $key,
                'secret' => $secret,
            ],
        ];

        if (!empty($endpoint)) {
            $s3Config['endpoint'] = $endpoint;
        }

        return new S3Client($s3Config);
    }
}
