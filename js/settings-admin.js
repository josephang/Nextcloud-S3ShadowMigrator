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
            excluded_users:      excluded.join(',')
        };
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
