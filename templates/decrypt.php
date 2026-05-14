<?php
/** @var \OCP\IConfig $config */
/** @var string $vaultKey */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vault Decryptor — CloudKingdom</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            min-height: 100vh;
            background: #0f0f13;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Inter', system-ui, sans-serif;
            color: #e2e8f0;
        }
        .card {
            background: #1a1a24;
            border: 1px solid #2d2d3f;
            border-radius: 16px;
            padding: 2.5rem;
            width: 100%;
            max-width: 520px;
            box-shadow: 0 25px 60px rgba(0,0,0,0.5);
        }
        .logo { display: flex; align-items: center; gap: 12px; margin-bottom: 2rem; }
        .logo-icon {
            width: 44px; height: 44px;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 22px;
        }
        h1 { font-size: 1.25rem; font-weight: 600; color: #f1f5f9; }
        p.sub { font-size: 0.8rem; color: #64748b; margin-top: 2px; }

        .drop-zone {
            border: 2px dashed #2d2d3f;
            border-radius: 12px;
            padding: 2.5rem 1.5rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            margin-bottom: 1.5rem;
        }
        .drop-zone:hover, .drop-zone.drag-over {
            border-color: #6366f1;
            background: rgba(99,102,241,0.05);
        }
        .drop-zone .icon { font-size: 2.5rem; margin-bottom: 0.75rem; }
        .drop-zone p { color: #94a3b8; font-size: 0.9rem; }
        .drop-zone strong { color: #e2e8f0; }

        .key-row {
            display: flex; gap: 8px; margin-bottom: 1.25rem;
        }
        .key-row input {
            flex: 1;
            background: #0f0f13;
            border: 1px solid #2d2d3f;
            border-radius: 8px;
            padding: 0.6rem 0.875rem;
            color: #e2e8f0;
            font-family: 'Courier New', monospace;
            font-size: 0.75rem;
            outline: none;
            transition: border-color 0.2s;
        }
        .key-row input:focus { border-color: #6366f1; }

        .progress-area { display: none; margin-bottom: 1.25rem; }
        .progress-label { font-size: 0.8rem; color: #94a3b8; margin-bottom: 6px; }
        .progress-bar { height: 6px; background: #2d2d3f; border-radius: 3px; overflow: hidden; }
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #6366f1, #8b5cf6);
            border-radius: 3px;
            width: 0%;
            transition: width 0.1s;
        }

        .btn {
            width: 100%;
            padding: 0.75rem;
            border: none;
            border-radius: 10px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-primary {
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: white;
        }
        .btn-primary:hover { opacity: 0.9; transform: translateY(-1px); }
        .btn-primary:disabled { opacity: 0.4; cursor: not-allowed; transform: none; }

        .status {
            margin-top: 1rem;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            font-size: 0.85rem;
            display: none;
        }
        .status.error { background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3); color: #fca5a5; }
        .status.success { background: rgba(34,197,94,0.1); border: 1px solid rgba(34,197,94,0.3); color: #86efac; }

        #file-input { display: none; }
    </style>
</head>
<body>
<div class="card">
    <div class="logo">
        <div class="logo-icon">🔓</div>
        <div>
            <h1>Vault Decryptor</h1>
            <p class="sub">AES-256-CTR · Client-side · Zero server contact</p>
        </div>
    </div>

    <div class="drop-zone" id="drop-zone">
        <div class="icon">📁</div>
        <p><strong>Drop your .enc file here</strong></p>
        <p>or click to browse</p>
    </div>
    <input type="file" id="file-input" accept=".enc">

    <div class="key-row">
        <input type="text" id="vault-key" placeholder="AES-256 hex key (64 chars)"
               value="<?= htmlspecialchars($vaultKey ?? '') ?>">
    </div>

    <div class="progress-area" id="progress-area">
        <div class="progress-label" id="progress-label">Decrypting…</div>
        <div class="progress-bar"><div class="progress-fill" id="progress-fill"></div></div>
    </div>

    <button class="btn btn-primary" id="decrypt-btn" disabled>Decrypt &amp; Download</button>
    <div class="status" id="status"></div>
</div>

<script>
const dropZone  = document.getElementById('drop-zone');
const fileInput = document.getElementById('file-input');
const keyInput  = document.getElementById('vault-key');
const btn       = document.getElementById('decrypt-btn');
const status    = document.getElementById('status');
const progressArea = document.getElementById('progress-area');
const progressFill = document.getElementById('progress-fill');
const progressLabel = document.getElementById('progress-label');

let selectedFile = null;

function setFile(file) {
    selectedFile = file;
    dropZone.querySelector('p strong').textContent = file.name;
    dropZone.querySelector('p:last-child').textContent = (file.size / 1024 / 1024).toFixed(2) + ' MB';
    btn.disabled = false;
}

dropZone.addEventListener('click', () => fileInput.click());
fileInput.addEventListener('change', e => e.target.files[0] && setFile(e.target.files[0]));
dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('drag-over'); });
dropZone.addEventListener('dragleave', () => dropZone.classList.remove('drag-over'));
dropZone.addEventListener('drop', e => {
    e.preventDefault();
    dropZone.classList.remove('drag-over');
    e.dataTransfer.files[0] && setFile(e.dataTransfer.files[0]);
});

function showStatus(msg, type) {
    status.textContent = msg;
    status.className = 'status ' + type;
    status.style.display = 'block';
}

btn.addEventListener('click', async () => {
    if (!selectedFile) return;
    const keyHex = keyInput.value.trim();
    if (keyHex.length !== 64 || !/^[0-9a-fA-F]+$/.test(keyHex)) {
        showStatus('Invalid key — must be 64 hex characters (32 bytes).', 'error');
        return;
    }

    btn.disabled = true;
    status.style.display = 'none';
    progressArea.style.display = 'block';
    progressLabel.textContent = 'Reading file…';
    progressFill.style.width = '5%';

    try {
        // Read the encrypted file
        const encBuffer = await selectedFile.arrayBuffer();
        const encBytes  = new Uint8Array(encBuffer);

        // First 32 bytes are the IV hex string (ASCII), remaining are ciphertext
        const ivHex  = new TextDecoder().decode(encBytes.slice(0, 32));
        const ivBytes = hexToBytes(ivHex);
        const ciphertext = encBytes.slice(32);

        progressLabel.textContent = 'Decrypting…';
        progressFill.style.width = '40%';

        // Import key
        const keyBytes = hexToBytes(keyHex);
        const cryptoKey = await crypto.subtle.importKey(
            'raw', keyBytes, { name: 'AES-CTR' }, false, ['decrypt']
        );

        progressFill.style.width = '60%';

        // Decrypt — AES-256-CTR, 128-bit counter (matching OpenSSL enc -aes-256-ctr)
        const decrypted = await crypto.subtle.decrypt(
            { name: 'AES-CTR', counter: ivBytes, length: 128 },
            cryptoKey,
            ciphertext
        );

        progressFill.style.width = '90%';
        progressLabel.textContent = 'Preparing download…';

        // Strip .enc from filename
        const outName = selectedFile.name.endsWith('.enc')
            ? selectedFile.name.slice(0, -4)
            : selectedFile.name + '.decrypted';

        const blob = new Blob([decrypted]);
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href = url; a.download = outName; a.click();
        URL.revokeObjectURL(url);

        progressFill.style.width = '100%';
        showStatus(`✅ Decrypted successfully → ${outName}`, 'success');
    } catch (err) {
        showStatus('❌ Decryption failed: ' + err.message, 'error');
    } finally {
        btn.disabled = false;
    }
});

function hexToBytes(hex) {
    const bytes = new Uint8Array(hex.length / 2);
    for (let i = 0; i < bytes.length; i++)
        bytes[i] = parseInt(hex.substr(i * 2, 2), 16);
    return bytes;
}
</script>
</body>
</html>
