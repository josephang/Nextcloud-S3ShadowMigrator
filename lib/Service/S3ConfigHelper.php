<?php

declare(strict_types=1);

namespace OCA\S3ShadowMigrator\Service;

use OCP\IConfig;
use OCP\IDBConnection;
use Aws\S3\S3Client;

class S3ConfigHelper {
    public static function getS3Config(IConfig $config, IDBConnection $db): array {
        $mountId = (int)$config->getAppValue('s3shadowmigrator', 's3_mount_id', '0');
        if ($mountId === 0) {
            throw new \RuntimeException('S3 Mount ID is not configured in S3 Shadow Migrator settings.');
        }

        $query = $db->getQueryBuilder();
        $query->select('key', 'value')
              ->from('external_config')
              ->where($query->expr()->eq('mount_id', $query->createNamedParameter($mountId)));
        
        $result = $query->executeQuery()->fetchAll();

        $s3Config = [];
        foreach ($result as $row) {
            $s3Config[$row['key']] = $row['value'];
        }

        if (empty($s3Config['key']) || empty($s3Config['secret']) || empty($s3Config['bucket'])) {
            throw new \RuntimeException('Incomplete S3 credentials found in oc_external_config for mount ID ' . $mountId);
        }

        return $s3Config;
    }

    public static function createS3Client(array $s3Config): S3Client {
        $clientConfig = [
            'version'                 => 'latest',
            'region'                  => $s3Config['region'] ?? 'us-east-1',
            'use_path_style_endpoint' => true,
            'credentials'             => [
                'key'    => $s3Config['key'],
                'secret' => $s3Config['secret'],
            ],
        ];

        if (!empty($s3Config['hostname'])) {
            // Ensure hostname has protocol
            $clientConfig['endpoint'] = str_starts_with($s3Config['hostname'], 'http') 
                ? $s3Config['hostname'] 
                : 'https://' . $s3Config['hostname'];
        }

        return new S3Client($clientConfig);
    }
}
