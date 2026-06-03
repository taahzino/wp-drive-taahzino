/**
 * WP Drive — Drive Downloader JS
 * Handles: Drive file/folder selection → local folder picker → background download + live progress.
 *
 * Three views rendered inside the same modal (#wpdDownloaderModal):
 *   1. Drive browser   — browse Drive, checkbox-select files & folders
 *   2. Local picker    — browse WP filesystem, pick a destination directory
 *   3. Progress        — live download progress with per-file status
 */
/* global wpDrive */
(function () {
  'use strict';

  const cfg   = window.wpDrive || {};
  const api   = cfg.restBase || '';
  const nonce = cfg.nonce   || '';

  // ============================================================
  // State
  // ============================================================
  let drivePath     = [{ id: 'root', name: 'My Drive' }]; // breadcrumb stack
  let selectedDrive = new Map();  // Map<drive_id, {drive_id, name, type, size, mime}>
  let localPath     = '';         // current local directory (relative to ABSPATH)
  let currentJobId  = null;
  let pollTimer     = null;
  let isDownloading = false;

  // ============================================================
  // DOM refs (stable — elements that are never recreated)
  // ============================================================
  const overlay        = document.getElementById('wpdDownloaderOverlay');
  const dlTitle        = document.getElementById('wpdDlTitle');
  const dlClose        = document.getElementById('wpdDlClose');
  const dlBreadcrumb   = document.getElementById('wpdDlBreadcrumb');
  const dlBody         = document.getElementById('wpdDlBody');
  const dlFooter       = document.getElementById('wpdDlFooter');

  // ============================================================
  // API helper
  // ============================================================
  async function apiFetch(method, endpoint, body) {
    const opts = {
      method,
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
    };
    if (body) opts.body = JSON.stringify(body);
    const res  = await fetch(api + endpoint, opts);
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.message || 'Request failed');
    return data;
  }

  // ============================================================
  // Modal open / close
  // ============================================================
  function openDownloader() {
    drivePath     = [{ id: 'root', name: 'My Drive' }];
    selectedDrive = new Map();
    localPath     = '';
    currentJobId  = null;
    isDownloading = false;

    switchToDriveBrowser();
    showOverlay(true);
    loadDriveFolder('root');
  }

  function closeDownloader() {
    if (isDownloading) return; // Never close mid-download.
    showOverlay(false);
    stopPolling();
  }

  function showOverlay(show) {
    if (!overlay) return;
    overlay.style.display     = show ? 'flex' : 'none';
    document.body.style.overflow = show ? 'hidden' : '';
  }

  // ============================================================
  // VIEW 1: Drive browser (select mode)
  // ============================================================
  function switchToDriveBrowser() {
    if (dlTitle)      dlTitle.textContent = 'Download from Google Drive';
    if (dlBreadcrumb) dlBreadcrumb.style.display = '';

    renderDriveBreadcrumb();
    renderDriveBody('loading');

    renderFooter('drive');
  }

  async function loadDriveFolder(folderId) {
    renderDriveBreadcrumb();
    renderDriveBody('loading');
    updateDriveFooter();

    try {
      const data    = await apiFetch('GET', '/drive/files?folder_id=' + encodeURIComponent(folderId));
      const folders = data.folders || [];
      const files   = data.files   || [];

      if (!folders.length && !files.length) {
        renderDriveBody('empty');
        return;
      }
      renderDriveBody('list', folders, files);
    } catch (err) {
      renderDriveBody('error', [], [], err.message);
    }
  }

  function renderDriveBody(state, folders = [], files = [], errMsg = '') {
    if (!dlBody) return;

    if (state === 'loading') {
      dlBody.innerHTML = `
        <div class="wpd-dl-loading">
          <div class="wpd-spinner"></div>
          <span>Loading Drive…</span>
        </div>`;
      return;
    }

    if (state === 'empty') {
      dlBody.innerHTML = `
        <div class="wpd-dl-empty">
          <span class="wpd-dl-empty-icon">📁</span>
          <span>This folder is empty</span>
        </div>`;
      return;
    }

    if (state === 'error') {
      dlBody.innerHTML = `<p style="color:#ef4444;padding:16px;">${esc(errMsg)}</p>`;
      return;
    }

    // List state — render checkboxable items.
    let html = '<div class="wpd-dl-drive-list">';

    folders.forEach(f => {
      const checked = selectedDrive.has(f.id);
      html += `
        <div class="wpd-dl-drive-item is-folder ${checked ? 'is-selected' : ''}"
             data-id="${esc(f.id)}" data-name="${esc(f.name)}" data-type="folder" data-size="0">
          <input class="wpd-dl-checkbox" type="checkbox" aria-label="Select ${esc(f.name)}" ${checked ? 'checked' : ''}>
          <span class="wpd-dl-item-icon">📁</span>
          <span class="wpd-dl-item-name">${esc(f.name)}</span>
          <span class="wpd-dl-item-nav" title="Open folder" aria-label="Open ${esc(f.name)}">›</span>
        </div>`;
    });

    files.forEach(f => {
      const checked = selectedDrive.has(f.id);
      html += `
        <div class="wpd-dl-drive-item ${checked ? 'is-selected' : ''}"
             data-id="${esc(f.id)}" data-name="${esc(f.name)}" data-type="file"
             data-size="${esc(String(f.size || 0))}" data-mime="${esc(f.mimeType || '')}">
          <input class="wpd-dl-checkbox" type="checkbox" aria-label="Select ${esc(f.name)}" ${checked ? 'checked' : ''}>
          <span class="wpd-dl-item-icon">${driveFileIcon(f.mimeType)}</span>
          <span class="wpd-dl-item-name">${esc(f.name)}</span>
          <span class="wpd-dl-item-size">${formatSize(f.size)}</span>
        </div>`;
    });

    html += '</div>';
    dlBody.innerHTML = html;

    // Attach events.
    dlBody.querySelectorAll('.wpd-dl-drive-item').forEach(el => {
      const id   = el.dataset.id;
      const name = el.dataset.name;
      const type = el.dataset.type;
      const size = parseInt(el.dataset.size || '0', 10);
      const mime = el.dataset.mime || '';
      const cb   = el.querySelector('.wpd-dl-checkbox');

      // Checkbox toggles selection.
      if (cb) {
        cb.addEventListener('change', e => {
          e.stopPropagation();
          toggleDriveSelect(id, name, type, size, mime, cb.checked);
          el.classList.toggle('is-selected', cb.checked);
          updateDriveFooter();
        });
      }

      // Row click also toggles (but not on the nav arrow).
      el.addEventListener('click', e => {
        if (e.target === el.querySelector('.wpd-dl-item-nav') || e.target.tagName === 'INPUT') return;
        const next = !selectedDrive.has(id);
        if (cb) cb.checked = next;
        toggleDriveSelect(id, name, type, size, mime, next);
        el.classList.toggle('is-selected', next);
        updateDriveFooter();
      });

      // Nav arrow (folders only) navigates into folder.
      const navArrow = el.querySelector('.wpd-dl-item-nav');
      if (navArrow && type === 'folder') {
        navArrow.addEventListener('click', e => {
          e.stopPropagation();
          drivePath.push({ id, name });
          loadDriveFolder(id);
        });
      }
    });
  }

  function toggleDriveSelect(id, name, type, size, mime, select) {
    if (select) {
      selectedDrive.set(id, { drive_id: id, name, type, size, mime });
    } else {
      selectedDrive.delete(id);
    }
  }

  function renderDriveBreadcrumb() {
    if (!dlBreadcrumb) return;
    let html = '';
    drivePath.forEach((item, i) => {
      const isCurrent = i === drivePath.length - 1;
      if (i > 0) html += '<span class="wpd-dl-bc-sep">›</span>';
      html += `<span class="wpd-dl-bc-item ${isCurrent ? 'is-current' : ''}" data-index="${i}">${esc(item.name)}</span>`;
    });
    dlBreadcrumb.innerHTML = html;

    dlBreadcrumb.querySelectorAll('.wpd-dl-bc-item:not(.is-current)').forEach(el => {
      el.addEventListener('click', () => {
        const idx = parseInt(el.dataset.index, 10);
        drivePath = drivePath.slice(0, idx + 1);
        loadDriveFolder(drivePath[drivePath.length - 1].id);
      });
    });
  }

  function updateDriveFooter() {
    const count = selectedDrive.size;
    const info  = dlFooter && dlFooter.querySelector('#wpdDlSelCount');
    const btn   = dlFooter && dlFooter.querySelector('#wpdDlContinueBtn');
    if (info) info.textContent = count > 0
      ? `${count} item${count !== 1 ? 's' : ''} selected`
      : 'Select files or folders to download';
    if (btn)  btn.disabled = count === 0;
  }

  // ============================================================
  // VIEW 2: Local folder picker
  // ============================================================
  function switchToLocalPicker() {
    localPath = '';
    if (dlTitle)      dlTitle.textContent = 'Choose download destination';
    if (dlBreadcrumb) dlBreadcrumb.style.display = 'none';

    renderFooter('local');
    loadLocalFolder('');
  }

  async function loadLocalFolder(path) {
    localPath = path;
    renderLocalBreadcrumb(path);
    dlBody.innerHTML = `
      <div class="wpd-dl-loading">
        <div class="wpd-spinner"></div>
        <span>Loading…</span>
      </div>`;
    updateLocalFooter();

    try {
      const data  = await apiFetch('GET', '/local/files?path=' + encodeURIComponent(path));
      const dirs  = (data.items || []).filter(i => i.type === 'dir');

      if (!dirs.length) {
        dlBody.innerHTML = `
          <div class="wpd-dl-empty">
            <span class="wpd-dl-empty-icon">📁</span>
            <span>No subfolders — you can download here</span>
          </div>`;
        return;
      }

      let html = '<div class="wpd-dl-local-list">';
      dirs.forEach(d => {
        html += `
          <div class="wpd-dl-local-item" data-path="${esc(d.path)}" role="button" tabindex="0">
            <span class="wpd-dl-item-icon">📁</span>
            <span class="wpd-dl-item-name">${esc(d.name)}</span>
            <span class="wpd-dl-item-nav">›</span>
          </div>`;
      });
      html += '</div>';
      dlBody.innerHTML = html;

      dlBody.querySelectorAll('.wpd-dl-local-item').forEach(el => {
        const nav = () => loadLocalFolder(el.dataset.path);
        el.addEventListener('click', nav);
        el.addEventListener('keydown', e => e.key === 'Enter' && nav());
      });
    } catch (err) {
      dlBody.innerHTML = `<p style="color:#ef4444;padding:16px;">${esc(err.message)}</p>`;
    }
  }

  function renderLocalBreadcrumb(path) {
    if (!dlBreadcrumb) return;
    dlBreadcrumb.style.display = '';
    const parts = path ? path.split('/') : [];
    let html = `<span class="wpd-dl-bc-item" data-path="" tabindex="0">WordPress Root</span>`;
    parts.forEach((part, i) => {
      const partPath  = parts.slice(0, i + 1).join('/');
      const isCurrent = i === parts.length - 1;
      html += '<span class="wpd-dl-bc-sep">›</span>';
      html += `<span class="wpd-dl-bc-item ${isCurrent ? 'is-current' : ''}" data-path="${esc(partPath)}" tabindex="0">${esc(part)}</span>`;
    });
    dlBreadcrumb.innerHTML = html;

    dlBreadcrumb.querySelectorAll('.wpd-dl-bc-item:not(.is-current)').forEach(el => {
      const nav = () => loadLocalFolder(el.dataset.path);
      el.addEventListener('click', nav);
      el.addEventListener('keydown', e => e.key === 'Enter' && nav());
    });
  }

  function updateLocalFooter() {
    const info = dlFooter && dlFooter.querySelector('#wpdDlDestInfo');
    if (info) info.textContent = localPath || 'WordPress Root';
  }

  // ============================================================
  // VIEW 3: Progress
  // ============================================================
  async function startDownload() {
    const items = [...selectedDrive.values()].map(i => ({
      drive_id: i.drive_id,
      name:     i.name,
      type:     i.type,
      size:     i.size || 0,
    }));

    if (!items.length) return;

    isDownloading = true;
    switchToProgressView(items);

    try {
      const data   = await apiFetch('POST', '/drive/download/start', {
        items,
        destination_path: localPath,
      });

      currentJobId = data.job_id;
      updateProgressMaster(0, data.total || 0, 0, null);
      pollTimer = setTimeout(pollStatus, 1500);
    } catch (err) {
      showDownloadError(err.message);
      isDownloading = false;
    }
  }

  async function pollStatus() {
    if (!currentJobId) return;
    try {
      const data    = await apiFetch('GET', '/drive/download/' + currentJobId + '/status');
      const percent = data.status === 'done' ? 100 : (data.percent || 0);

      updateProgressMaster(data.completed || 0, data.total || 0, percent, data.current_file || null);
      updateProgressFiles(data);

      if (data.status === 'done' || data.status === 'failed') {
        stopPolling();
        showDownloadComplete(data);
        return;
      }
      pollTimer = setTimeout(pollStatus, 800);
    } catch (err) {
      stopPolling();
      showDownloadError(err.message);
    }
  }

  function stopPolling() {
    if (pollTimer) clearTimeout(pollTimer);
    pollTimer = null;
  }

  function switchToProgressView(items) {
    if (dlTitle)      dlTitle.textContent = 'Downloading…';
    if (dlBreadcrumb) dlBreadcrumb.style.display = 'none';
    if (dlFooter)     dlFooter.style.display = 'none';

    const fileItems = items.filter(i => i.type === 'file');

    dlBody.innerHTML = `
      <div class="wpd-progress-view">
        <div class="wpd-progress-header">
          <div class="wpd-progress-title">
            <span class="wpd-mini-spinner"></span>
            Downloading to <strong>${esc(localPath || 'WordPress Root')}</strong>
          </div>
          <div class="wpd-progress-current-file" id="wpdDlCurrentFile"></div>
          <div class="wpd-progress-master">
            <div class="wpd-progress-bar-bg">
              <div class="wpd-progress-bar-fill wpd-progress-bar-animated" id="wpdDlMasterBar" style="width:0%"></div>
            </div>
            <div class="wpd-progress-meta">
              <span id="wpdDlProgressLabel">Waiting for background job…</span>
              <span id="wpdDlProgressPct">0%</span>
            </div>
          </div>
        </div>
        <div class="wpd-progress-files" id="wpdDlProgressFiles">
          ${fileItems.map(i => `
            <div class="wpd-progress-file is-pending">
              <span class="wpd-progress-file-icon">${driveFileIcon(i.mime || '')}</span>
              <span class="wpd-progress-file-name">${esc(i.name)}</span>
              <span class="wpd-progress-file-status">Pending</span>
            </div>`).join('')}
        </div>
      </div>`;
  }

  function updateProgressMaster(completed, total, percent, currentFile) {
    const bar     = document.getElementById('wpdDlMasterBar');
    const label   = document.getElementById('wpdDlProgressLabel');
    const pct     = document.getElementById('wpdDlProgressPct');
    const fileEl  = document.getElementById('wpdDlCurrentFile');

    if (bar)   bar.style.width = percent + '%';
    if (pct)   pct.textContent = percent + '%';
    if (label) {
      label.textContent = total > 0
        ? `${completed} of ${total} file${total !== 1 ? 's' : ''}`
        : 'Preparing…';
    }
    if (fileEl) fileEl.textContent = currentFile ? `Downloading: ${currentFile}` : '';
  }

  function updateProgressFiles(jobData) {
    const filesEl = document.getElementById('wpdDlProgressFiles');
    if (!filesEl || !jobData.items) return;

    const fileItems = (jobData.items || []).filter(i => i.type === 'file');
    const rows = filesEl.querySelectorAll('.wpd-progress-file');

    rows.forEach((row, i) => {
      const item = fileItems[i];
      if (!item) return;

      row.className = 'wpd-progress-file is-' + (item.status || 'pending');
      const statusEl = row.querySelector('.wpd-progress-file-status');
      if (!statusEl) return;

      switch (item.status) {
        case 'done':
          statusEl.innerHTML = '✓ Done';
          break;
        case 'failed':
          statusEl.innerHTML = `✗ ${esc(item.error || 'Failed')}`;
          break;
        case 'running':
          statusEl.innerHTML = item.file_pct !== undefined
            ? `<span class="wpd-mini-spinner"></span> ${item.file_pct}%`
            : '<span class="wpd-mini-spinner"></span> Downloading…';
          break;
        default:
          statusEl.textContent = 'Pending';
      }
    });
  }

  function showDownloadComplete(data) {
    const masterBar = document.getElementById('wpdDlMasterBar');
    if (masterBar) {
      masterBar.classList.remove('wpd-progress-bar-animated');
      masterBar.style.width = '100%';
    }
    if (dlTitle) dlTitle.textContent = 'Download complete';
    if (!dlBody) return;

    const errorCount   = (data.errors || []).length;
    const successCount = (data.completed || 0) - errorCount;
    const hasErrors    = errorCount > 0;

    dlBody.innerHTML = `
      <div class="wpd-upload-done">
        <div class="wpd-upload-done-icon">${hasErrors ? '⚠️' : '✅'}</div>
        <h3>${hasErrors ? 'Download completed with errors' : 'All files downloaded!'}</h3>
        <p>${successCount} file${successCount !== 1 ? 's' : ''} saved to <strong>${esc(localPath || 'WordPress Root')}</strong>.
          ${errorCount ? ` ${errorCount} file${errorCount !== 1 ? 's' : ''} failed.` : ''}</p>
        ${hasErrors ? `<div style="text-align:left;width:100%;max-height:120px;overflow-y:auto;">
          ${(data.errors || []).map(e => `<p style="font-size:12px;color:#dc2626;margin:4px 0;">✗ ${esc(e.name)}: ${esc(e.error)}</p>`).join('')}
        </div>` : ''}
        <div class="wpd-upload-done-actions">
          <button type="button" class="wpd-btn wpd-btn-secondary" id="wpdDlDoneClose">Close</button>
        </div>
      </div>`;

    const closeBtn = document.getElementById('wpdDlDoneClose');
    if (closeBtn) closeBtn.addEventListener('click', () => {
      isDownloading = false;
      closeDownloader();
    });

    isDownloading = false;
  }

  function showDownloadError(msg) {
    if (dlTitle) dlTitle.textContent = 'Download failed';
    if (!dlBody) return;

    dlBody.innerHTML = `
      <div class="wpd-upload-done">
        <div class="wpd-upload-done-icon">❌</div>
        <h3>Download failed</h3>
        <p>${esc(msg)}</p>
        <div class="wpd-upload-done-actions">
          <button type="button" class="wpd-btn wpd-btn-secondary" id="wpdDlErrClose">Close</button>
        </div>
      </div>`;

    const closeBtn = document.getElementById('wpdDlErrClose');
    if (closeBtn) closeBtn.addEventListener('click', () => {
      isDownloading = false;
      closeDownloader();
    });

    isDownloading = false;
  }

  // ============================================================
  // Footer renderer (context-sensitive)
  // ============================================================
  function renderFooter(mode) {
    if (!dlFooter) return;
    dlFooter.style.display = '';

    if (mode === 'drive') {
      dlFooter.innerHTML = `
        <div class="wpd-dl-footer-info" id="wpdDlSelCount">Select files or folders to download</div>
        <div class="wpd-dl-footer-actions">
          <button type="button" class="wpd-btn wpd-btn-ghost" id="wpdDlCancelBtn">Cancel</button>
          <button type="button" class="wpd-btn wpd-btn-primary" id="wpdDlContinueBtn" disabled>
            Choose Destination →
          </button>
        </div>`;

      const cancelBtn   = dlFooter.querySelector('#wpdDlCancelBtn');
      const continueBtn = dlFooter.querySelector('#wpdDlContinueBtn');
      if (cancelBtn)   cancelBtn.addEventListener('click', closeDownloader);
      if (continueBtn) continueBtn.addEventListener('click', switchToLocalPicker);
    } else if (mode === 'local') {
      dlFooter.innerHTML = `
        <div class="wpd-dl-footer-dest">
          <span style="font-size:12px;color:#64748b;">Saving to:</span>
          <span class="wpd-dl-footer-dest-path" id="wpdDlDestInfo">${esc(localPath || 'WordPress Root')}</span>
        </div>
        <div class="wpd-dl-footer-actions">
          <button type="button" class="wpd-btn wpd-btn-ghost" id="wpdDlBackBtn">← Back</button>
          <button type="button" class="wpd-btn wpd-btn-download" id="wpdDlStartBtn">
            ↓ Download Here
            <span class="wpd-spinner"></span>
          </button>
        </div>`;

      const backBtn  = dlFooter.querySelector('#wpdDlBackBtn');
      const startBtn = dlFooter.querySelector('#wpdDlStartBtn');
      if (backBtn)  backBtn.addEventListener('click', switchToDriveBrowser);
      if (startBtn) startBtn.addEventListener('click', () => {
        setLoading(startBtn, true);
        startDownload();
      });
    }
  }

  // ============================================================
  // Helpers
  // ============================================================
  function setLoading(btn, loading) {
    if (!btn) return;
    btn.disabled = loading;
    btn.classList.toggle('is-loading', loading);
  }

  function driveFileIcon(mimeType) {
    const m = mimeType || '';
    if (m.includes('folder'))        return '📁';
    if (m.startsWith('image/'))      return '🖼️';
    if (m.startsWith('video/'))      return '🎬';
    if (m.startsWith('audio/'))      return '🎵';
    if (m.includes('pdf'))           return '📄';
    if (m.includes('document'))      return '📝';
    if (m.includes('spreadsheet'))   return '📊';
    if (m.includes('presentation'))  return '📑';
    return '📎';
  }

  function formatSize(bytes) {
    if (!bytes || bytes <= 0) return '';
    const units = ['B', 'KB', 'MB', 'GB'];
    let i = 0;
    while (bytes >= 1024 && i < units.length - 1) { bytes /= 1024; i++; }
    return (i === 0 ? bytes : bytes.toFixed(1)) + ' ' + units[i];
  }

  function esc(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  // ============================================================
  // Event wiring
  // ============================================================
  document.addEventListener('DOMContentLoaded', () => {
    if (!overlay) return;

    // Close button.
    if (dlClose) dlClose.addEventListener('click', closeDownloader);

    // Backdrop click.
    overlay.addEventListener('click', e => {
      if (e.target === overlay && !isDownloading) closeDownloader();
    });

    // Escape key.
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape' && overlay.style.display !== 'none' && !isDownloading) closeDownloader();
    });

    // Listen for trigger from file manager toolbar.
    document.addEventListener('wpd:open-downloader', () => openDownloader());
  });

}());
