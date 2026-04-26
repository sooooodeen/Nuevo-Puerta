(function () {
  if (window.__alertModalInitialized) return;
  window.__alertModalInitialized = true;

  var activeResolver = null;

  function ensureModal() {
    var existing = document.getElementById('globalAlertModal');
    if (existing) return existing;

    var overlay = document.createElement('div');
    overlay.id = 'globalAlertModal';
    overlay.style.display = 'none';
    overlay.style.position = 'fixed';
    overlay.style.inset = '0';
    overlay.style.background = 'rgba(0,0,0,0.45)';
    overlay.style.zIndex = '10050';
    overlay.style.alignItems = 'center';
    overlay.style.justifyContent = 'center';

    var card = document.createElement('div');
    card.style.background = '#ffffff';
    card.style.width = '92%';
    card.style.maxWidth = '460px';
    card.style.borderRadius = '12px';
    card.style.boxShadow = '0 14px 34px rgba(0,0,0,0.28)';
    card.style.padding = '18px';

    var title = document.createElement('h3');
    title.id = 'globalAlertTitle';
    title.textContent = 'Notice';
    title.style.margin = '0 0 10px 0';
    title.style.color = '#234031';
    title.style.fontSize = '20px';

    var body = document.createElement('div');
    body.id = 'globalAlertBody';
    body.style.color = '#1f2937';
    body.style.fontSize = '15px';
    body.style.lineHeight = '1.5';
    body.style.whiteSpace = 'pre-wrap';

    var promptWrap = document.createElement('div');
    promptWrap.id = 'globalAlertPromptWrap';
    promptWrap.style.display = 'none';
    promptWrap.style.marginTop = '12px';

    var promptInput = document.createElement('input');
    promptInput.id = 'globalAlertPromptInput';
    promptInput.type = 'text';
    promptInput.style.width = '100%';
    promptInput.style.border = '1px solid #d1d5db';
    promptInput.style.borderRadius = '8px';
    promptInput.style.padding = '9px 10px';
    promptInput.style.fontSize = '14px';
    promptWrap.appendChild(promptInput);

    var actions = document.createElement('div');
    actions.style.display = 'flex';
    actions.style.justifyContent = 'flex-end';
    actions.style.gap = '8px';
    actions.style.marginTop = '16px';

    var cancelBtn = document.createElement('button');
    cancelBtn.type = 'button';
    cancelBtn.id = 'globalAlertCancel';
    cancelBtn.textContent = 'Cancel';
    cancelBtn.style.border = '1px solid #d1d5db';
    cancelBtn.style.background = '#f9fafb';
    cancelBtn.style.color = '#374151';
    cancelBtn.style.padding = '9px 18px';
    cancelBtn.style.borderRadius = '8px';
    cancelBtn.style.cursor = 'pointer';
    cancelBtn.style.fontWeight = '600';
    cancelBtn.style.display = 'none';

    var okBtn = document.createElement('button');
    okBtn.type = 'button';
    okBtn.id = 'globalAlertOk';
    okBtn.textContent = 'OK';
    okBtn.style.border = 'none';
    okBtn.style.background = '#2f5f46';
    okBtn.style.color = '#fff';
    okBtn.style.padding = '9px 18px';
    okBtn.style.borderRadius = '8px';
    okBtn.style.cursor = 'pointer';
    okBtn.style.fontWeight = '600';

    actions.appendChild(cancelBtn);
    actions.appendChild(okBtn);
    card.appendChild(title);
    card.appendChild(body);
    card.appendChild(promptWrap);
    card.appendChild(actions);
    overlay.appendChild(card);

    function closeModal() {
      overlay.style.display = 'none';
    }

    function resolveAndClose(payload) {
      if (activeResolver) {
        var resolver = activeResolver;
        activeResolver = null;
        resolver(payload);
      }
      closeModal();
    }

    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) {
        resolveAndClose({ type: 'cancel' });
      }
    });
    okBtn.addEventListener('click', function () {
      var inputEl = document.getElementById('globalAlertPromptInput');
      var promptIsVisible = document.getElementById('globalAlertPromptWrap').style.display !== 'none';
      resolveAndClose({ type: 'ok', value: promptIsVisible && inputEl ? inputEl.value : undefined });
    });
    cancelBtn.addEventListener('click', function () {
      resolveAndClose({ type: 'cancel' });
    });
    document.addEventListener('keydown', function (e) {
      if (overlay.style.display !== 'flex') return;
      if (e.key === 'Escape') {
        resolveAndClose({ type: 'cancel' });
      }
      if (e.key === 'Enter') {
        var inputEl = document.getElementById('globalAlertPromptInput');
        var promptIsVisible = document.getElementById('globalAlertPromptWrap').style.display !== 'none';
        resolveAndClose({ type: 'ok', value: promptIsVisible && inputEl ? inputEl.value : undefined });
      }
    });

    document.body.appendChild(overlay);
    return overlay;
  }

  function openBase(message, title, options) {
    options = options || {};

    return new Promise(function (resolve) {
      activeResolver = resolve;

      var modal = ensureModal();
      var titleEl = document.getElementById('globalAlertTitle');
      var bodyEl = document.getElementById('globalAlertBody');
      var okBtn = document.getElementById('globalAlertOk');
      var cancelBtn = document.getElementById('globalAlertCancel');
      var promptWrap = document.getElementById('globalAlertPromptWrap');
      var promptInput = document.getElementById('globalAlertPromptInput');

      if (titleEl) titleEl.textContent = title || 'Notice';
      if (bodyEl) bodyEl.textContent = String(message == null ? '' : message);
      if (okBtn) okBtn.textContent = options.okText || 'OK';
      if (cancelBtn) {
        cancelBtn.textContent = options.cancelText || 'Cancel';
        cancelBtn.style.display = options.showCancel ? 'inline-block' : 'none';
      }
      if (promptWrap) {
        promptWrap.style.display = options.prompt ? 'block' : 'none';
      }
      if (promptInput) {
        promptInput.value = options.promptDefault || '';
      }

      modal.style.display = 'flex';
      if (options.prompt && promptInput) {
        promptInput.focus();
        promptInput.select();
      } else if (okBtn) {
        okBtn.focus();
      }
    });
  }

  function showAlertModal(message, title) {
    function open() {
      openBase(message, title || 'Notice', { showCancel: false, okText: 'OK' });
    }

    if (document.body) {
      open();
    } else {
      window.addEventListener('DOMContentLoaded', open, { once: true });
    }
  }

  function showConfirmModal(message, title, okText, cancelText) {
    return openBase(message, title || 'Confirm Action', {
      showCancel: true,
      okText: okText || 'Yes',
      cancelText: cancelText || 'Cancel'
    }).then(function (result) {
      return !!(result && result.type === 'ok');
    });
  }

  function showPromptModal(message, defaultValue, title) {
    return openBase(message, title || 'Input Required', {
      showCancel: true,
      okText: 'Submit',
      cancelText: 'Cancel',
      prompt: true,
      promptDefault: defaultValue || ''
    }).then(function (result) {
      if (!result || result.type !== 'ok') return null;
      return result.value == null ? '' : String(result.value);
    });
  }

  function confirmFormSubmit(event, form, message) {
    if (event && typeof event.preventDefault === 'function') {
      event.preventDefault();
    }
    if (!form) return false;
    showConfirmModal(message || 'Are you sure you want to continue?').then(function (ok) {
      if (ok) form.submit();
    });
    return false;
  }

  window.showAlertModal = showAlertModal;
  window.showConfirmModal = showConfirmModal;
  window.showPromptModal = showPromptModal;
  window.confirmFormSubmit = confirmFormSubmit;
  window.alert = function (message) {
    showAlertModal(message, 'Notice');
  };
})();
