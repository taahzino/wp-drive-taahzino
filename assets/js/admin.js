/**
 * WP Drive — Admin JS
 * Handles the installation wizard and the settings page.
 */
/* global wpDrive, wpdWizardInitStep */
(function () {
  'use strict';

  const cfg = window.wpDrive || {};
  const api = cfg.restBase || '';
  const nonce = cfg.nonce || '';

  // ============================================================
  // API helper
  // ============================================================
  async function request(method, endpoint, body) {
    const opts = {
      method,
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': nonce,
      },
    };
    if (body) opts.body = JSON.stringify(body);
    const res = await fetch(api + endpoint, opts);
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.message || 'Request failed');
    return data;
  }

  // ============================================================
  // Alert helper
  // ============================================================
  function showAlert(el, message, type = 'error') {
    if (!el) return;
    el.textContent = message;
    el.className = 'wpd-alert wpd-alert-' + (type === 'error' ? 'error' : 'success');
    el.style.display = '';
    el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  function hideAlert(el) {
    if (!el) return;
    el.className = 'wpd-alert wpd-alert-hidden';
    el.style.display = '';
  }

  // ============================================================
  // Button loading state
  // ============================================================
  function setLoading(btn, loading) {
    if (!btn) return;
    btn.disabled = loading;
    btn.classList.toggle('is-loading', loading);
  }

  // ============================================================
  // WIZARD
  // ============================================================
  const TOTAL_STEPS = 5;

  const stepConfig = {
    1: { nextLabel: 'Get Started →', showBack: false },
    2: { nextLabel: 'Continue →',    showBack: true  },
    3: { nextLabel: 'Save & Continue →', showBack: true },
    4: { nextLabel: 'Connect to Google Drive →', showBack: true },
    5: { nextLabel: 'Open File Manager →', showBack: false },
  };

  let currentStep = parseInt(window.wpdWizardInitStep || '1', 10);

  const progressBar  = document.getElementById('wpdProgressBar');
  const stepDots     = document.querySelectorAll('.wpd-wizard-step-dot');
  const connectors   = document.querySelectorAll('.wpd-wizard-connector');
  const btnNext      = document.getElementById('wpdBtnNext');
  const btnBack      = document.getElementById('wpdBtnBack');
  const gcpCheckbox  = document.getElementById('wpdGcpCheckbox');

  function initWizard() {
    if (!btnNext) return; // Not on wizard page.
    renderStep(currentStep, false);
    btnNext.addEventListener('click', handleNext);
    btnBack.addEventListener('click', handleBack);
    if (gcpCheckbox) {
      gcpCheckbox.addEventListener('change', updateNextButton);
    }
    const toggleSecret = document.getElementById('wpdToggleSecret');
    if (toggleSecret) {
      toggleSecret.addEventListener('click', () => togglePasswordField('wpdClientSecret', toggleSecret));
    }
  }

  function renderStep(step, animate = true) {
    // Hide all panels.
    document.querySelectorAll('.wpd-wizard-panel').forEach(p => p.classList.remove('is-active'));

    // Show current.
    const panel = document.getElementById('wpdPanel' + step);
    if (panel) {
      if (animate) {
        panel.style.animation = 'none';
        panel.offsetHeight; // reflow
        panel.style.animation = '';
      }
      panel.classList.add('is-active');
    }

    // Update step dots.
    stepDots.forEach(dot => {
      const dotStep = parseInt(dot.dataset.step, 10);
      dot.classList.remove('is-active', 'is-done');
      if (dotStep < step) dot.classList.add('is-done');
      if (dotStep === step) dot.classList.add('is-active');
      const circle = dot.querySelector('.wpd-wizard-step-circle');
      if (circle) circle.textContent = dotStep < step ? '✓' : dotStep;
    });

    // Update connectors.
    connectors.forEach((conn, i) => {
      conn.classList.toggle('is-done', i < step - 1);
    });

    // Update progress bar.
    const pct = Math.round(((step - 1) / (TOTAL_STEPS - 1)) * 100);
    if (progressBar) progressBar.style.width = pct + '%';

    // Update buttons.
    const conf = stepConfig[step] || {};
    if (btnNext) {
      btnNext.innerHTML = (conf.nextLabel || 'Continue →') + ' <span class="wpd-spinner"></span>';
    }
    if (btnBack) {
      btnBack.style.visibility = conf.showBack ? 'visible' : 'hidden';
    }
    updateNextButton();
  }

  function updateNextButton() {
    if (!btnNext) return;
    if (currentStep === 2 && gcpCheckbox) {
      btnNext.disabled = !gcpCheckbox.checked;
    } else {
      btnNext.disabled = false;
    }
  }

  async function handleNext() {
    const alert3 = document.getElementById('wpdCredsAlert');
    const alert4 = document.getElementById('wpdConnectAlert');

    if (currentStep === 2) {
      if (gcpCheckbox && !gcpCheckbox.checked) return;
      goToStep(3);
      return;
    }

    if (currentStep === 3) {
      // Save credentials.
      const clientId       = (document.getElementById('wpdClientId') || {}).value || '';
      const clientSecret   = (document.getElementById('wpdClientSecret') || {}).value || '';
      const redirectOverride = ((document.getElementById('wpdRedirectOverride') || {}).value || '').trim();

      if (!clientId.trim()) {
        showAlert(alert3, 'Please enter your Client ID.');
        return;
      }
      if (!clientSecret.trim()) {
        showAlert(alert3, 'Please enter your Client Secret.');
        return;
      }

      hideAlert(alert3);
      setLoading(btnNext, true);
      try {
        await request('POST', '/auth/credentials', {
          client_id: clientId.trim(),
          client_secret: clientSecret.trim(),
          redirect_uri_override: redirectOverride,
        });
        goToStep(4);
      } catch (err) {
        showAlert(alert3, err.message || 'Failed to save credentials. Please try again.');
      } finally {
        setLoading(btnNext, false);
      }
      return;
    }

    if (currentStep === 4) {
      // OAuth redirect.
      setLoading(btnNext, true);
      try {
        const data = await request('GET', '/auth/connect');
        if (data.url) {
          window.location.href = data.url;
        } else {
          showAlert(alert4, 'Could not get authorization URL. Check your Client ID and Secret.');
          setLoading(btnNext, false);
        }
      } catch (err) {
        showAlert(alert4, err.message || 'Failed to connect. Please check your credentials.');
        setLoading(btnNext, false);
      }
      return;
    }

    if (currentStep === 5) {
      window.location.href = cfg.fileManagerUrl || '';
      return;
    }

    goToStep(currentStep + 1);
  }

  function handleBack() {
    if (currentStep > 1) goToStep(currentStep - 1);
  }

  function goToStep(step) {
    currentStep = Math.max(1, Math.min(TOTAL_STEPS, step));
    renderStep(currentStep, true);
  }

  // ============================================================
  // SETTINGS PAGE
  // ============================================================
  function initSettings() {
    const saveBtn    = document.getElementById('wpdSaveCredentials');
    const connectBtn = document.getElementById('wpdBtnConnect');
    const disconnBtn = document.getElementById('wpdBtnDisconnect');
    const reauthBtn  = document.getElementById('wpdBtnReauth');
    const copyBtn    = document.getElementById('wpdCopyRedirect');
    const toggleBtn  = document.getElementById('wpdSettingsToggleSecret');
    const alertEl    = document.getElementById('wpdSettingsAlert');

    if (!saveBtn && !connectBtn) return; // Not on settings page.

    // Toggle secret field visibility.
    if (toggleBtn) {
      toggleBtn.addEventListener('click', () => togglePasswordField('wpdSettingsSecret', toggleBtn));
    }

    // Copy redirect URI.
    if (copyBtn) {
      copyBtn.addEventListener('click', () => {
        const input = copyBtn.previousElementSibling;
        if (input) {
          navigator.clipboard.writeText(input.value).then(() => {
            copyBtn.textContent = '✓';
            setTimeout(() => { copyBtn.textContent = '📋'; }, 1500);
          });
        }
      });
    }

    // Live-update active redirect URI display when override input changes.
    const overrideInput   = document.getElementById('wpdRedirectOverride');
    const activeUriInput  = document.getElementById('wpdActiveRedirectUri');
    if (overrideInput && activeUriInput) {
      const originalUri = activeUriInput.value;
      overrideInput.addEventListener('input', () => {
        const v = overrideInput.value.trim();
        activeUriInput.value = v !== '' ? v : originalUri;
      });
    }

    // Save credentials.
    if (saveBtn) {
      saveBtn.addEventListener('click', async () => {
        const clientId        = (document.getElementById('wpdSettingsClientId') || {}).value || '';
        const clientSecret    = (document.getElementById('wpdSettingsSecret') || {}).value || '';
        const redirectOverride = ((document.getElementById('wpdRedirectOverride') || {}).value || '').trim();

        if (!clientId.trim()) {
          showAlert(alertEl, 'Please enter your Client ID.');
          return;
        }

        hideAlert(alertEl);
        setLoading(saveBtn, true);
        try {
          const result = await request('POST', '/auth/credentials', {
            client_id: clientId.trim(),
            client_secret: clientSecret.trim(),
            redirect_uri_override: redirectOverride,
          });
          showAlert(alertEl, 'Credentials saved successfully.', 'success');
          // Update the active URI display with what the server now uses.
          if (activeUriInput && result.redirect_uri) activeUriInput.value = result.redirect_uri;
          // Enable connect button if it was disabled.
          if (connectBtn) connectBtn.disabled = false;
        } catch (err) {
          showAlert(alertEl, err.message || 'Failed to save. Please try again.');
        } finally {
          setLoading(saveBtn, false);
        }
      });
    }

    // Connect to Google Drive.
    if (connectBtn) {
      connectBtn.addEventListener('click', async () => {
        setLoading(connectBtn, true);
        try {
          const data = await request('GET', '/auth/connect');
          if (data.url) {
            window.location.href = data.url;
          } else {
            showAlert(alertEl, 'Could not get authorization URL.');
            setLoading(connectBtn, false);
          }
        } catch (err) {
          showAlert(alertEl, err.message || 'Failed to connect.');
          setLoading(connectBtn, false);
        }
      });
    }

    // Re-authorize.
    if (reauthBtn) {
      reauthBtn.addEventListener('click', async () => {
        setLoading(reauthBtn, true);
        try {
          const data = await request('GET', '/auth/connect');
          if (data.url) window.location.href = data.url;
        } catch (err) {
          showAlert(alertEl, err.message || 'Failed.');
        } finally {
          setLoading(reauthBtn, false);
        }
      });
    }

    // Disconnect.
    if (disconnBtn) {
      disconnBtn.addEventListener('click', async () => {
        if (!confirm('Disconnect Google Drive? You can reconnect anytime.')) return;
        setLoading(disconnBtn, true);
        try {
          await request('DELETE', '/auth/disconnect');
          window.location.reload();
        } catch (err) {
          showAlert(alertEl, err.message || 'Failed to disconnect.');
          setLoading(disconnBtn, false);
        }
      });
    }
  }

  // ============================================================
  // Shared: password show/hide toggle
  // ============================================================
  function togglePasswordField(inputId, btn) {
    const input = document.getElementById(inputId);
    if (!input) return;
    const isHidden = input.type === 'password';
    input.type = isHidden ? 'text' : 'password';
    btn.textContent = isHidden ? '🙈' : '👁';
  }

  // ============================================================
  // Boot
  // ============================================================
  document.addEventListener('DOMContentLoaded', () => {
    initWizard();
    initSettings();
  });

})();
