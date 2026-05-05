# Nextcloud S3 Shadow Migrator

A custom Nextcloud architecture designed to bridge massive local storage volumes with remote S3/Backblaze B2 buckets using **Zero-Egress Direct Streaming** and **Linux Sparse Files**.

This app was built to bypass Nextcloud's native bandwidth bottlenecks and database limitations by intercepting downloads at the core level while preserving the local PostgreSQL database state.

## Core Features

1. **The Sparse File Trick**
   Files uploaded by users are temporarily buffered on the local NVMe storage. The background migrator pushes them to S3 and uses Linux `ftruncate()` to reduce their physical disk footprint to `0` blocks, while perfectly maintaining Nextcloud's native `oc_filecache` metadata and UI representation.
2. **Zero-Egress Direct Streaming**
   A custom `DownloadInterceptorMiddleware` intercepts Nextcloud download requests (UI, Share Links, WebDAV). If the file is tracked as sparse, it seamlessly generates a 302 Redirect to an S3 Pre-signed URL, completely bypassing the Nextcloud server's egress bandwidth.
3. **The Null-Byte Trap Prevention**
   A low-level `S3ShadowStorageWrapper` hooks into Nextcloud's `\OC\Files\Storage\Local`. It detects internal Nextcloud background workers (like Preview Generators or Search Indexers) and actively blocks them from reading the 0-byte sparse files, preventing infinite streams of null bytes from crashing the server's memory.
4. **Self-Healing Versioning**
   The application tracks file ETags. If a user natively overwrites a file via Nextcloud, the ETag mismatch is instantly detected. The middleware falls back to serving the new local file, and the Migrator sweeps it back into S3 on its next pass.

---

## Installation & Configuration

Install the app in your Nextcloud `apps/` directory and configure the target S3 credentials via `occ`:

```bash
sudo -u www-data php occ config:app:set s3shadowmigrator s3_bucket_name --value="my-bucket"
sudo -u www-data php occ config:app:set s3shadowmigrator s3_bucket_identifier --value="amazon::external::123"
sudo -u www-data php occ config:app:set s3shadowmigrator s3_region --value="us-west-004"
sudo -u www-data php occ config:app:set s3shadowmigrator s3_endpoint --value="https://s3.us-west-004.backblazeb2.com"
sudo -u www-data php occ config:app:set s3shadowmigrator s3_key --value="your_access_key"
sudo -u www-data php occ config:app:set s3shadowmigrator s3_secret --value="your_secret_key"
```

*Note: Nextcloud `files_versions` MUST be disabled (`occ app:disable files_versions`) to prevent corrupted version histories.*

---

## Command Line Tools (CLI)

The migrator ships with three powerful `occ` commands to manage your storage infrastructure.

### 1. The Migrator: `occ s3shadowmigrator:migrate-file`
Pushes files to S3, tracks them in `oc_s3shadow_files`, and truncates the local disks.

**Single File Mode:**
```bash
sudo -u www-data php occ s3shadowmigrator:migrate-file 12345
```

**Batch Mode (Recommended for cron/drain scripts):**
```bash
# Migrates 5000 files smaller than 50MB
sudo -u www-data php occ s3shadowmigrator:migrate-file --batch 5000 --max-size 52428800
```
*Tip: The app includes `drain_all.sh` which wraps this command in an infinite, error-trapped loop for massive 1TB+ continuous offloads.*

### 2. The Undo Button: `occ s3shadowmigrator:restore-user`
Reverses the migration for a specific user. It safely streams the files back from S3 into temporary buffers, validates their byte-lengths, overwrites the sparse files to restore disk blocks, and permanently deletes the S3 copies to save costs.

```bash
sudo -u www-data php occ s3shadowmigrator:restore-user "Jin Kim"
```

### 3. The Garbage Collector: `occ s3shadowmigrator:gc`
Nextcloud event hooks are unreliable for bulk deletions. The Nightly GC command cross-references the shadow database against the active Nextcloud filesystem. Any orphaned S3 objects are permanently purged from the remote bucket.

```bash
sudo -u www-data php occ s3shadowmigrator:gc
```

---

## Important Exclusions

You can manually exclude certain users or paths from the migration sweep by editing the query builder inside `lib/Service/S3MigrationService.php`.

**Example:**
```php
// EXCLUSION: Do not migrate files for user Jin Kim
->andWhere($query->expr()->neq('s.id', $query->createNamedParameter('home::Jin Kim')))
```

## Security & Encryption
- **End-to-End Encryption (E2EE):** Fully supported. Users must use the Nextcloud Mobile/Desktop clients to encrypt their files locally. The encrypted blobs are seamlessly offloaded to S3.
- **Server-Side Encryption (SSE):** Not Recommended. SSE requires Nextcloud to decrypt the file before sending it to the user, completely destroying the Zero-Egress direct streaming architecture.

---
*Built for extreme scale.*
