<?php

declare(strict_types=1);

namespace OCA\S3ShadowMigrator\Dav;

use OCA\S3ShadowMigrator\Service\S3ConfigHelper;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/**
 * Sabre DAV plugin that intercepts GET/HEAD requests for sparse (migrated) files
 * and redirects the client directly to Backblaze B2 via a pre-signed URL.
 *
 * This fires via the Sabre 'beforeMethod:GET' event — BEFORE Sabre sends any
 * response headers — which is the only reliable interception point for WebDAV
 * streaming. Attempting to redirect inside fopen() is too late (Sabre has
 * already sent 200 + Content-Length by then).
 *
 * Benefits:
 *  - Zero host egress: every byte is served directly from B2
 *  - Range request support: the browser re-sends Range headers to B2 after
 *    following the 302, so video seeking works natively
 *  - Vault files are skipped (they require server-side decryption)
 */
class S3RedirectPlugin extends ServerPlugin {
    private IConfig $config;
    private IDBConnection $db;
    private LoggerInterface $logger;

    public function __construct(IConfig $config, IDBConnection $db, LoggerInterface $logger) {
        $this->config = $config;
        $this->db     = $db;
        $this->logger = $logger;
    }

    public function initialize(\Sabre\DAV\Server $server): void {
        // IMPORTANT: This plugin is added by PluginManager INSIDE the 'beforeMethod:*' callback,
        // which means 'beforeMethod:GET' has ALREADY fired by the time initialize() runs.
        // We must listen to 'method:GET' instead — it fires after 'beforeMethod:*', so we
        // can still intercept before Sabre's own file-serving handler (default priority ~90).
        $server->on('method:GET',  [$this, 'handleGet'], 10);
        $server->on('method:HEAD', [$this, 'handleGet'], 10);
        $server->on('propFind', [$this, 'handlePropFind'], 200); // High priority to override properties
    }

    /**
     * Overrides the getcontenttype property for sparse S3 files to force clients to download them
     * instead of attempting to play them using embedded media players.
     */
    public function handlePropFind(\Sabre\DAV\PropFind $propFind, \Sabre\DAV\INode $node): void {
        if (!($node instanceof \OCA\DAV\Connector\Sabre\File)) {
            return;
        }

        try {
            $fileId = $node->getId();
            
            $qb = $this->db->getQueryBuilder();
            $qb->select('is_sparse')
                ->from('s3_shadow_migration')
                ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)));

            $result = $qb->executeQuery();
            $row = $result->fetch();
            $result->closeCursor();

            if ($row && (int)$row['is_sparse'] === 1) {
                // Force application/octet-stream to prevent media players from triggering
                $propFind->handle('{DAV:}getcontenttype', function() {
                    return 'application/octet-stream';
                });
            }
        } catch (\Exception $e) {
            $this->logger->error('S3ShadowMigrator S3RedirectPlugin propFind exception: ' . $e->getMessage(), ['app' => 's3shadowmigrator']);
        }
    }

    /**
     * Intercept GET/HEAD for a user file. If the file is an active sparse stub,
     * issue a 302 to the B2 pre-signed URL and halt further Sabre processing.
     *
     * @return bool  false = stop processing (redirect issued), true = continue normally
     */
    public function handleGet(RequestInterface $request, ResponseInterface $response): bool {
        // Skip for WebDAV clients that do not support 302 redirects (like Windows Explorer)
        $userAgent = $request->getHeader('User-Agent') ?? '';
        if (str_contains($userAgent, 'Microsoft-WebDAV')) {
            $this->logger->debug('S3ShadowMigrator S3RedirectPlugin: skipping Microsoft WebDAV client (no redirect support)', ['app' => 's3shadowmigrator']);
            return true;
        }

        // Sabre path for user files: files/{username}/path/to/file
        $path = rawurldecode($request->getPath());

        if (str_starts_with($path, 'files/')) {
            // New endpoint: remote.php/dav/files/{username}/path
            $parts = explode('/', $path, 3);
            if (count($parts) < 3 || empty($parts[1]) || empty($parts[2])) {
                return true;
            }
            $username  = $parts[1];
            $cachePath = 'files/' . $parts[2];
        } else {
            // Legacy endpoint: remote.php/webdav/path
            // The path is relative to the user's root
            if (!\OC::$server->getUserSession()->isLoggedIn()) {
                return true; // Unauthenticated
            }
            $username = \OC::$server->getUserSession()->getUser()->getUID();
            $cachePath = 'files/' . ltrim($path, '/');
        }

        // Single query: join storages + filecache + s3shadow_files
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('sf.s3_key', 'sf.is_vault')
               ->from('s3shadow_files', 'sf')
               ->innerJoin('sf', 'filecache',  'fc', $qb->expr()->eq('sf.fileid', 'fc.fileid'))
               ->innerJoin('fc', 'storages',   's',  $qb->expr()->eq('fc.storage', 's.numeric_id'))
               ->where($qb->expr()->eq('s.id',   $qb->createNamedParameter('home::' . $username)))
               ->andWhere($qb->expr()->eq('fc.path', $qb->createNamedParameter($cachePath)))
               ->andWhere($qb->expr()->eq('sf.status', $qb->createNamedParameter('active')));

            $row = $qb->executeQuery()->fetch();
        } catch (\Exception $e) {
            $this->logger->warning('S3ShadowMigrator S3RedirectPlugin: DB lookup failed for "' . $path . '": ' . $e->getMessage(), ['app' => 's3shadowmigrator']);
            return true;
        }

        if (!$row || empty($row['s3_key'])) {
            return true; // Not a migrated file
        }

        // Vault files must stream through the server for decryption
        if ((bool)$row['is_vault']) {
            $this->logger->debug('S3ShadowMigrator S3RedirectPlugin: skipping vault file "' . $path . '"', ['app' => 's3shadowmigrator']);
            return true;
        }

        $filename = basename($path);
        
        // ALWAYS force Content-Disposition: attachment to ensure blazing fast downloads
        // and prevent browsers/mobile apps from attempting to play the stream inline.
        $contentDisposition = 'attachment; filename="' . $filename . '"';

        // Generate pre-signed Backblaze B2 URL (1-hour TTL)
        try {
            $s3Config = S3ConfigHelper::getS3Config($this->config, $this->db);
            $s3Client = S3ConfigHelper::createS3Client($s3Config);

            $cmd = $s3Client->getCommand('GetObject', [
                'Bucket' => $s3Config['bucket'],
                'Key'    => $row['s3_key'],
                'ResponseContentDisposition' => $contentDisposition,
            ]);
            $presignedRequest = $s3Client->createPresignedRequest($cmd, '+1 hour');
            $presignedUrl     = (string)$presignedRequest->getUri();
        } catch (\Exception $e) {
            $this->logger->error('S3ShadowMigrator S3RedirectPlugin: failed to generate pre-signed URL for "' . $row['s3_key'] . '": ' . $e->getMessage(), ['app' => 's3shadowmigrator']);
            return true; // Fall through to normal serving
        }

        // Direct browser/app download: Use pure zero-bandwidth 302 Redirect
        $this->logger->info('S3ShadowMigrator S3RedirectPlugin: 302 Forced Download (0 egress) for "' . $path . '"', ['app' => 's3shadowmigrator']);
        
        $response->setStatus(302);
        $response->setHeader('Location', $presignedUrl);
        $response->setBody('');

        return false; // Stop all further Sabre processing for this request
    }
}
