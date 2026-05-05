/**
 * S3 Shadow Migrator — Admin Settings JS
 * Loaded as an external script via OCP\Util::addScript() so it executes
 * correctly even when Nextcloud injects the settings section via innerHTML
 * (inline <script> tags are blocked by browsers in that context).
 */
function s3sm_init() {
    var toggleWrap  = document.getElementById('s3sm-toggle-wrap');
    var toggleTrack = document.getElementById('s3sm-toggle-track');
    var toggleKnob  = document.getElementById('s3sm-toggle-knob');
    var toggleLabel = document.getElementById('s3sm-toggle-label');
    var checkbox    = document.getElementById('s3sm-auto-upload');
    var saveBtn     = document.getElementById('s3sm-save');

    if (!toggleWrap || !checkbox || !saveBtn) {
        // Elements not in DOM yet — retry
        setTimeout(s3sm_init, 150);
        return;
    }

    // Prevent duplicate listener registration on repeated calls
    if (toggleWrap.dataset.s3smReady) return;
    toggleWrap.dataset.s3smReady = '1';

    // ── Toggle visual ─────────────────────────────────────────────────────────
    function updateToggleVisual(on) {
        toggleTrack.style.background = on ? '#0082c9' : '#ccc';
        toggleKnob.style.left        = on ? '24px'    : '3px';
        toggleLabel.textContent      = on ? 'Enabled'  : 'Disabled';
    }

    toggleWrap.addEventListener('click', function () {
        var nowOn = !checkbox.checked;
        checkbox.checked = nowOn;
        updateToggleVisual(nowOn);
        if (nowOn) {
            doSave(function () { doTrigger(); });
        } else {
            doSave(null);
        }
    });

    // ── Form data helper ──────────────────────────────────────────────────────
    function getFormData() {
        var cbs = document.querySelectorAll('.s3sm-user-checkbox');
        var excluded = [];
        cbs.forEach(function (cb) { if (cb.checked) excluded.push(cb.value); });
        var modeEl = document.querySelector('input[name="exclusion_mode"]:checked');
        return {
            auto_upload_enabled: checkbox.checked ? 'yes' : 'no',
            s3_mount_id:         document.getElementById('s3sm-mount-id').value,
            throttle_mode:       document.getElementById('s3sm-throttle-mode').value,
            custom_throttle_mb:  document.getElementById('s3sm-custom-throttle').value,
            exclusion_mode:      modeEl ? modeEl.value : 'blacklist',
            excluded_users:      excluded.join(','),
            mirror_paths:        document.getElementById('s3sm-mirror-paths') ? document.getElementById('s3sm-mirror-paths').value : ''
        };
    }

    // ── Mirror Paths Grid Logic ───────────────────────────────────────────────
    var mirrorInput = document.getElementById('s3sm-mirror-input');
    var addMirrorBtn = document.getElementById('s3sm-add-mirror-btn');
    var mirrorGrid = document.getElementById('s3sm-mirror-grid');
    var mirrorPathsHidden = document.getElementById('s3sm-mirror-paths');

    function renderMirrorGrid() {
        if (!mirrorGrid || !mirrorPathsHidden) return;
        mirrorGrid.innerHTML = '';
        var paths = mirrorPathsHidden.value.split(',').map(function(s) { return s.trim(); }).filter(function(s) { return s.length > 0; });
        paths.forEach(function(p) {
            var chip = document.createElement('div');
            chip.style.cssText = 'background:var(--color-primary-light, #e0f2fe); color:var(--color-primary-text-dark, #0369a1); padding:4px 8px; border-radius:16px; font-size:13px; display:flex; align-items:center; gap:6px; border:1px solid var(--color-primary, #0082c9);';
            var text = document.createElement('span');
            text.textContent = p;
            var closeBtn = document.createElement('span');
            closeBtn.textContent = '×';
            closeBtn.style.cssText = 'cursor:pointer; font-weight:bold; font-size:16px; line-height:1; padding:0 2px;';
            closeBtn.addEventListener('click', function() {
                var newPaths = mirrorPathsHidden.value.split(',').map(function(s){return s.trim();}).filter(function(s){return s!==p && s.length>0;});
                mirrorPathsHidden.value = newPaths.join(',');
                renderMirrorGrid();
            });
            chip.appendChild(text);
            chip.appendChild(closeBtn);
            mirrorGrid.appendChild(chip);
        });
    }

    if (addMirrorBtn && mirrorInput && mirrorPathsHidden) {
        addMirrorBtn.addEventListener('click', function(e) {
            e.preventDefault();
            var val = mirrorInput.value.trim();
            if (val) {
                var paths = mirrorPathsHidden.value.split(',').map(function(s){return s.trim();}).filter(function(s){return s.length>0;});
                if (paths.indexOf(val) === -1) {
                    paths.push(val);
                    mirrorPathsHidden.value = paths.join(',');
                }
                mirrorInput.value = '';
                renderMirrorGrid();
            }
        });
        
        mirrorInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                addMirrorBtn.click();
            }
        });

        renderMirrorGrid();
    }

    // ── Save ──────────────────────────────────────────────────────────────────
    function doSave(onSuccess) {
        var statusMsg = document.getElementById('s3sm-status-msg');
        saveBtn.disabled    = true;
        saveBtn.textContent = 'Saving\u2026';
        fetch(OC.generateUrl('/apps/s3shadowmigrator/settings'), {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken },
            body:    JSON.stringify(getFormData())
        })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function () {
            if (statusMsg) { statusMsg.textContent = '\u2713 Saved'; statusMsg.style.color = '#27ae60'; }
            setTimeout(function () { if (statusMsg) statusMsg.textContent = ''; }, 3000);
            if (onSuccess) onSuccess();
        })
        .catch(function (e) {
            if (statusMsg) { statusMsg.textContent = '\u2717 Error: ' + e.message; statusMsg.style.color = '#e74c3c'; }
        })
        .finally(function () {
            saveBtn.disabled    = false;
            saveBtn.textContent = 'Save Settings';
        });
    }

    saveBtn.addEventListener('click', function () { doSave(null); });

    // ── Trigger immediate batch ───────────────────────────────────────────────
    function doTrigger() {
        var logEl    = document.getElementById('s3sm-live-log');
        var statusEl = document.getElementById('s3sm-live-status');
        if (statusEl) { statusEl.textContent = 'Starting\u2026'; statusEl.style.color = '#f39c12'; }
        if (logEl)    { logEl.textContent = '\u23f3 Triggering migration batch\u2026\n'; }
        fetch(OC.generateUrl('/apps/s3shadowmigrator/trigger'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken },
            body: JSON.stringify({})
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (logEl && data.log && data.log.trim()) {
                logEl.textContent = data.log;
                var w = document.getElementById('s3sm-live-log-wrapper');
                if (w) w.scrollTop = w.scrollHeight;
            }
        })
        .catch(function (e) { if (logEl) logEl.textContent = '\u2717 Trigger error: ' + e.message; });
    }

    // ── Live log poll every 2s ────────────────────────────────────────────────
    if (!window.s3sm_polling) {
        window.s3sm_polling = true;
        setInterval(function () {
            fetch(OC.generateUrl('/apps/s3shadowmigrator/status'), {
                headers: { 'requesttoken': OC.requestToken }
            })
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(function (data) {
                var logEl    = document.getElementById('s3sm-live-log');
                var wrapper  = document.getElementById('s3sm-live-log-wrapper');
                var statusEl = document.getElementById('s3sm-live-status');
                var log = data.log || '';
                if (logEl) logEl.textContent = log.trim() ? log : '\u23f3 Daemon idle or not yet started. Enable daemon and wait for next cron cycle.';
                if (statusEl) { statusEl.textContent = 'Live \u25cf'; statusEl.style.color = '#58d68d'; }
                if (wrapper) wrapper.scrollTop = wrapper.scrollHeight;
            })
            .catch(function () {
                var statusEl = document.getElementById('s3sm-live-status');
                if (statusEl) { statusEl.textContent = '\u2717 Error'; statusEl.style.color = '#e74c3c'; }
            });
        }, 2000);
    }
}

// Start immediately — if elements aren't present yet, the function retries
s3sm_init();
