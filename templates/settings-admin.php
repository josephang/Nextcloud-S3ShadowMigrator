
<div id="s3shadowmigrator-settings" class="section">
    <h2><?php p($l->t('S3 Shadow Migrator')); ?></h2>
    <p>
        <?php p($l->t('Configure the background migration to S3. Note: The target S3 bucket must be mounted as an External Storage in Nextcloud first.')); ?>
    </p>

    <div class="s3shadowmigrator-setting-group">
        <label for="s3sm-auto-upload">
            <input type="checkbox" id="s3sm-auto-upload" name="auto_upload_enabled" value="yes" <?php if ($_['auto_upload_enabled'] === 'yes') p('checked'); ?> />
            <?php p($l->t('Enable Continuous Background Daemon')); ?>
        </label>
        <br/><br/>

        <label for="s3sm-mount-id"><?php p($l->t('Target Amazon S3 Mount')); ?></label>
        <select id="s3sm-mount-id" name="s3_mount_id">
            <option value="0">-- Select Mount --</option>
            <?php foreach ($_['available_mounts'] as $mount): ?>
                <option value="<?php p($mount['mount_id']); ?>" <?php if ((string)$_['s3_mount_id'] === (string)$mount['mount_id']) p('selected'); ?>>
                    <?php p($mount['mount_point']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <br/><br/>

        <label><?php p($l->t('Exclusion/Inclusion Mode')); ?></label><br/>
        <label>
            <input type="radio" name="exclusion_mode" value="blacklist" <?php if ($_['exclusion_mode'] === 'blacklist') p('checked'); ?>>
            Blacklist (Migrate everyone EXCEPT selected)
        </label><br/>
        <label>
            <input type="radio" name="exclusion_mode" value="whitelist" <?php if ($_['exclusion_mode'] === 'whitelist') p('checked'); ?>>
            Whitelist (Migrate ONLY selected)
        </label>
        <br/><br/>

        <label><?php p($l->t('Select Users and Groups')); ?></label>
        <?php $excludedArray = explode(',', $_['excluded_users']); ?>
        <div style="border: 1px solid var(--color-border, #ccc); border-radius: 5px; max-height: 260px; overflow-y: auto; padding: 12px; width: 320px; background: var(--color-main-background, #fff); color: var(--color-main-text, #222); box-sizing: border-box;">
            <strong>Users</strong><br/>
            <?php foreach ($_['available_users'] as $u): ?>
                <label style="display:block; margin-left: 10px;">
                    <input type="checkbox" class="s3sm-user-checkbox" value="user::<?php p($u); ?>" <?php if (in_array("user::$u", $excludedArray)) p('checked'); ?>> <?php p($u); ?>
                </label>
            <?php endforeach; ?>
            <br/><strong>Groups</strong><br/>
            <?php foreach ($_['available_groups'] as $g): ?>
                <label style="display:block; margin-left: 10px;">
                    <input type="checkbox" class="s3sm-user-checkbox" value="group::<?php p($g); ?>" <?php if (in_array("group::$g", $excludedArray)) p('checked'); ?>> <?php p($g); ?>
                </label>
            <?php endforeach; ?>
        </div>
        <br/><br/>

        <label for="s3sm-throttle-mode"><?php p($l->t('Bandwidth Throttle')); ?></label>
        <select id="s3sm-throttle-mode" name="throttle_mode" onchange="document.getElementById('custom-throttle-container').style.display = this.value === 'custom' ? 'block' : 'none';">
            <option value="unlimited" <?php if ($_['throttle_mode'] === 'unlimited') p('selected'); ?>>Aggressive (Unlimited)</option>
            <option value="balanced" <?php if ($_['throttle_mode'] === 'balanced') p('selected'); ?>>Balanced (~50MB/s)</option>
            <option value="gentle" <?php if ($_['throttle_mode'] === 'gentle') p('selected'); ?>>Gentle (~10MB/s)</option>
            <option value="custom" <?php if ($_['throttle_mode'] === 'custom') p('selected'); ?>>Custom</option>
        </select>
        
        <div id="custom-throttle-container" style="display: <?php p($_['throttle_mode'] === 'custom' ? 'block' : 'none'); ?>; margin-top: 10px;">
            <label for="s3sm-custom-throttle"><?php p($l->t('Max MB/s')); ?></label>
            <input type="number" id="s3sm-custom-throttle" name="custom_throttle_mb" value="<?php p($_['custom_throttle_mb']); ?>" style="width: 80px;" />
        </div>
        <br/>

        <button id="s3sm-save" class="button primary"><?php p($l->t('Save Settings')); ?></button>
        <span id="s3sm-status-msg" class="msg"></span>
    </div>

    <!-- Live Transparency Dashboard -->
    <div style="margin-top: 30px; padding: 15px; background: #0d1117; border: 1px solid var(--color-border, #333); color: #58d68d; font-family: 'Courier New', monospace; border-radius: 6px; box-sizing: border-box;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
            <strong style="color: #f0f0f0; font-size: 13px;">⚡ S3 Migrator Live Output</strong>
            <span id="s3sm-live-status" style="font-size: 11px; color: #888;">Connecting...</span>
        </div>
        <div id="s3sm-live-log-wrapper" style="height: 180px; overflow-y: auto; font-size: 12px;">
            <pre id="s3sm-live-log" style="white-space: pre-wrap; margin: 0; line-height: 1.5;"></pre>
        </div>
    </div>
</div>

<script>
document.getElementById('s3sm-save').addEventListener('click', function() {
    var button = this;
    var statusMsg = document.getElementById('s3sm-status-msg');
    button.disabled = true;
    button.textContent = 'Saving...';
    
    // Get checkbox values
    var checkboxes = document.querySelectorAll('.s3sm-user-checkbox');
    var excludedValues = [];
    checkboxes.forEach(function(cb) {
        if (cb.checked) {
            excludedValues.push(cb.value);
        }
    });

    var exclusionMode = document.querySelector('input[name="exclusion_mode"]:checked').value;
    
    var data = {
        auto_upload_enabled: document.getElementById('s3sm-auto-upload').checked ? 'yes' : 'no',
        s3_mount_id: document.getElementById('s3sm-mount-id').value,
        throttle_mode: document.getElementById('s3sm-throttle-mode').value,
        custom_throttle_mb: document.getElementById('s3sm-custom-throttle').value,
        exclusion_mode: exclusionMode,
        excluded_users: excludedValues.join(',')
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

setInterval(function() {
    fetch(OC.generateUrl('/apps/s3shadowmigrator/status'), {
        headers: { 'requesttoken': OC.requestToken }
    })
    .then(function(r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
    })
    .then(function(data) {
        var logEl = document.getElementById('s3sm-live-log');
        var wrapper = document.getElementById('s3sm-live-log-wrapper');
        var statusEl = document.getElementById('s3sm-live-status');
        var log = data.log || '';
        logEl.textContent = log.trim() !== '' ? log : '⏳ Daemon idle or not yet started. Enable daemon and wait for next cron cycle.';
        statusEl.textContent = 'Live ●';
        statusEl.style.color = '#58d68d';
        // Auto-scroll to bottom
        wrapper.scrollTop = wrapper.scrollHeight;
    })
    .catch(function(err) {
        var statusEl = document.getElementById('s3sm-live-status');
        statusEl.textContent = '✗ Error';
        statusEl.style.color = '#e74c3c';
    });
}, 2000);
</script>
