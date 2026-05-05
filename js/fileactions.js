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

        // Legacy Backbone Files app
        if (typeof OCA.Files.fileActions !== 'undefined') {
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
