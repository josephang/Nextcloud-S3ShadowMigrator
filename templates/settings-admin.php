<?php script('s3shadowmigrator', 'settings-admin'); ?>
<div id="s3shadowmigrator-settings" class="section">
    <h2><?php p($l->t('S3 Shadow Migrator')); ?></h2>
    <p>
        <?php p($l->t('Configure the background migration to S3. Note: The target S3 bucket must be mounted as an External Storage in Nextcloud first.')); ?>
    </p>

    <div class="s3shadowmigrator-setting-group">
        <label for="s3sm-auto-upload">
            <input type="checkbox" id="s3sm-auto-upload" name="auto_upload_enabled" value="yes" <?php if ($_['auto_upload_enabled'] === 'yes') p('checked'); ?> />
            <?php p($l->t('Enable Auto-Upload via Cron')); ?>
        </label>
        <br/><br/>

        <label for="s3sm-batch-limit"><?php p($l->t('Batch Limit (Files per Cron run)')); ?></label>
        <input type="number" id="s3sm-batch-limit" name="batch_limit_files" value="<?php p($_['batch_limit_files']); ?>" placeholder="500" />
        <br/><br/>

        <label for="s3sm-bucket-identifier"><?php p($l->t('Target S3 Mount Identifier (e.g., s3::my-bucket-name)')); ?></label>
        <input type="text" id="s3sm-bucket-identifier" name="s3_bucket_identifier" value="<?php p($_['s3_bucket_identifier']); ?>" />
        <br/><br/>

        <label for="s3sm-bucket-name"><?php p($l->t('Target S3 Bucket Name')); ?></label>
        <input type="text" id="s3sm-bucket-name" name="s3_bucket_name" value="<?php p($_['s3_bucket_name']); ?>" />
        <br/><br/>
        
        <label for="s3sm-region"><?php p($l->t('S3 Region')); ?></label>
        <input type="text" id="s3sm-region" name="s3_region" value="<?php p($_['s3_region']); ?>" />
        <br/><br/>
        
        <label for="s3sm-endpoint"><?php p($l->t('S3 Endpoint (e.g. https://s3.us-west-004.backblazeb2.com)')); ?></label>
        <input type="text" id="s3sm-endpoint" name="s3_endpoint" value="<?php p($_['s3_endpoint']); ?>" style="width:300px;" />
        <br/><br/>
        
        <label for="s3sm-key"><?php p($l->t('S3 Access Key')); ?></label>
        <input type="text" id="s3sm-key" name="s3_key" value="<?php p($_['s3_key']); ?>" />
        <br/><br/>
        
        <label for="s3sm-secret"><?php p($l->t('S3 Secret Key')); ?></label>
        <input type="password" id="s3sm-secret" name="s3_secret" value="<?php p($_['s3_secret']); ?>" />
        <br/><br/>

        <button id="s3sm-save" class="button primary"><?php p($l->t('Save Settings')); ?></button>
        <span id="s3sm-status-msg" class="msg"></span>
    </div>
</div>

<script>
document.getElementById('s3sm-save').addEventListener('click', function() {
    var button = this;
    var statusMsg = document.getElementById('s3sm-status-msg');
    button.disabled = true;
    button.textContent = 'Saving...';
    
    var data = {
        auto_upload_enabled: document.getElementById('s3sm-auto-upload').checked ? 'yes' : 'no',
        batch_limit_files: document.getElementById('s3sm-batch-limit').value,
        s3_bucket_identifier: document.getElementById('s3sm-bucket-identifier').value,
        s3_bucket_name: document.getElementById('s3sm-bucket-name').value,
        s3_region: document.getElementById('s3sm-region').value,
        s3_endpoint: document.getElementById('s3sm-endpoint').value,
        s3_key: document.getElementById('s3sm-key').value,
        s3_secret: document.getElementById('s3sm-secret').value
    };

    fetch(OC.generateUrl('/apps/s3shadowmigrator/settings'), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'requesttoken': OC.requestToken
        },
        body: JSON.stringify(data)
    }).then(function(response) {
        if (response.ok) {
            statusMsg.textContent = 'Saved successfully.';
            statusMsg.style.color = 'green';
        } else {
            statusMsg.textContent = 'Error saving settings.';
            statusMsg.style.color = 'red';
        }
        setTimeout(function() { statusMsg.textContent = ''; }, 3000);
    }).catch(function(error) {
        statusMsg.textContent = 'Network error.';
        statusMsg.style.color = 'red';
    }).finally(function() {
        button.disabled = false;
        button.textContent = 'Save Settings';
    });
});
</script>
