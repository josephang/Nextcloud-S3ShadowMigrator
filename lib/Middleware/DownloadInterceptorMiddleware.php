<?php

declare(strict_types=1);

namespace OCA\S3ShadowMigrator\Middleware;

use OCP\Encryption\IManager as EncryptionManager;
use OCP\IRequest;
use OCP\Files\File;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Aws\S3\S3Client;

class DownloadInterceptorMiddleware {
    private EncryptionManager $encryptionManager;
    private IRequest $request;
    private IConfig $config;
    private IDBConnection $db;
    private LoggerInterface $logger;
    private ?S3Client $s3Client = null;

    public function __construct(
        EncryptionManager $encryptionManager,
        IRequest $request,
        IConfig $config,
        IDBConnection $db,
        LoggerInterface $logger
    ) {
        $this->encryptionManager = $encryptionManager;
        $this->request = $request;
        $this->config = $config;
        $this->db = $db;
        $this->logger = $logger;
    }

    private function getS3Client(): S3Client {
        if ($this->s3Client === null) {
            $s3Config = \OCA\S3ShadowMigrator\Service\S3ConfigHelper::getS3Config($this->config, $this->db);
            $this->s3Client = \OCA\S3ShadowMigrator\Service\S3ConfigHelper::createS3Client($s3Config);
        }
        return $this->s3Client;
    }

    /**
     * Intercept a download request. Issues a 302 pre-signed redirect if the file is on our S3 bucket.
     */
    public function interceptDownload(File $node): bool {
        // BUG FIX: This is fired on BeforeNodeReadEvent which covers ALL file system operations,
        // not just user-facing downloads. We must only redirect on actual HTTP requests.
        // Nextcloud background jobs, preview generation, etc. run in CLI mode where
        // PHP_SAPI is 'cli' or the request object has no URI. Redirecting in those contexts
        // calls header() which is a no-op in CLI and then exit() which terminates the cron job.
        if (PHP_SAPI === 'cli') {
            return false;
        }

        // Only intercept GET requests
        if ($this->request->getMethod() !== 'GET') {
            return false;
        }

        $uri = $this->request->getRequestUri();

        // BUG FIX: The original check for '/download' matched ANY URL containing the word download,
        // including admin paths like '/settings/admin/download'. This must be anchored to known
        // Nextcloud file download endpoint patterns only.
        $isDownloadRoute = (
            preg_match('#/index\.php/f/\d+#', $uri) ||        // Web UI direct link
            str_contains($uri, '/remote.php/webdav') ||        // WebDAV
            str_contains($uri, '/remote.php/dav/files') ||     // DAV files
            str_contains($uri, '/index.php/apps/files/ajax/download') || // Legacy AJAX download
            (str_contains($uri, '/download') && str_contains($uri, '/apps/files')) // Files app download
        );

        if (!$isDownloadRoute) {
            return false;
        }

        // Determine if this is an explicit user-initiated download or an inline stream/preview.
        // Explicit downloads should force 'attachment' so the browser saves the file with the
        // correct filename. Inline requests (media player, PDF viewer, image preview) must use
        // 'inline' so the browser can render/play the content instead of downloading it.
        $isExplicitDownload = (
            str_contains($uri, '/index.php/apps/files/ajax/download') ||
            str_contains($this->request->getParam('download', ''), '1') ||
            $this->request->getParam('downloadStartSecret') !== null
        );
        $contentDisposition = $isExplicitDownload
            ? 'attachment; filename="' . basename($node->getName()) . '"'
            : 'inline; filename="' . basename($node->getName()) . '"';



        // Bypass encrypted files - they MUST be decrypted server-side
        if ($this->encryptionManager->isReady()) {
            // BUG FIX: getFileInfo() on a file throws if the storage is unavailable.
            // The node itself has an isEncrypted() check path we can use more safely.
            try {
                if ($node->getFileInfo()->isEncrypted()) {
                    $this->logger->debug('S3ShadowMigrator bypassing redirect for encrypted file ID: ' . $node->getId(), ['app' => 's3shadowmigrator']);
                    return false;
                }
            } catch (\Exception $e) {
                // If we can't determine encryption status, play it safe and don't redirect
                return false;
            }
        }

        try {
            $fileStorageId = $node->getStorage()->getId();
        } catch (\Exception $e) {
            $this->logger->debug('S3ShadowMigrator: could not get storage ID for file. Skipping.', ['app' => 's3shadowmigrator']);
            return false;
        }

        // Extract username from storage ID
        $username = null;
        if (str_starts_with($fileStorageId, 'home::')) {
            $username = substr($fileStorageId, strlen('home::'));
        }

        if (empty($username)) {
            return false;
        }

        // Check if file is sparse. Pass empty ETag — detection is by status='active' only.
        // The Migrator keeps fc.etag in sync with migrated_etag after sparsing, making an
        // ETag check here redundant. Removing it also eliminates the scanner race window.
        $updater = new \OCA\S3ShadowMigrator\Db\FileCacheUpdater($this->db, $this->logger);
        $sparseStatus = $updater->getFileSparseStatus($node->getId(), '');

        if ($sparseStatus === null || !$sparseStatus['is_sparse']) {
            return false; // Not tracked, or currently mid-upload (status='uploading')
        }

        // Vault Check: If the file is encrypted, we CANNOT issue a 302 redirect.
        // It must stream through Azure to be decrypted by our Storage Wrapper.
        if ($sparseStatus['is_vault']) {
            $this->logger->info("S3ShadowMigrator: bypassing 302 redirect for Vault file (ID {$node->getId()}). Must decrypt locally.", ['app' => 's3shadowmigrator']);
            return false;
        }

        // Generate Pre-signed URL and Redirect
        try {
            $s3Config = \OCA\S3ShadowMigrator\Service\S3ConfigHelper::getS3Config($this->config, $this->db);
            $s3 = $this->getS3Client();
            $bucketName = $s3Config['bucket'];

            // Use the authoritative S3 key from the DB record — never reconstruct from path,
            // as shared/external mounts can produce divergent internal paths.
            $s3Key = $sparseStatus['s3_key'];
            if (empty($s3Key)) {
                // Fallback: reconstruct from node path (for records pre-dating H1 fix)
                $s3Key = $username . '/' . ltrim($node->getInternalPath(), '/');
            }

            $cmd = $s3->getCommand('GetObject', [
                'Bucket'                     => $bucketName,
                'Key'                        => $s3Key,
                // Use inline disposition for media/preview, attachment for explicit downloads.
                // This allows the browser to play videos, render PDFs/images, and stream audio
                // directly from S3 without downloading the file first.
                'ResponseContentDisposition' => $contentDisposition,
            ]);

            // Pre-signed URL valid for 1 hour (enough for very large file downloads)
            $presignedRequest = $s3->createPresignedRequest($cmd, '+1 hour');
            $presignedUrl = (string)$presignedRequest->getUri();

            $this->logger->info("S3ShadowMigrator: issuing 302 for file key: {$s3Key}", ['app' => 's3shadowmigrator']);

            header('Location: ' . $presignedUrl, true, 302);
            exit();

        } catch (\Exception $e) {
            $this->logger->error('S3ShadowMigrator: failed to generate pre-signed URL: ' . $e->getMessage(), ['app' => 's3shadowmigrator']);
            return false;
        }
    }
}
