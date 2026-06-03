/**
 * WP Drive — File Manager JS
 * Handles local file/folder browsing and selection.
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
  let currentPath  = '';
  let currentItems = [];
  let selected     = new Set(); // Set of item "path" strings
  let viewMode     = 'list';    // 'list' | 'grid'

  // ============================================================
  // DOM refs
  // ============================================================
  const breadcrumb     = document.getElementById('wpdBreadcrumb');
  const loadingEl      = document.getElementById('wpdFmLoading');
  const listView       = document.getElementById('wpdListView');
  const gridView       = document.getElementById('wpdGridView');
  const fileListEl     = document.getElementById('wpdFileList');
  const gridViewEl     = document.getElementById('wpdGridView');
  const emptyEl        = document.getElementById('wpdFmEmpty');
  const selectionInfo  = document.getElementById('wpdSelectionInfo');
  const clearBtn       = document.getElementById('wpdClearSelection');
  const uploadBtn      = document.getElementById('wpdUploadToDrive');
  const viewListBtn    = document.getElementById('wpdViewList');
  const viewGridBtn    = document.getElementById('wpdViewGrid');

  // ============================================================
  // API
  // ============================================================
  async function fetchDirectory(path) {
    const url = api + '/local/files?path=' + encodeURIComponent(path);
    const res = await fetch(url, {
      headers: { 'X-WP-Nonce': nonce },
    });
    if (!res.ok) throw new Error('Failed to load directory.');
    const data = await res.json();
    return data.items || [];
  }

  // ============================================================
  // Navigation
  // ============================================================
  async function navigateTo(path) {
    currentPath = path;
    selected.clear();
    showLoading(true);
    try {
      currentItems = await fetchDirectory(path);
      renderBreadcrumb(path);
      renderItems();
      updateToolbar();
    } catch (err) {
      showError(err.message);
    } finally {
      showLoading(false);
    }
  }

  // ============================================================
  // Render
  // ============================================================
  function renderBreadcrumb(path) {
    if (!breadcrumb) return;
    const parts = path ? path.split('/') : [];
    let html = '<span class="wpd-fm-breadcrumb-item" data-path="" tabindex="0">WordPress Root</span>';

    parts.forEach((part, i) => {
      const partPath = parts.slice(0, i + 1).join('/');
      const isCurrent = i === parts.length - 1;
      html += '<span class="wpd-fm-breadcrumb-sep">›</span>';
      if (isCurrent) {
        html += `<span class="wpd-fm-breadcrumb-item is-current" data-path="${esc(partPath)}">${esc(part)}</span>`;
      } else {
        html += `<span class="wpd-fm-breadcrumb-item" data-path="${esc(partPath)}" tabindex="0">${esc(part)}</span>`;
      }
    });

    breadcrumb.innerHTML = html;
    breadcrumb.querySelectorAll('.wpd-fm-breadcrumb-item:not(.is-current)').forEach(el => {
      el.addEventListener('click', () => navigateTo(el.dataset.path));
      el.addEventListener('keydown', e => e.key === 'Enter' && navigateTo(el.dataset.path));
    });
  }

  function renderItems() {
    if (!currentItems.length) {
      showEmpty(true);
      if (fileListEl) fileListEl.innerHTML = '';
      if (gridViewEl) gridViewEl.innerHTML = '';
      return;
    }
    showEmpty(false);

    if (viewMode === 'list') {
      renderListView();
    } else {
      renderGridView();
    }
  }

  function renderListView() {
    if (!fileListEl) return;
    fileListEl.innerHTML = currentItems.map(item => itemListHTML(item)).join('');
    attachItemListeners(fileListEl);
  }

  function renderGridView() {
    if (!gridViewEl) return;
    gridViewEl.innerHTML = currentItems.map(item => itemGridHTML(item)).join('');
    attachItemListeners(gridViewEl);
  }

  function itemListHTML(item) {
    const isSelected = selected.has(item.path);
    return `
      <div class="wpd-fm-item ${isSelected ? 'is-selected' : ''}" 
           data-path="${esc(item.path)}" data-type="${esc(item.type)}" 
           role="row" tabindex="0">
        <div class="wpd-fm-item-check">
          <input type="checkbox" aria-label="${esc(item.name)}" ${isSelected ? 'checked' : ''} tabindex="-1">
        </div>
        <div class="wpd-fm-item-name">
          <span class="wpd-fm-item-icon">${fileIcon(item)}</span>
          <span class="wpd-fm-item-label" title="${esc(item.name)}">${esc(item.name)}</span>
        </div>
        <span class="wpd-fm-item-size">${item.type === 'dir' ? '—' : formatSize(item.size)}</span>
        <span class="wpd-fm-item-date">${formatDate(item.modified)}</span>
        <span class="wpd-fm-item-type">${item.type === 'dir' ? 'Folder' : fileType(item.mime)}</span>
      </div>`;
  }

  function itemGridHTML(item) {
    const isSelected = selected.has(item.path);
    return `
      <div class="wpd-fm-grid-item ${isSelected ? 'is-selected' : ''}" 
           data-path="${esc(item.path)}" data-type="${esc(item.type)}"
           role="gridcell" tabindex="0">
        <div class="wpd-fm-item-check">
          <input type="checkbox" aria-label="${esc(item.name)}" ${isSelected ? 'checked' : ''} tabindex="-1">
        </div>
        <span class="wpd-fm-item-icon">${fileIcon(item)}</span>
        <span class="wpd-fm-item-label" title="${esc(item.name)}">${esc(item.name)}</span>
      </div>`;
  }

  function attachItemListeners(container) {
    container.querySelectorAll('[data-path]').forEach(el => {
      const path = el.dataset.path;
      const type = el.dataset.type;

      // Checkbox click = toggle selection.
      const cb = el.querySelector('input[type="checkbox"]');
      if (cb) {
        cb.addEventListener('change', e => {
          e.stopPropagation();
          toggleSelect(path, type, cb.checked);
        });
      }

      // Row click = toggle select; double-click on dir = navigate.
      el.addEventListener('click', e => {
        if (e.target.tagName === 'INPUT') return;
        toggleSelect(path, type, !selected.has(path));
      });

      el.addEventListener('dblclick', () => {
        if (type === 'dir') navigateTo(path);
      });

      el.addEventListener('keydown', e => {
        if (e.key === 'Enter') {
          if (type === 'dir') navigateTo(path);
          else toggleSelect(path, type, !selected.has(path));
        }
        if (e.key === ' ') {
          e.preventDefault();
          toggleSelect(path, type, !selected.has(path));
        }
      });
    });
  }

  function toggleSelect(path, type, isSelected) {
    if (isSelected) {
      selected.add(path);
    } else {
      selected.delete(path);
    }
    // Re-render just the row state without full re-render for performance.
    const els = document.querySelectorAll(`[data-path="${CSS.escape(path)}"]`);
    els.forEach(el => {
      el.classList.toggle('is-selected', isSelected);
      const cb = el.querySelector('input[type="checkbox"]');
      if (cb) cb.checked = isSelected;
    });
    updateToolbar();
  }

  // ============================================================
  // Toolbar
  // ============================================================
  function updateToolbar() {
    const count = selected.size;
    if (selectionInfo) {
      selectionInfo.innerHTML = count > 0
        ? `<strong>${count}</strong> item${count !== 1 ? 's' : ''} selected`
        : 'No items selected';
    }
    if (uploadBtn)  uploadBtn.disabled  = count === 0;
    if (clearBtn)   clearBtn.style.display = count > 0 ? '' : 'none';
  }

  // ============================================================
  // Loading / empty states
  // ============================================================
  function showLoading(show) {
    if (loadingEl) loadingEl.style.display = show ? '' : 'none';
    if (listView)  listView.style.display  = show ? 'none' : (viewMode === 'list' ? '' : 'none');
    if (gridView)  gridView.style.display  = show ? 'none' : (viewMode === 'grid' ? '' : 'none');
    if (emptyEl)   emptyEl.style.display   = show ? 'none' : (emptyEl.style.display || 'none');
  }

  function showEmpty(show) {
    if (emptyEl)  emptyEl.style.display   = show ? '' : 'none';
    if (listView) listView.style.display  = show ? 'none' : (viewMode === 'list' ? '' : 'none');
    if (gridView) gridView.style.display  = show ? 'none' : (viewMode === 'grid' ? '' : 'none');
  }

  function showError(msg) {
    if (fileListEl) fileListEl.innerHTML = `<p style="color:#ef4444;padding:16px;">${esc(msg)}</p>`;
    if (listView)   listView.style.display = '';
  }

  // ============================================================
  // View mode toggle
  // ============================================================
  function setViewMode(mode) {
    viewMode = mode;
    if (viewListBtn) viewListBtn.classList.toggle('is-active', mode === 'list');
    if (viewGridBtn) viewGridBtn.classList.toggle('is-active', mode === 'grid');
    if (listView) listView.style.display = mode === 'list' && currentItems.length ? '' : 'none';
    if (gridView) gridView.style.display = mode === 'grid' && currentItems.length ? '' : 'none';
    renderItems();
  }

  // ============================================================
  // Format helpers
  // ============================================================
  function formatSize(bytes) {
    if (!bytes) return '—';
    const units = ['B', 'KB', 'MB', 'GB'];
    let i = 0;
    while (bytes >= 1024 && i < units.length - 1) { bytes /= 1024; i++; }
    return (i === 0 ? bytes : bytes.toFixed(1)) + ' ' + units[i];
  }

  function formatDate(ts) {
    if (!ts) return '—';
    return new Date(ts * 1000).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
  }

  function fileIcon(item) {
    if (item.type === 'dir') return '📁';
    const mime = item.mime || '';
    if (mime.startsWith('image/'))       return '🖼️';
    if (mime.startsWith('video/'))       return '🎬';
    if (mime.startsWith('audio/'))       return '🎵';
    if (mime.includes('pdf'))            return '📄';
    if (mime.includes('zip') || mime.includes('rar') || mime.includes('gzip')) return '🗜️';
    if (mime.includes('word') || mime.includes('document')) return '📝';
    if (mime.includes('sheet') || mime.includes('excel'))   return '📊';
    return '📎';
  }

  function fileType(mime) {
    if (!mime) return 'File';
    const map = {
      'image/': 'Image', 'video/': 'Video', 'audio/': 'Audio',
      'application/pdf': 'PDF', 'text/': 'Text',
      'application/zip': 'Archive', 'application/x-rar': 'Archive',
    };
    for (const [prefix, label] of Object.entries(map)) {
      if (mime.startsWith(prefix) || mime === prefix) return label;
    }
    return mime.split('/')[1] || 'File';
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
  // Public API for drive-picker.js
  // ============================================================
  window.wpdFileManager = {
    getSelected() {
      return [...selected].map(path => {
        const item = currentItems.find(i => i.path === path);
        return { path, type: item ? item.type : 'file' };
      });
    },
    clearSelection() {
      selected.clear();
      renderItems();
      updateToolbar();
    },
  };

  // ============================================================
  // Boot
  // ============================================================
  document.addEventListener('DOMContentLoaded', () => {
    if (!document.getElementById('wpdFileManager')) return;

    // View toggle.
    if (viewListBtn) viewListBtn.addEventListener('click', () => setViewMode('list'));
    if (viewGridBtn) viewGridBtn.addEventListener('click', () => setViewMode('grid'));

    // Clear selection.
    if (clearBtn) {
      clearBtn.addEventListener('click', () => {
        selected.clear();
        renderItems();
        updateToolbar();
      });
    }

    // Upload button — opens picker (handled by drive-picker.js).
    if (uploadBtn) {
      uploadBtn.addEventListener('click', () => {
        const items = window.wpdFileManager.getSelected();
        document.dispatchEvent(new CustomEvent('wpd:open-picker', { detail: { items } }));
      });
    }

    // Download button — opens Drive downloader (handled by drive-downloader.js).
    const downloadBtn = document.getElementById('wpdDownloadFromDrive');
    if (downloadBtn) {
      downloadBtn.addEventListener('click', () => {
        document.dispatchEvent(new CustomEvent('wpd:open-downloader'));
      });
    }

    // Initial load.
    navigateTo('');
  });

})();
