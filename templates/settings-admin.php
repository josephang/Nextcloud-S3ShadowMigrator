
<div id="s3shadowmigrator-settings" class="section">
    <h2><?php p($l->t('S3 Shadow Migrator')); ?></h2>
    <p style="color: var(--color-text-lighter);">
        <?php p($l->t('Configure the background migration to S3. The target S3 bucket must be mounted as an External Storage in Nextcloud first.')); ?>
    </p>

    <!-- Vault info — always at top, full-width -->
    <div style="background: var(--color-background-dark, #f0f4f8); border-left: 4px solid #0082c9; padding: 12px 16px; margin-bottom: 24px; border-radius: 4px; font-size: 13px; color: var(--color-main-text, #333);">
        <strong style="color: #0082c9;">🔐 Hybrid Vault Encryption</strong><br/>
        Any folder named <code>EncryptedVault</code> triggers hardware-accelerated <strong>OpenSSL AES-256-CBC</strong> encryption during migration. Files are encrypted at rest in S3 and decrypted on the fly on download — without disabling Zero-Egress for any other folder.<br/>
        <span style="color: #c0392b; font-size: 12px; margin-top: 4px; display: inline-block;">
            ⚠️ <strong>Egress notice:</strong> Vault downloads stream through this server (not a direct S3 redirect) and will consume server bandwidth and may incur egress fees from your S3 provider.
        </span>
    </div>

    <div class="s3shadowmigrator-setting-group">

        <!-- Daemon Toggle -->
        <div style="margin-bottom: 20px;">
            <strong style="display: block; margin-bottom: 6px;"><?php p($l->t('Background Migration Daemon')); ?></strong>
            <div id="s3sm-toggle-wrap" style="display:inline-flex; align-items:center; gap:12px; cursor:pointer; user-select:none;">
                <div id="s3sm-toggle-track" style="position:relative; display:inline-block; width:46px; height:24px; background:<?php p($_['auto_upload_enabled'] === 'yes' ? '#0082c9' : '#ccc'); ?>; border-radius:24px; transition:background 0.2s; flex-shrink:0;">
                    <div id="s3sm-toggle-knob" style="position:absolute; top:3px; left:<?php p($_['auto_upload_enabled'] === 'yes' ? '24px' : '3px'); ?>; width:18px; height:18px; background:white; border-radius:50%; transition:left 0.2s; box-shadow:0 1px 3px rgba(0,0,0,.3);"></div>
                </div>
                <span id="s3sm-toggle-label" style="font-weight:500;"><?php p($_['auto_upload_enabled'] === 'yes' ? 'Enabled' : 'Disabled'); ?></span>
                <input type="checkbox" id="s3sm-auto-upload" name="auto_upload_enabled" value="yes" style="display:none" <?php if ($_['auto_upload_enabled'] === 'yes') p('checked'); ?> />
            </div>
            <p style="font-size:12px; color:var(--color-text-lighter); margin-top:4px;">
                When enabled, the daemon runs continuously. Toggling ON immediately saves and starts a migration batch.
            </p>
        </div>

        <!-- S3 Mount -->
        <label for="s3sm-mount-id" style="font-weight:500;"><?php p($l->t('Target S3 Mount')); ?></label><br/>
        <select id="s3sm-mount-id" name="s3_mount_id" style="margin-top:4px; min-width:220px;">
            <option value="0">-- Select Mount --</option>
            <?php foreach ($_['available_mounts'] as $mount): ?>
                <option value="<?php p($mount['mount_id']); ?>" <?php if ((string)$_['s3_mount_id'] === (string)$mount['mount_id']) p('selected'); ?>>
                    <?php p($mount['mount_point']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <br/><br/>

        <!-- Exclusion Mode -->
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

        <!-- User/Group Checklist -->
        <label style="font-weight:500;"><?php p($l->t('Select Users and Groups')); ?></label>
        <?php $excludedArray = explode(',', $_['excluded_users']); ?>
        <div style="border:1px solid var(--color-border,#ccc); border-radius:5px; max-height:260px; overflow-y:auto; padding:12px; width:320px; background:var(--color-main-background,#fff); color:var(--color-main-text,#222); box-sizing:border-box; margin-top:4px;">
            <strong>Users</strong><br/>
            <?php foreach ($_['available_users'] as $u): ?>
                <label style="display:block; margin:2px 0 2px 10px;">
                    <input type="checkbox" class="s3sm-user-checkbox" value="user::<?php p($u); ?>" <?php if (in_array("user::$u", $excludedArray)) p('checked'); ?>> <?php p($u); ?>
                </label>
            <?php endforeach; ?>
            <br/><strong>Groups</strong><br/>
            <?php foreach ($_['available_groups'] as $g): ?>
                <label style="display:block; margin:2px 0 2px 10px;">
                    <input type="checkbox" class="s3sm-user-checkbox" value="group::<?php p($g); ?>" <?php if (in_array("group::$g", $excludedArray)) p('checked'); ?>> <?php p($g); ?>
                </label>
            <?php endforeach; ?>
        </div>
        <!-- Mirror Paths -->
        <label style="font-weight:500;"><?php p($l->t('Mirror Paths (Keep Local)')); ?></label>
        <p style="font-size:12px; color:var(--color-text-lighter); margin-top:2px; margin-bottom:8px;">
            Paths that match these strings will be uploaded to S3 but <strong>will not</strong> be truncated locally (e.g. <code>Notes/</code>).
        </p>
        <div style="display:flex; gap:8px; margin-bottom:8px;">
            <input type="text" id="s3sm-mirror-input" placeholder="e.g. Notes/" style="width:200px;" />
            <button id="s3sm-add-mirror-btn" type="button" class="button" style="margin:0;">+ Add</button>
        </div>
        <div id="s3sm-mirror-grid" style="display:flex; flex-wrap:wrap; gap:8px; max-width:400px; margin-bottom:16px;">
            <!-- Chips injected by JS -->
        </div>
        <input type="hidden" id="s3sm-mirror-paths" name="mirror_paths" value="<?php p($_['mirror_paths']); ?>" />
        <br/>

        <!-- Throttle -->
        <label for="s3sm-throttle-mode" style="font-weight:500;"><?php p($l->t('Bandwidth Throttle')); ?></label><br/>
        <select id="s3sm-throttle-mode" name="throttle_mode" style="margin-top:4px;" onchange="document.getElementById('s3sm-custom-throttle-row').style.display=this.value==='custom'?'flex':'none';">
            <option value="unlimited" <?php if ($_['throttle_mode'] === 'unlimited') p('selected'); ?>>Aggressive (Unlimited)</option>
            <option value="balanced"  <?php if ($_['throttle_mode'] === 'balanced')  p('selected'); ?>>Balanced (~50 MB/s)</option>
            <option value="gentle"    <?php if ($_['throttle_mode'] === 'gentle')    p('selected'); ?>>Gentle (~10 MB/s)</option>
            <option value="custom"    <?php if ($_['throttle_mode'] === 'custom')    p('selected'); ?>>Custom</option>
        </select>
        <div id="s3sm-custom-throttle-row" style="display:<?php p($_['throttle_mode'] === 'custom' ? 'flex' : 'none'); ?>; align-items:center; gap:8px; margin-top:8px;">
            <label for="s3sm-custom-throttle"><?php p($l->t('Max MB/s')); ?></label>
            <input type="number" id="s3sm-custom-throttle" name="custom_throttle_mb" value="<?php p($_['custom_throttle_mb']); ?>" style="width:80px;" min="0.1" step="0.1" />
        </div>
        <br/>

        <button id="s3sm-save" class="button primary"><?php p($l->t('Save Settings')); ?></button>
        <span id="s3sm-status-msg" style="margin-left:10px; font-size:13px;"></span>
    </div>

    <!-- Live Terminal -->
    <div style="margin-top:30px; padding:15px; background:#0d1117; border:1px solid var(--color-border,#333); color:#58d68d; font-family:'Courier New',monospace; border-radius:6px; box-sizing:border-box;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
            <strong style="color:#f0f0f0; font-size:13px;">⚡ S3 Migrator Live Output</strong>
            <span id="s3sm-live-status" style="font-size:11px; color:#888;">Connecting...</span>
        </div>
        <div id="s3sm-live-log-wrapper" style="height:180px; overflow-y:auto; font-size:12px;">
            <pre id="s3sm-live-log" style="white-space:pre-wrap; margin:0; line-height:1.5;"></pre>
        </div>
    </div>
</div>
