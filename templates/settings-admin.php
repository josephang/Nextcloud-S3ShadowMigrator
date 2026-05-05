
<div id="s3shadowmigrator-settings" class="section">
    <h2><?php p($l->t('S3 Shadow Migrator')); ?></h2>
    <p style="color: var(--color-text-lighter);">
        <?php p($l->t('Configure the background migration to S3. The target S3 bucket must be mounted as an External Storage in Nextcloud first.')); ?>
    </p>

    <!-- ═══════════════════════════════════════════════════
         VAULT INFO — top of page, full-width, non-intrusive
         ═══════════════════════════════════════════════════ -->
    <div style="background: var(--color-background-dark, #f0f4f8); border-left: 4px solid #0082c9; padding: 12px 16px; margin-bottom: 24px; border-radius: 4px; font-size: 13px; color: var(--color-main-text, #333);">
        <strong style="color: #0082c9;">🔐 Hybrid Vault Encryption</strong><br/>
        Any folder named <code>EncryptedVault</code> will automatically trigger hardware-accelerated <strong>OpenSSL AES-256-CBC</strong> encryption during migration. Files are encrypted at rest in S3 and seamlessly decrypted on the fly when downloaded — without disabling Zero-Egress for any other folder.<br/>
        <span style="color: #c0392b; font-size: 12px; margin-top: 4px; display: inline-block;">
            ⚠️ <strong>Egress fee notice:</strong> Vault files are decrypted server-side on download, meaning they stream through this server rather than redirecting directly to S3. This <em>will</em> consume server bandwidth and may incur egress fees from your S3 provider for that data path.
        </span>
    </div>

    <div class="s3shadowmigrator-setting-group">

        <!-- ── Daemon Toggle ─────────────────────────────── -->
        <div style="margin-bottom: 20px;">
            <strong style="display: block; margin-bottom: 6px;"><?php p($l->t('Background Migration Daemon')); ?></strong>
            <div id="s3sm-toggle-wrap" style="display:inline-flex; align-items:center; gap: 12px; cursor: pointer; user-select: none;">
                <div id="s3sm-toggle-track" style="
                    position: relative; display: inline-block; width: 46px; height: 24px;
                    background: <?php p($_['auto_upload_enabled'] === 'yes' ? '#0082c9' : '#ccc'); ?>;
                    border-radius: 24px; transition: background 0.2s; flex-shrink: 0;">
                    <div id="s3sm-toggle-knob" style="
                        position: absolute; top: 3px;
                        left: <?php p($_['auto_upload_enabled'] === 'yes' ? '24px' : '3px'); ?>;
                        width: 18px; height: 18px; background: white;
                        border-radius: 50%; transition: left 0.2s; box-shadow: 0 1px 3px rgba(0,0,0,.3);">
                    </div>
                </div>
                <span id="s3sm-toggle-label" style="font-weight: 500; color: var(--color-main-text);">
                    <?php p($_['auto_upload_enabled'] === 'yes' ? 'Enabled' : 'Disabled'); ?>
                </span>
                <input type="checkbox" id="s3sm-auto-upload" name="auto_upload_enabled"
                       value="yes" style="display:none"
                       <?php if ($_['auto_upload_enabled'] === 'yes') p('checked'); ?> />
            </div>
            <p style="font-size: 12px; color: var(--color-text-lighter); margin-top: 4px;">
                When enabled, the daemon runs continuously in the background and immediately begins migrating files on toggle.
            </p>
        </div>

        <!-- ── S3 Mount ──────────────────────────────────── -->
        <label for="s3sm-mount-id" style="font-weight:500;"><?php p($l->t('Target Amazon S3 Mount')); ?></label><br/>
        <select id="s3sm-mount-id" name="s3_mount_id" style="margin-top: 4px; min-width: 220px;">
            <option value="0">-- Select Mount --</option>
            <?php foreach ($_['available_mounts'] as $mount): ?>
                <option value="<?php p($mount['mount_id']); ?>" <?php if ((string)$_['s3_mount_id'] === (string)$mount['mount_id']) p('selected'); ?>>
                    <?php p($mount['mount_point']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <br/><br/>

        <!-- ── Exclusion Mode ────────────────────────────── -->
        <label style="font-weight:500;"><?php p($l->t('Migration Mode')); ?></label><br/>
        <label style="display:inline-flex; align-items:center; gap:6px; margin-top:4px;">
            <input type="radio" name="exclusion_mode" value="blacklist" <?php if ($_['exclusion_mode'] === 'blacklist') p('checked'); ?>>
            <span>Blacklist — migrate everyone <em>except</em> selected</span>
        </label><br/>
        <label style="display:inline-flex; align-items:center; gap:6px; margin-top:4px;">
            <input type="radio" name="exclusion_mode" value="whitelist" <?php if ($_['exclusion_mode'] === 'whitelist') p('checked'); ?>>
            <span>Whitelist — migrate <em>only</em> selected</span>
        </label>
        <br/><br/>

        <!-- ── User/Group Checklist ──────────────────────── -->
        <label style="font-weight:500;"><?php p($l->t('Select Users and Groups')); ?></label>
        <?php $excludedArray = explode(',', $_['excluded_users']); ?>
        <div style="border: 1px solid var(--color-border, #ccc); border-radius: 5px; max-height: 260px; overflow-y: auto; padding: 12px; width: 320px; background: var(--color-main-background, #fff); color: var(--color-main-text, #222); box-sizing: border-box; margin-top: 4px;">
            <strong>Users</strong><br/>
            <?php foreach ($_['available_users'] as $u): ?>
                <label style="display:block; margin: 2px 0 2px 10px;">
                    <input type="checkbox" class="s3sm-user-checkbox" value="user::<?php p($u); ?>" <?php if (in_array("user::$u", $excludedArray)) p('checked'); ?>> <?php p($u); ?>
                </label>
            <?php endforeach; ?>
            <br/><strong>Groups</strong><br/>
            <?php foreach ($_['available_groups'] as $g): ?>
                <label style="display:block; margin: 2px 0 2px 10px;">
                    <input type="checkbox" class="s3sm-user-checkbox" value="group::<?php p($g); ?>" <?php if (in_array("group::$g", $excludedArray)) p('checked'); ?>> <?php p($g); ?>
                </label>
            <?php endforeach; ?>
        </div>
        <br/>

        <!-- ── Bandwidth Throttle ────────────────────────── -->
        <label for="s3sm-throttle-mode" style="font-weight:500;"><?php p($l->t('Bandwidth Throttle')); ?></label><br/>
        <select id="s3sm-throttle-mode" name="throttle_mode" style="margin-top:4px;" onchange="document.getElementById('custom-throttle-container').style.display = this.value === 'custom' ? 'flex' : 'none';">
            <option value="unlimited" <?php if ($_['throttle_mode'] === 'unlimited') p('selected'); ?>>Aggressive (Unlimited)</option>
            <option value="balanced"  <?php if ($_['throttle_mode'] === 'balanced')  p('selected'); ?>>Balanced (~50 MB/s)</option>
            <option value="gentle"    <?php if ($_['throttle_mode'] === 'gentle')    p('selected'); ?>>Gentle (~10 MB/s)</option>
            <option value="custom"    <?php if ($_['throttle_mode'] === 'custom')    p('selected'); ?>>Custom</option>
        </select>
        <div id="custom-throttle-container" style="display: <?php p($_['throttle_mode'] === 'custom' ? 'flex' : 'none'); ?>; align-items:center; gap: 8px; margin-top: 8px;">
            <label for="s3sm-custom-throttle"><?php p($l->t('Max MB/s')); ?></label>
            <input type="number" id="s3sm-custom-throttle" name="custom_throttle_mb"
                   value="<?php p($_['custom_throttle_mb']); ?>" style="width: 80px;" min="0.1" step="0.1" />
        </div>
        <br/>

        <!-- ── Save Button ───────────────────────────────── -->
        <button id="s3sm-save" class="button primary"><?php p($l->t('Save Settings')); ?></button>
        <span id="s3sm-status-msg" style="margin-left: 10px; font-size: 13px;"></span>
    </div>

    <!-- ═══════════════════════════════════════════════════
         LIVE TRANSPARENCY DASHBOARD
         ═══════════════════════════════════════════════════ -->
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
(function() {

    // ── Toggle Switch ─────────────────────────────────────────────────────────
    var toggleWrap  = document.getElementById('s3sm-toggle-wrap');
    var toggleTrack = document.getElementById('s3sm-toggle-track');
    var toggleKnob  = document.getElementById('s3sm-toggle-knob');
    var toggleLabel = document.getElementById('s3sm-toggle-label');
    var checkbox    = document.getElementById('s3sm-auto-upload');

    function setToggleState(on, triggerMigration) {
        checkbox.checked    = on;
        toggleTrack.style.background = on ? '#0082c9' : '#ccc';
        toggleKnob.style.left        = on ? '24px'    : '3px';
        toggleLabel.textContent      = on ? 'Enabled'  : 'Disabled';

        if (on && triggerMigration) {
            // Save first, then kick off an immediate batch
            saveSettings(function() {
                triggerBatch();
            });
        }
    }

    toggleWrap.addEventListener('click', function() {
        var nowOn = !checkbox.checked;
        setToggleState(nowOn, true);
    });

    // ── Save Settings ─────────────────────────────────────────────────────────
    function getFormData() {
        var checkboxes = document.querySelectorAll('.s3sm-user-checkbox');
        var excluded   = [];
        checkboxes.forEach(function(cb) { if (cb.checked) excluded.push(cb.value); });

        var modeEl = document.querySelector('input[name="exclusion_mode"]:checked');

        return {
            auto_upload_enabled: checkbox.checked ? 'yes' : 'no',
            s3_mount_id:         document.getElementById('s3sm-mount-id').value,
            throttle_mode:       document.getElementById('s3sm-throttle-mode').value,
            custom_throttle_mb:  document.getElementById('s3sm-custom-throttle').value,
            exclusion_mode:      modeEl ? modeEl.value : 'blacklist',
            excluded_users:      excluded.join(',')
        };
    }

    function saveSettings(onSuccess) {
        var btn       = document.getElementById('s3sm-save');
        var statusMsg = document.getElementById('s3sm-status-msg');
        btn.disabled      = true;
        btn.textContent   = 'Saving…';

        fetch(OC.generateUrl('/apps/s3shadowmigrator/settings'), {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken },
            body:    JSON.stringify(getFormData())
        })
        .then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function() {
            statusMsg.textContent  = '✓ Saved';
            statusMsg.style.color  = '#27ae60';
            setTimeout(function() { statusMsg.textContent = ''; }, 3000);
            if (onSuccess) onSuccess();
        })
        .catch(function(e) {
            statusMsg.textContent = '✗ Error saving: ' + e.message;
            statusMsg.style.color = '#e74c3c';
        })
        .finally(function() {
            btn.disabled    = false;
            btn.textContent = 'Save Settings';
        });
    }

    document.getElementById('s3sm-save').addEventListener('click', function() {
        saveSettings(null);
    });

    // ── Immediate Trigger ─────────────────────────────────────────────────────
    function triggerBatch() {
        var statusEl = document.getElementById('s3sm-live-status');
        statusEl.textContent    = 'Starting…';
        statusEl.style.color    = '#f39c12';

        var logEl = document.getElementById('s3sm-live-log');
        logEl.textContent = '⏳ Triggering migration batch…\n';

        fetch(OC.generateUrl('/apps/s3shadowmigrator/trigger'), {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken },
            body:    JSON.stringify({})
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.log && data.log.trim() !== '') {
                logEl.textContent = data.log;
            } else {
                logEl.textContent = '✓ Daemon started. Polling for updates…';
            }
            var wrapper = document.getElementById('s3sm-live-log-wrapper');
            wrapper.scrollTop = wrapper.scrollHeight;
        })
        .catch(function(e) {
            logEl.textContent = '✗ Trigger failed: ' + e.message;
        });
    }

    // ── Live Log Poll (every 2s) ──────────────────────────────────────────────
    setInterval(function() {
        fetch(OC.generateUrl('/apps/s3shadowmigrator/status'), {
            headers: { 'requesttoken': OC.requestToken }
        })
        .then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function(data) {
            var logEl   = document.getElementById('s3sm-live-log');
            var wrapper = document.getElementById('s3sm-live-log-wrapper');
            var statusEl = document.getElementById('s3sm-live-status');
            var log = data.log || '';
            logEl.textContent     = log.trim() !== '' ? log : '⏳ Daemon idle or not yet started. Enable daemon and wait for next cron cycle.';
            statusEl.textContent  = 'Live ●';
            statusEl.style.color  = '#58d68d';
            wrapper.scrollTop     = wrapper.scrollHeight;
        })
        .catch(function() {
            var statusEl = document.getElementById('s3sm-live-status');
            statusEl.textContent = '✗ Error';
            statusEl.style.color = '#e74c3c';
        });
    }, 2000);

}());
</script>
