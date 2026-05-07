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
4. **Hybrid Vault Encryption**
   Any folder named `EncryptedVault` automatically triggers a streaming hardware-accelerated OpenSSL AES-256-CBC encryption pipeline. The Azure server encrypts the files on the fly before pushing them to Backblaze. When downloaded, the server automatically bypasses the 302 redirect and proxies the stream to securely decrypt it in real-time, delivering native selective Server-Side encryption without ruining Zero-Egress for the rest of the server!
5. **Self-Healing System (v1.1.0+)**
   An autonomous hourly background job audits all database-tracked files and scans the S3 bucket via paginated `ListObjectsV2`. It automatically detects and recovers from 5 specific corruption states (like locally overwritten sparse files, orphaned S3 objects, and 0-byte upload failures) without destroying data. Lost files are automatically flagged with `status='lost'` for administrative review.
6. **Dynamic Bandwidth Throttling**
   The migrator daemon includes a real-time micro-sleep engine that dynamically pauses between uploads to perfectly match your desired egress limit (e.g., 50MB/s Balanced, 10MB/s Gentle, or Custom values).
7. **Redis Transparency Dashboard**
   Live migration logs are piped directly into Nextcloud's `IMemcache` (Redis/APCu) allowing the Nextcloud Admin UI to stream the daemon's terminal output in real-time without file-locking clashes or excessive database writes.

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

## Administrative UI & Inclusions/Exclusions

The migrator features a rich graphical interface in Nextcloud's Administrative settings:
- **Exclusion/Inclusion Toggle:** Dynamically switch between **Blacklist Mode** (migrate everyone *except* the selected users/groups) or **Whitelist Mode** (migrate *only* the selected users/groups).
- **Checklist UX:** Easily browse and select target Users and Groups from a scrollable checklist without having to edit complex configuration files.
- **Real-Time Terminal:** Monitor the background daemon's live progress via the embedded Redis dashboard directly in your browser.

---

## Operations & Maintenance

Because this app manipulates physical disk allocations behind Nextcloud's back, standard Nextcloud operations carry new implications:

### 1. Backups & Restorations
**Do NOT use `rsync` or `tar` for backups without special flags.** Since sparse files report their apparent size rather than physical size, standard backup tools will hydrate the 0-byte files, inflating a 1GB drive back to 50TB and instantly crashing your server.
- Use `rsync --sparse` (`-S`) to preserve the 0-byte holes during migration.
- If you suffer data loss, you must restore the `oc_s3shadow_files` table along with the database.

### 2. Safely Disabling the App
You cannot simply disable the app if you have sparse files. If you disable the app, the Storage Wrapper is removed, and Nextcloud will serve 0-byte null files to your users. 
To safely uninstall:
1. Disable the Migration Daemon in the UI settings.
2. Run `occ s3shadowmigrator:restore-user` for all users to hydrate files back from S3.
3. Once `oc_s3shadow_files` is empty, you can safely disable the app.

### 3. Mirror Mode (`mirror_paths`)
By default, the migrator creates 0-byte sparse files. However, for certain paths (like Obsidian vaults or text notes), you may want the physical file to remain intact on disk while still being backed up to S3. 
- You can define `mirror_paths` in the settings (e.g., `Notes/`). 
- Files matching this path are uploaded to S3 but **not** truncated. They remain fully allocated on disk, allowing fast local editing.

### 4. Reading the Live Log
The Admin UI provides a streaming log of the background daemon:
- `✓` indicates a successful upload and sparse truncation.
- `🔧 Corrupt-A` means the SelfHealer detected a file that should be sparse but has real content (user overwrote it). The Healer automatically re-sparsed it.
- `♻ Corrupt-C` means the SelfHealer detected a missing S3 object. It automatically removed the sparse mark so the migrator will re-upload it.

---

## Security & Encryption
- **Hybrid Vault Encryption:** Create any folder named `EncryptedVault` and the S3 Migrator will natively encrypt its contents using an auto-generated AES-256 master key (stored in the Nextcloud database via `occ config:app:set s3shadowmigrator vault_key`). Files in this folder are encrypted at rest in S3 and seamlessly decrypted by the Azure server upon download.
- **End-to-End Encryption (E2EE):** Fully supported. Users must use the Nextcloud Mobile/Desktop clients to encrypt their files locally. The encrypted blobs are seamlessly offloaded to S3 via the migrator.
- **Standard Server-Side Encryption (SSE):** Not Recommended. Native Nextcloud SSE requires encrypting every file on the server, completely destroying the Zero-Egress direct streaming architecture. Use the Hybrid Vault feature instead.

---
*Built for extreme scale.*
