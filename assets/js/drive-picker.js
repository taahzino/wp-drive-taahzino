/**
 * WP Drive — Drive Picker + Upload JS
 * Handles Google Drive folder browsing, upload initiation, and live progress tracking.
 */
/* global wpDrive */
(function () {
  'use strict';

  const cfg   = window.wpDrive || {};
  const api   = cfg.restBase || '';
  const nonce = cfg.nonce || '';

  // ============================================================
  // State
  // ============================================================
  let drivePath = [{ id: 'root', name: 'My Drive' }]; // Stack of { id, name }
  let selectedItems = []; // Items from file manager
  let currentJobId  = null;
  let pollTimer     = null;
  let isUploading   = false;

  // ============================================================
  // DOM refs
  // ============================================================
  // Stable references — elements that are never recreated.
  const overlay       = document.getElementById('wpdPickerOverlay');
  const pickerTitle   = document.getElementById('wpdPickerTitle');
  const closeBtn      = document.getElementById('wpdPickerClose');
  const cancelBtn     = document.getElementById('wpdPickerCancel');
  const uploadHereBtn = document.getElementById('wpdUploadHere');
  const pickerFooter  = document.getElementById('wpdPickerFooter');
  const pickerBody    = document.getElementById('wpdPickerBody');
  const pickerBcNav   = document.getElementById('wpdDriveBreadcrumb');
  const destNameEl    = document.getElementById('wpdDestName');

  // Dynamic lookups — these elements are recreated by resetToFolderBrowser()
  // every time the picker opens, so we must NOT cache them as consts.
  const getDriveLoading = () => document.getElementById('wpdDriveLoading');
  const getDriveList    = () => document.getElementById('wpdDriveList');
  const getDriveEmpty   = () => document.getElementById('wpdDriveEmpty');
  const getPickerBcEl   = () => document.getElementById('wpdDriveBreadcrumb');

  // ============================================================
  // API helpers
  // ============================================================
  async function apiFetch(method, endpoint, body) {
    const opts = {
      method,
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
    };
    if (body) opts.body = JSON.stringify(body);
    const res = await fetch(api + endpoint, opts);
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.message || 'Request failed');
    return data;
  }

  // ============================================================
  // Modal open / close
  // ============================================================
  function openPicker(items) {
    selectedItems = items || [];
    isUploading   = false;
    currentJobId  = null;
    drivePath     = [{ id: 'root', name: 'My Drive' }];

    resetToFolderBrowser();
    showOverlay(true);
    loadDriveFolder('root');
  }

  function closePicker() {
    showOverlay(false);
    stopPolling();
    if (window.wpdFileManager && !isUploading) {
      // Keep selection intact so user can retry.
    }
  }

  function showOverlay(show) {
    if (!overlay) return;
    overlay.style.display = show ? '' : 'none';
    if (show) document.body.style.overflow = 'hidden';
    else      document.body.style.overflow = '';
  }

  // ============================================================
  // Drive folder browser
  // ============================================================
  async function loadDriveFolder(folderId) {
    showDriveState('loading');
    renderPickerBreadcrumb();
    updateDestDisplay();

    try {
      const data = await apiFetch('GET', '/drive/files?folder_id=' + encodeURIComponent(folderId));
      const folders = data.folders || [];
      const files   = data.files   || [];

      if (!folders.length && !files.length) {
        showDriveState('empty');
        return;
      }

      showDriveState('list');
      renderDriveItems(folders, files);
    } catch (err) {
      showDriveState('list');
      const listEl = getDriveList();
      if (listEl) {
        listEl.innerHTML = `<p style="color:#ef4444;padding:16px;">${esc(err.message)}</p>`;
      }
    }
  }

  function renderDriveItems(folders, files) {
    const driveList = getDriveList();
    if (!driveList) return;
    let html = '';

    folders.forEach(folder => {
      html += `
        <div class="wpd-drive-item is-folder" data-id="${esc(folder.id)}" data-name="${esc(folder.name)}" role="button" tabindex="0">
          <span class="wpd-drive-item-icon">📁</span>
          <span class="wpd-drive-item-name">${esc(folder.name)}</span>
          <span class="wpd-drive-item-arrow">›</span>
        </div>`;
    });

    files.forEach(file => {
      html += `
        <div class="wpd-drive-item" data-id="${esc(file.id)}" data-name="${esc(file.name)}" role="listitem">
          <span class="wpd-drive-item-icon">${driveFileIcon(file.mimeType)}</span>
          <span class="wpd-drive-item-name" style="color:#94a3b8;">${esc(file.name)}</span>
        </div>`;
    });

    driveList.innerHTML = html;

    // Folder click: navigate in.
    driveList.querySelectorAll('.is-folder').forEach(el => {
      const navigate = () => {
        drivePath.push({ id: el.dataset.id, name: el.dataset.name });
        loadDriveFolder(el.dataset.id);
      };
      el.addEventListener('click', navigate);
      el.addEventListener('keydown', e => e.key === 'Enter' && navigate());
    });
  }

  function renderPickerBreadcrumb() {
    const bcEl = getPickerBcEl();
    if (!bcEl) return;
    let html = '';
    drivePath.forEach((item, i) => {
      const isCurrent = i === drivePath.length - 1;
      if (i > 0) html += '<span class="wpd-picker-breadcrumb-sep">›</span>';
      html += `<span class="wpd-picker-breadcrumb-item ${isCurrent ? 'is-current' : ''}" 
                     data-index="${i}" tabindex="${isCurrent ? '-1' : '0'}">${esc(item.name)}</span>`;
    });
    bcEl.innerHTML = html;

    bcEl.querySelectorAll('.wpd-picker-breadcrumb-item:not(.is-current)').forEach(el => {
      const jump = () => {
        const idx = parseInt(el.dataset.index, 10);
        drivePath = drivePath.slice(0, idx + 1);
        loadDriveFolder(drivePath[drivePath.length - 1].id);
      };
      el.addEventListener('click', jump);
      el.addEventListener('keydown', e => e.key === 'Enter' && jump());
    });
  }

  function updateDestDisplay() {
    const current = drivePath[drivePath.length - 1];
    if (destNameEl) destNameEl.textContent = current.name;
  }

  function showDriveState(state) {
    const loadingEl = getDriveLoading();
    const listEl    = getDriveList();
    const emptyEl   = getDriveEmpty();
    if (loadingEl) loadingEl.style.display = state === 'loading' ? '' : 'none';
    if (listEl)    listEl.style.display    = state === 'list'    ? '' : 'none';
    if (emptyEl)   emptyEl.style.display   = state === 'empty'   ? '' : 'none';
  }

  // ============================================================
  // Upload flow
  // ============================================================
  async function startUpload() {
    const destFolder = drivePath[drivePath.length - 1];
    if (!selectedItems.length) return;

    isUploading = true;
    switchToProgressView(selectedItems);
    setLoading(uploadHereBtn, true);

    try {
      // /start schedules a WP-Cron job and returns immediately — no timeout risk.
      const data = await apiFetch('POST', '/drive/upload/start', {
        items: selectedItems.map(i => ({ path: i.path, type: i.type })),
        destination_folder_id: destFolder.id,
      });

      currentJobId = data.job_id;
      updateProgressMaster(0, data.total || 0, 0);

      // Begin polling the lightweight /status endpoint.
      pollTimer = setTimeout(pollStatus, 1500);
    } catch (err) {
      showUploadError(err.message);
      isUploading = false;
      setLoading(uploadHereBtn, false);
    }
  }

  /**
   * Polls GET /drive/upload/{job_id}/status every 800 ms.
   * The actual upload runs in a WP-Cron background job — this endpoint
   * simply reads the transient, so it always returns in <100 ms.
   */
  async function pollStatus() {
    if (!currentJobId) return;
    try {
      const data = await apiFetch('GET', '/drive/upload/' + currentJobId + '/status');

      const percent   = data.status === 'done' ? 100 : (data.percent || 0);
      const completed = data.completed || 0;
      const total     = data.total || 0;

      updateProgressMaster(completed, total, percent, data.current_file || null);
      updateProgressFiles(data);

      if (data.status === 'done' || data.status === 'failed') {
        stopPolling();
        showUploadComplete(data);
        return;
      }

      // Keep polling — job is pending or running.
      pollTimer = setTimeout(pollStatus, 800);
    } catch (err) {
      // On 404 (job expired) or network error, stop and show error.
      stopPolling();
      showUploadError(err.message);
    }
  }

  function stopPolling() {
    if (pollTimer) clearTimeout(pollTimer);
    pollTimer = null;
  }

  // ============================================================
  // Progress view
  // ============================================================
  function switchToProgressView(items) {
    if (!pickerBody || !pickerFooter) return;

    // Update header.
    if (pickerTitle)  pickerTitle.textContent = 'Uploading to Google Drive…';
    if (pickerBcNav)  pickerBcNav.style.display = 'none';

    const dest = drivePath[drivePath.length - 1];

    pickerBody.innerHTML = `
      <div class="wpd-progress-view">
        <div class="wpd-progress-header">
          <div class="wpd-progress-title">
            <span class="wpd-mini-spinner"></span>
            Uploading to <strong>${esc(dest.name)}</strong>
          </div>
          <div class="wpd-progress-current-file" id="wpdProgressCurrentFile"></div>
          <div class="wpd-progress-master">
            <div class="wpd-progress-bar-bg">
              <div class="wpd-progress-bar-fill wpd-progress-bar-animated" id="wpdMasterBar" style="width:0%"></div>
            </div>
            <div class="wpd-progress-meta">
              <span id="wpdProgressLabel">Waiting for background job…</span>
              <span id="wpdProgressPct">0%</span>
            </div>
          </div>
        </div>
        <div class="wpd-progress-files" id="wpdProgressFiles">
          ${items.filter(i => i.type === 'file').map(i => `
            <div class="wpd-progress-file is-pending" data-path="${esc(i.path)}">
              <span class="wpd-progress-file-icon">${itemIcon(i.type)}</span>
              <span class="wpd-progress-file-name">${esc(i.path.split('/').pop())}</span>
              <span class="wpd-progress-file-status">Pending</span>
            </div>`).join('')}
        </div>
      </div>`;

    // Hide footer.
    pickerFooter.style.display = 'none';
  }

  function updateProgressMaster(completed, total, percent, currentFile) {
    const bar       = document.getElementById('wpdMasterBar');
    const label     = document.getElementById('wpdProgressLabel');
    const pct       = document.getElementById('wpdProgressPct');
    const titleEl   = document.getElementById('wpdProgressCurrentFile');

    if (bar)   bar.style.width = percent + '%';
    if (pct)   pct.textContent = percent + '%';

    if (label) {
      if (total > 0) {
        label.textContent = `${completed} of ${total} file${total !== 1 ? 's' : ''}`;
      } else {
        label.textContent = 'Preparing…';
      }
    }

    // Show which file is currently uploading.
    if (titleEl) {
      titleEl.textContent = currentFile ? `Uploading: ${currentFile}` : '';
    }
  }

  function updateProgressFiles(jobData) {
    const filesEl = document.getElementById('wpdProgressFiles');
    if (!filesEl || !jobData.items) return;

    // Match only file-type items (skip create_folder entries).
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
        case 'running': {
          // Show byte-level progress if available.
          if (item.file_pct !== undefined) {
            statusEl.innerHTML =
              `<span class="wpd-mini-spinner"></span> ${item.file_pct}%`;
          } else {
            statusEl.innerHTML = '<span class="wpd-mini-spinner"></span> Uploading…';
          }
          break;
        }
        default:
          statusEl.textContent = 'Pending';
      }
    });
  }

  function showUploadComplete(data) {
    // Stop the shimmer animation on the master bar.
    const masterBar = document.getElementById('wpdMasterBar');
    if (masterBar) {
      masterBar.classList.remove('wpd-progress-bar-animated');
      masterBar.style.width = '100%';
    }
    if (!pickerBody) return;

    const errorCount  = (data.errors || []).length;
    const successCount = (data.completed || 0) - errorCount;
    const hasErrors   = errorCount > 0;

    if (pickerTitle) pickerTitle.textContent = 'Upload complete';

    pickerBody.innerHTML = `
      <div class="wpd-upload-done">
        <div class="wpd-upload-done-icon">${hasErrors ? '⚠️' : '✅'}</div>
        <h3>${hasErrors ? 'Upload completed with errors' : 'All files uploaded!'}</h3>
        <p>${successCount} file${successCount !== 1 ? 's' : ''} uploaded successfully to <strong>${esc(drivePath[drivePath.length - 1].name)}</strong>.
          ${errorCount ? ` ${errorCount} file${errorCount !== 1 ? 's' : ''} failed.` : ''}</p>
        ${hasErrors ? `<div style="text-align:left;width:100%;max-height:120px;overflow-y:auto;">
          ${(data.errors || []).map(e => `<p style="font-size:12px;color:#dc2626;margin:4px 0;">✗ ${esc(e.name)}: ${esc(e.error)}</p>`).join('')}
        </div>` : ''}
        <div class="wpd-upload-done-actions">
          <button type="button" class="wpd-btn wpd-btn-secondary" id="wpdDoneClose">Close</button>
          ${errorCount ? '<button type="button" class="wpd-btn wpd-btn-secondary" id="wpdRetryFailed">Retry failed files</button>' : ''}
        </div>
      </div>`;

    const doneClose = document.getElementById('wpdDoneClose');
    if (doneClose) {
      doneClose.addEventListener('click', () => {
        closePicker();
        if (window.wpdFileManager) window.wpdFileManager.clearSelection();
      });
    }

    setLoading(uploadHereBtn, false);
    isUploading = false;
  }

  function showUploadError(msg) {
    if (!pickerBody) return;
    pickerBody.innerHTML = `
      <div class="wpd-upload-done">
        <div class="wpd-upload-done-icon">❌</div>
        <h3>Upload failed</h3>
        <p>${esc(msg)}</p>
        <div class="wpd-upload-done-actions">
          <button type="button" class="wpd-btn wpd-btn-secondary" id="wpdErrClose">Close</button>
        </div>
      </div>`;
    const errClose = document.getElementById('wpdErrClose');
    if (errClose) errClose.addEventListener('click', closePicker);
    setLoading(uploadHereBtn, false);
  }

  function resetToFolderBrowser() {
    if (pickerBody) {
      pickerBody.innerHTML = `
        <div class="wpd-drive-loading" id="wpdDriveLoading">
          <div class="wpd-spinner"></div>
          <span>Loading Drive…</span>
        </div>
        <div class="wpd-drive-list" id="wpdDriveList" style="display:none;"></div>
        <div class="wpd-drive-empty" id="wpdDriveEmpty" style="display:none;">
          <span style="font-size:36px;">📁</span>
          <span>This folder is empty</span>
        </div>`;
    }
    if (pickerFooter) pickerFooter.style.display = '';
    if (pickerTitle)  pickerTitle.textContent = 'Choose destination in Drive';
    if (pickerBcNav)  pickerBcNav.style.display = '';
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
    if (m.includes('folder'))         return '📁';
    if (m.startsWith('image/'))       return '🖼️';
    if (m.startsWith('video/'))       return '🎬';
    if (m.startsWith('audio/'))       return '🎵';
    if (m.includes('pdf'))            return '📄';
    if (m.includes('document'))       return '📝';
    if (m.includes('spreadsheet'))    return '📊';
    if (m.includes('presentation'))   return '📑';
    return '📎';
  }

  function itemIcon(type) {
    return type === 'dir' ? '📁' : '📎';
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

    // Close picker.
    if (closeBtn)  closeBtn.addEventListener('click', closePicker);
    if (cancelBtn) cancelBtn.addEventListener('click', closePicker);

    // Close on overlay backdrop click.
    overlay.addEventListener('click', e => {
      if (e.target === overlay && !isUploading) closePicker();
    });

    // Escape key.
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape' && overlay.style.display !== 'none' && !isUploading) closePicker();
    });

    // Upload here button.
    if (uploadHereBtn) {
      uploadHereBtn.addEventListener('click', startUpload);
    }

    // Listen for file manager trigger.
    document.addEventListener('wpd:open-picker', e => {
      openPicker(e.detail.items || []);
    });
  });

})();
