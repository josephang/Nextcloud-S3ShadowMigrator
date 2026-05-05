<?php

declare(strict_types=1);

namespace OCA\S3ShadowMigrator\Command;

use OCP\IDBConnection;
use OCP\IConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Aws\S3\S3Client;

class GcCommand extends Command {
    private IDBConnection $db;
    private IConfig $config;

    public function __construct(IDBConnection $db, IConfig $config) {
        parent::__construct();
        $this->db = $db;
        $this->config = $config;
    }

    protected function configure(): void {
        $this->setName('s3shadowmigrator:gc')
             ->setDescription('Garbage Collector: Sweeps orphaned S3 objects for deleted Nextcloud files.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $output->writeln('<info>Starting S3ShadowMigrator Garbage Collection...</info>');

        $bucketName = $this->config->getAppValue('s3shadowmigrator', 's3_bucket_name', '');
        if (empty($bucketName)) {
            $output->writeln('<error>Bucket name not configured.</error>');
            return Command::FAILURE;
        }

        // Find orphaned fileids
        // An orphan is an entry in oc_s3shadow_files that no longer exists in oc_filecache
        $query = $this->db->getQueryBuilder();
        $query->select('sf.fileid', 'sf.s3_key')
              ->from('s3shadow_files', 'sf')
              ->leftJoin('sf', 'filecache', 'fc', $query->expr()->eq('sf.fileid', 'fc.fileid'))
              ->where($query->expr()->isNull('fc.fileid'));

        $orphans = $query->executeQuery()->fetchAll();

        if (empty($orphans)) {
            $output->writeln('<info>No orphaned files found. Storage is clean.</info>');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('<info>Found %d orphaned records in the shadow tracking table. Cleaning up...</info>', count($orphans)));

        $s3 = $this->getS3Client();
        $deletedCount = 0;
        $failedCount = 0;

        foreach ($orphans as $orphan) {
            $fileId = (int)$orphan['fileid'];
            $s3Key = (string)($orphan['s3_key'] ?? '');
            
            if (empty($s3Key)) {
                $output->writeln("<error>Cannot delete S3 object for fileid {$fileId}: s3_key is missing in DB.</error>");
                $failedCount++;
                continue;
            }

            try {
                // Delete from S3
                $s3->deleteObject([
                    'Bucket' => $bucketName,
                    'Key'    => $s3Key
                ]);
                
                // Delete from DB tracking table
                $deleteQuery = $this->db->getQueryBuilder();
                $deleteQuery->delete('s3shadow_files')
                            ->where($deleteQuery->expr()->eq('fileid', $deleteQuery->createNamedParameter($fileId)))
                            ->executeStatement();
                            
                $output->writeln("<info>Deleted orphan S3 object: {$s3Key}</info>");
                $deletedCount++;
            } catch (\Exception $e) {
                $output->writeln("<error>Failed to delete S3 object '{$s3Key}': " . $e->getMessage() . "</error>");
                $failedCount++;
            }
        }

        $output->writeln("<info>Garbage collection finished. Deleted: {$deletedCount}, Failed: {$failedCount}</info>");

        return Command::SUCCESS;
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
