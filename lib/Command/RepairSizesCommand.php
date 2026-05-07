<?php

declare(strict_types=1);

namespace OCA\S3ShadowMigrator\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use OCP\IDBConnection;
use OCP\IConfig;
use OCA\S3ShadowMigrator\Service\S3ConfigHelper;

class RepairSizesCommand extends Command {
    private IDBConnection $db;
    private IConfig $config;

    public function __construct(IDBConnection $db, IConfig $config) {
        parent::__construct();
        $this->db = $db;
        $this->config = $config;
    }

    protected function configure() {
        $this->setName('s3shadow:repair-sizes')
             ->setDescription('Finds all S3-migrated files that show as 0 bytes and restores their correct size (as Linux sparse files)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $dataDir = rtrim($this->config->getSystemValue('datadirectory', '/var/www/nextcloud/data'), '/');

        $qb = $this->db->getQueryBuilder();
        $qb->select('fc.fileid', 'fc.path', 'sf.s3_key', 's.id AS storage_id')
           ->from('filecache', 'fc')
           ->innerJoin('fc', 's3shadow_files', 'sf', $qb->expr()->eq('fc.fileid', 'sf.fileid'))
           ->leftJoin('fc', 'storages', 's', $qb->expr()->eq('fc.storage', 's.numeric_id'))
           ->where($qb->expr()->eq('fc.size', $qb->createNamedParameter(0)))
           ->andWhere($qb->expr()->neq('sf.status', $qb->createNamedParameter('lost')))
           ->andWhere($qb->expr()->neq('sf.status', $qb->createNamedParameter('uploading')));

        $rows = $qb->executeQuery()->fetchAll();

        if (empty($rows)) {
            $output->writeln('<info>No 0-byte entries found. All sizes look correct!</info>');
            return 0;
        }

        $output->writeln('<info>Found ' . count($rows) . ' files with 0-byte size. Fetching real sizes from S3...</info>');

        $s3Config = S3ConfigHelper::getS3Config($this->config, $this->db);
        $s3 = S3ConfigHelper::createS3Client($s3Config);
        $bucket = $s3Config['bucket'];
        $repaired = 0;

        foreach ($rows as $row) {
            $fileId    = (int)$row['fileid'];
            $s3Key     = $row['s3_key'];
            $storageId = $row['storage_id'] ?? '';
            $cachePath = $row['path'];

            if (empty($s3Key)) continue;

            try {
                $metadata = $s3->headObject(['Bucket' => $bucket, 'Key' => $s3Key]);
                $realSize = (int)$metadata['ContentLength'];

                if ($realSize <= 0) {
                    $output->writeln("<comment>ID {$fileId} is actually 0 bytes on S3 too. Leaving it.</comment>");
                    continue;
                }

                $output->writeln("Repairing ID {$fileId}: {$cachePath} -> {$realSize} bytes");

                // 1. Write a Linux sparse file so filesize() returns the correct size.
                //    Nextcloud's scanner will then see the right size and won't reset filecache.
                $username = str_starts_with($storageId, 'home::') ? substr($storageId, 6) : null;
                if ($username !== null) {
                    $localPath = $dataDir . '/' . $username . '/' . $cachePath;
                    if (file_exists($localPath)) {
                        $f = fopen($localPath, 'w');
                        if ($f) {
                            ftruncate($f, $realSize); // sparse hole: near-zero disk usage
                            fclose($f);
                        }
                    }
                }

                // 2. Update filecache.size directly
                $upd = $this->db->getQueryBuilder();
                $upd->update('filecache')
                    ->set('size', $upd->createNamedParameter($realSize))
                    ->where($upd->expr()->eq('fileid', $upd->createNamedParameter($fileId)))
                    ->executeStatement();

                $repaired++;
            } catch (\Exception $e) {
                $output->writeln("<error>Failed for {$s3Key}: " . $e->getMessage() . "</error>");
            }
        }

        $output->writeln("<info>Successfully repaired {$repaired} files!</info>");
        return 0;
    }
}
