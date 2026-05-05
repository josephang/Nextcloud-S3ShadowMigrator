(function(OCA) {
    OCA.S3ShadowMigrator = OCA.S3ShadowMigrator || {};

    let pollCount = 0;
    function registerS3MigratorAction() {
        if (typeof OCA === 'undefined' || !OCA.Files) {
            pollCount++;
            if (pollCount < 50) {
                setTimeout(registerS3MigratorAction, 100);
            } else {
                console.error('[S3ShadowMigrator] Failed to find OCA.Files after 5 seconds.');
            }
            return;
        }

        // Modern Vue Files app (Nextcloud 28+)
        if (OCA.Files.App && typeof OCA.Files.App.registerFileAction === 'function') {
            console.log('[S3ShadowMigrator] Registering via modern Vue App.registerFileAction');
            OCA.Files.App.registerFileAction({
                id: 's3shadowmigrate',
                displayName: function() { return t('s3shadowmigrator', 'Migrate to Cloud'); },
                iconSvgInline: function() { return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM14 13v4h-4v-4H7l5-5 5 5h-3z"/></svg>'; },
                enabled: function() { return true; },
                exec: function(fileInfo) {
                    var fileId = fileInfo.id || fileInfo.fileid;
                    OC.Notification.showTemporary(t('s3shadowmigrator', 'Migrating ' + fileInfo.name + ' to S3...'));
                    executeMigration(fileId, fileInfo.name);
                }
            });
            return;
        }

        // Legacy Backbone Files app
        if (OCA.Files.fileActions) {
            console.log('[S3ShadowMigrator] Registering via legacy Backbone fileActions');
            OCA.Files.fileActions.registerAction({
                name: 'S3ShadowMigrate',
                displayName: t('s3shadowmigrator', 'Migrate to Cloud'),
                mime: 'all',
                permissions: OC.PERMISSION_READ,
                iconClass: 'icon-upload',
                actionHandler: function(filename, context) {
                    var fileId = context.fileInfoModel ? context.fileInfoModel.get('id') : context.fileId;
                    OC.Notification.showTemporary(t('s3shadowmigrator', 'Migrating ' + filename + ' to S3...'));
                    executeMigration(fileId, filename);
                }
            });
            return;
        }

        pollCount++;
        if (pollCount < 50) {
            setTimeout(registerS3MigratorAction, 100);
        } else {
            console.error('[S3ShadowMigrator] Failed to find a valid file action registration method.');
        }
    }

    function executeMigration(fileId, filename) {
        $.ajax({
            url: OC.generateUrl('/apps/s3shadowmigrator/migrate/{fileId}', {fileId: fileId}),
            type: 'POST',
            success: function(response) {
                if (response.status === 'success') {
                    OC.Notification.showTemporary(t('s3shadowmigrator', 'Successfully migrated ' + filename + ' to S3.'));
                } else {
                    OC.Notification.showTemporary(t('s3shadowmigrator', 'Migration failed: ' + response.message));
                }
            },
            error: function(xhr) {
                var msg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Unknown error occurred.';
                OC.Notification.showTemporary(t('s3shadowmigrator', 'Error migrating ' + filename + ': ' + msg));
            }
        });
    }

    if (document.readyState === 'loading') {
        window.addEventListener('DOMContentLoaded', registerS3MigratorAction);
    } else {
        registerS3MigratorAction();
    }
})(OCA);
