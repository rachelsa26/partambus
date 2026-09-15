document.addEventListener('click', function (event) {
  var target = event.target.closest('[data-confirm]');
  if (target && !window.confirm(target.getAttribute('data-confirm'))) {
    event.preventDefault();
  }
});

// ---- Sidebar collapse/expand, persisted per-browser ----
// header.php's inline pre-paint script already applied the stored
// preference to <body> to avoid a flash of the wide sidebar; this makes the
// toggle button actually work and keeps `.sidebar.collapsed` (not the body
// class) as the source of truth from here on.
(function () {
  var sidebar = document.querySelector('.sidebar');
  var toggleBtn = document.getElementById('sidebar-collapse-toggle');
  if (!sidebar || !toggleBtn) return;

  var STORAGE_KEY = 'partambus_sidebar_collapsed';
  var stored = null;
  try { stored = localStorage.getItem(STORAGE_KEY); } catch (e) {}

  sidebar.classList.toggle('collapsed', stored === '1');
  document.body.classList.remove('sidebar-collapsed-pref');

  toggleBtn.addEventListener('click', function () {
    var collapsed = !sidebar.classList.contains('collapsed');
    sidebar.classList.toggle('collapsed', collapsed);
    try { localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0'); } catch (e) {}
  });
})();

// ---- Info tooltip (hover works natively via CSS; this adds tap-to-toggle
// for touch devices, which have no :hover) ----
document.addEventListener('click', function (event) {
  var tip = event.target.closest('.info-tip');
  var openTips = document.querySelectorAll('.info-tip.info-tip-open');
  openTips.forEach(function (t) {
    if (t !== tip) t.classList.remove('info-tip-open');
  });
  if (tip) tip.classList.toggle('info-tip-open');
});

// ---- Generic small dropdown menu (e.g. "+ Tambah Produk") ----
document.addEventListener('click', function (event) {
  var toggle = event.target.closest('[data-dropdown-toggle]');
  var openMenus = document.querySelectorAll('.dropdown-menu:not(.hidden)');

  if (toggle) {
    event.preventDefault();
    var menu = document.getElementById(toggle.getAttribute('data-dropdown-toggle'));
    var wasOpen = menu && !menu.classList.contains('hidden');
    openMenus.forEach(function (m) { m.classList.add('hidden'); });
    if (menu && !wasOpen) menu.classList.remove('hidden');
    return;
  }

  if (!event.target.closest('.dropdown-menu')) {
    openMenus.forEach(function (m) { m.classList.add('hidden'); });
  }
});

// ---- Clickable table rows (e.g. product picker results) ----
// Clicking anywhere in the row navigates to its data-href, except when the
// click landed on an actual link/button inside the row — that element's own
// click already handles navigation, so it's left alone to avoid double-firing.
document.addEventListener('click', function (event) {
  var row = event.target.closest('.clickable-row');
  if (!row || event.target.closest('a, button')) return;
  var href = row.getAttribute('data-href');
  if (href) window.location.href = href;
});
document.addEventListener('keydown', function (event) {
  if (event.key !== 'Enter' && event.key !== ' ') return;
  var row = event.target.closest('.clickable-row');
  if (!row) return;
  event.preventDefault();
  var href = row.getAttribute('data-href');
  if (href) window.location.href = href;
});

// ---- Products list: real-time (debounced) search ----
// Typing in the search box re-fetches just the results fragment (table +
// pagination) from the same page with &ajax=1, and swaps it in — the "Cari"
// button still does a normal full-page submit as a fallback/manual option.
(function () {
  var input = document.getElementById('product-search-input');
  var form = document.getElementById('products-filter-form');
  var resultsContainer = document.getElementById('products-results');
  if (!input || !form || !resultsContainer) return;

  var ajaxUrl = resultsContainer.getAttribute('data-ajax-url');
  var debounceTimer = null;
  var latestRequestId = 0;

  function fetchResults() {
    var requestId = ++latestRequestId;
    var params = new URLSearchParams(new FormData(form));
    params.set('page', '1');
    params.set('ajax', '1');

    resultsContainer.style.opacity = '0.5';

    fetch(ajaxUrl + '?' + params.toString())
      .then(function (res) { return res.text(); })
      .then(function (html) {
        if (requestId !== latestRequestId) return; // a newer keystroke already superseded this request
        resultsContainer.innerHTML = html;
        resultsContainer.style.opacity = '1';

        var displayParams = new URLSearchParams(params);
        displayParams.delete('ajax');
        window.history.replaceState({}, '', window.location.pathname + '?' + displayParams.toString());
      })
      .catch(function () {
        resultsContainer.style.opacity = '1';
      });
  }

  input.addEventListener('input', function () {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(fetchResults, 350);
  });
})();

/**
 * Generic real-time single-field product search: debounces typing in
 * #inputId, fetches "<ajax-url from #resultsId's data-ajax-url>?q=...&ajax=1",
 * and swaps the response into #resultsId. Used everywhere a product is
 * looked up by typing (POS, and the standalone product picker in Inventaris)
 * — the underlying page still supports a normal full-page search too.
 */
/** @return {fetchNow: function(): Promise} so callers (e.g. the POS scan
 *  handler) can force an immediate, non-debounced refresh and know when
 *  the results have actually landed. */
function setupSimpleLiveSearch(inputId, resultsId) {
  var input = document.getElementById(inputId);
  var results = document.getElementById(resultsId);
  if (!input || !results) return null;

  var ajaxUrl = results.getAttribute('data-ajax-url');
  var debounceTimer = null;
  var latestRequestId = 0;

  function fetchResults() {
    clearTimeout(debounceTimer);
    var requestId = ++latestRequestId;
    var params = new URLSearchParams();
    params.set('q', input.value);
    params.set('ajax', '1');

    results.style.opacity = '0.5';

    // A request that never resolves (dropped connection, server hang) must
    // not leave the results dimmed/stuck forever — abort and show a clear
    // error after 10s instead of hanging with no explanation.
    var controller = new AbortController();
    var timeoutId = setTimeout(function () { controller.abort(); }, 10000);

    return fetch(ajaxUrl + '?' + params.toString(), { signal: controller.signal })
      .then(function (res) { return res.text(); })
      .then(function (html) {
        clearTimeout(timeoutId);
        if (requestId !== latestRequestId) return;
        results.innerHTML = html;
        results.style.opacity = '1';

        var displayParams = new URLSearchParams(params);
        displayParams.delete('ajax');
        window.history.replaceState({}, '', window.location.pathname + '?' + displayParams.toString());
      })
      .catch(function () {
        clearTimeout(timeoutId);
        if (requestId !== latestRequestId) return;
        results.style.opacity = '1';
        results.innerHTML = '<div class="flash flash-error" style="margin-top:10px">Gagal memuat, coba lagi.</div>';
      });
  }

  input.addEventListener('input', function () {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(fetchResults, 350);
  });

  return { fetchNow: fetchResults };
}

// Top-level (not inside an IIFE) so the checkout-success handler further
// down this file can also reach it, to refresh the search results back to
// their empty state after a sale completes.
var posLiveSearch = null;

document.addEventListener('DOMContentLoaded', function () {
  posLiveSearch = setupSimpleLiveSearch('pos-search-input', 'pos-search-results');
  setupSimpleLiveSearch('picker-search-input', 'picker-search-results');
  setupPosBarcodeScan(posLiveSearch);
});

// ---- POS: barcode scanner auto-add ----
// A physical scanner "types" the barcode into whatever's focused, then
// sends Enter — which submits the search form exactly like a cashier
// pressing Enter or clicking "Cari" manually. This intercepts that submit
// to check for an exact code/barcode match first: if it resolves to one
// sellable unit with enough stock, add it straight to the cart instead of
// making the cashier click "+"; otherwise fall through to the normal
// results list (which the live-search above may already be showing).
function setupPosBarcodeScan(posLiveSearch) {
  var form = document.getElementById('pos-search-form');
  var input = document.getElementById('pos-search-input');
  if (!form || !input) return;

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    var q = input.value.trim();
    if (!q) return;

    // This form has data-no-loading (see pos/index.php) precisely so the
    // global loading overlay never gets shown for it — most submits here
    // resolve as a same-page AJAX results refresh with no navigation, so
    // that overlay would otherwise never get hidden again. A stuck fetch
    // still needs its own bound, though — abort after 10s instead of
    // leaving the search silently hanging with no explanation.
    var controller = new AbortController();
    var timeoutId = setTimeout(function () { controller.abort(); }, 10000);

    fetch(form.getAttribute('action') + '?ajax=scan&q=' + encodeURIComponent(q), { signal: controller.signal })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        clearTimeout(timeoutId);
        if (data && data.action === 'add') {
          submitPosScanAdd(data.product_id, data.unit_id);
          return;
        }
        var refresh = posLiveSearch ? posLiveSearch.fetchNow() : Promise.resolve();
        if (data && data.warning) {
          refresh.then(function () { showPosInlineWarning(data.warning); });
        }
      })
      .catch(function () {
        clearTimeout(timeoutId);
        if (posLiveSearch) {
          posLiveSearch.fetchNow();
        } else {
          showPosInlineWarning('Gagal memuat, coba lagi.');
        }
      });
  });
}

function submitPosScanAdd(productId, unitId) {
  var csrfInput = document.getElementById('pos-csrf-token');
  var form = document.getElementById('pos-search-form');
  var hiddenForm = document.createElement('form');
  hiddenForm.method = 'post';
  hiddenForm.action = form.getAttribute('action');
  hiddenForm.style.display = 'none';
  var fields = {
    csrf_token: csrfInput ? csrfInput.value : '',
    pos_action: 'add_to_cart',
    product_id: productId,
    unit_id: unitId,
    qty: '1',
    scanned: '1',
  };
  Object.keys(fields).forEach(function (name) {
    var el = document.createElement('input');
    el.type = 'hidden';
    el.name = name;
    el.value = fields[name];
    hiddenForm.appendChild(el);
  });
  document.body.appendChild(hiddenForm);
  hiddenForm.submit();
}

function showPosInlineWarning(message) {
  // Insert into the scrollable list specifically, not #pos-search-results
  // itself — that outer element also holds the pinned card header (icon +
  // title + live count), which must never get bumped down by a warning.
  var box = document.getElementById('pos-search-results-scroll');
  if (!box) return;
  var div = document.createElement('div');
  div.className = 'flash flash-warning';
  div.style.marginTop = '10px';
  div.textContent = message;
  box.insertBefore(div, box.firstChild);
}

// ---- POS: one-shot "Ditambahkan: [nama]" toast after a scan auto-added
// an item — same one-shot-URL-flag pattern used elsewhere (?saved=1, etc.),
// strip the flag right away so a refresh doesn't replay the toast. ----
(function () {
  var params = new URLSearchParams(window.location.search);
  var scannedName = params.get('scanned');
  if (!scannedName) return;

  var toast = document.createElement('div');
  toast.className = 'pos-scan-toast';
  toast.textContent = 'Ditambahkan: ' + scannedName;
  document.body.appendChild(toast);
  setTimeout(function () {
    toast.classList.add('pos-scan-toast-out');
    setTimeout(function () { toast.remove(); }, 200);
  }, 2200);

  if (window.history && window.history.replaceState) {
    params.delete('scanned');
    var qs = params.toString();
    window.history.replaceState({}, '', window.location.pathname + (qs ? '?' + qs : ''));
  }
})();

// ---- Global loading overlay ----
// Reusable full-page overlay shown while any form submission (add/edit
// product, POS checkout, import, etc.) is in flight. Since every action in
// this app is a traditional full-page form POST/GET (no AJAX yet), simply
// showing the overlay on 'submit' and letting the browser navigate away is
// enough — there's no matching "hide" call needed for the happy path, the
// overlay disappears along with the old page. window.PartambusLoading is
// exposed globally so future AJAX-driven features can call show()/hide()
// directly instead of relying on the automatic form-submit hook.
(function () {
  var overlay = null;
  var messageEl = null;

  function elements() {
    if (!overlay) {
      overlay = document.getElementById('global-loading-overlay');
      messageEl = document.querySelector('[data-loading-message-text]');
    }
    return overlay;
  }

  function show(message) {
    if (!elements()) return;
    if (messageEl) messageEl.textContent = message || 'Memproses...';
    overlay.classList.remove('hidden');
  }

  function hide() {
    if (!elements()) return;
    overlay.classList.add('hidden');
  }

  window.PartambusLoading = { show: show, hide: hide };

  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!(form instanceof HTMLFormElement) || form.hasAttribute('data-no-loading')) {
      return;
    }
    var submitter = event.submitter;
    var message = (submitter && submitter.getAttribute('data-loading-message'))
      || form.getAttribute('data-loading-message')
      || null;
    show(message);
  });

  // A page restored from the browser's back/forward cache can retain the
  // overlay's "visible" state from just before the user navigated away,
  // making it look permanently stuck. Clear it whenever that happens.
  window.addEventListener('pageshow', function (event) {
    if (event.persisted) hide();
  });
})();

document.addEventListener('click', function (event) {
  var addBtn = event.target.closest('[data-add-unit-row]');
  if (!addBtn) return;
  event.preventDefault();

  var container = document.getElementById('unit-rows');
  var template = document.getElementById('unit-row-template');
  if (!container || !template) return;

  var index = parseInt(container.getAttribute('data-next-index') || '0', 10);
  var html = template.innerHTML.replace(/__INDEX__/g, String(index));
  var wrapper = document.createElement('div');
  wrapper.innerHTML = html.trim();
  container.appendChild(wrapper.firstElementChild);
  container.setAttribute('data-next-index', String(index + 1));
});

document.addEventListener('click', function (event) {
  var removeBtn = event.target.closest('[data-remove-unit-row]');
  if (!removeBtn) return;
  event.preventDefault();
  var row = removeBtn.closest('.unit-row');
  if (row) row.remove();
});

function syncUnitOtherInput(select) {
  var wrapper = select.closest('[data-unit-select-wrapper]');
  var otherInput = wrapper ? wrapper.querySelector('[data-unit-other-input]') : null;
  if (!otherInput) return;
  var isOther = select.value === '__other__';
  otherInput.style.display = isOther ? '' : 'none';
  if (isOther) otherInput.focus();
}
document.addEventListener('change', function (event) {
  var select = event.target.closest('[data-unit-select]');
  if (select) syncUnitOtherInput(select);
});

function updatePreviewFilterCount(activeFilter) {
  var countEl = document.querySelector('[data-preview-count]');
  if (!countEl) return;
  var groups = document.querySelectorAll('[data-preview-status]');
  if (activeFilter === 'all' || !activeFilter) {
    countEl.textContent = 'Menampilkan semua (' + groups.length + ' produk)';
    return;
  }
  var visible = document.querySelectorAll('[data-preview-status="' + activeFilter + '"]');
  countEl.textContent = 'Menampilkan ' + visible.length + ' dari ' + groups.length + ' produk';
}

document.addEventListener('click', function (event) {
  var filterBtn = event.target.closest('[data-preview-filter]');
  if (!filterBtn) return;

  var filter = filterBtn.getAttribute('data-preview-filter');
  var groups = document.querySelectorAll('[data-preview-status]');
  groups.forEach(function (group) {
    var matches = filter === 'all' || group.getAttribute('data-preview-status') === filter;
    group.classList.toggle('hidden', !matches);
  });
  updatePreviewFilterCount(filter);
});

(function () {
  var guard = document.getElementById('import-preview-guard');
  if (!guard) return;

  var bypass = false;
  var modal = document.getElementById('preview-exit-modal');
  var pendingHref = null;

  window.addEventListener('beforeunload', function (event) {
    if (bypass) return;
    event.preventDefault();
    event.returnValue = '';
  });

  document.addEventListener('submit', function (event) {
    if (event.target.closest('[data-preview-guard-scope]')) {
      bypass = true;
    }
  });

  document.addEventListener('click', function (event) {
    if (event.target.closest('[data-preview-guard-bypass]')) {
      bypass = true;
      return;
    }

    var navLink = event.target.closest('.sidebar-nav a, .back-link');
    if (!navLink || !modal) return;

    event.preventDefault();
    pendingHref = navLink.getAttribute('href');
    modal.classList.remove('hidden');
  });

  if (modal) {
    modal.addEventListener('click', function (event) {
      if (event.target === modal || event.target.closest('[data-modal-cancel]')) {
        modal.classList.add('hidden');
        pendingHref = null;
      } else if (event.target.closest('[data-modal-confirm]')) {
        bypass = true;
        modal.classList.add('hidden');
        if (pendingHref) window.location.href = pendingHref;
      }
    });
  }
})();

function auditPrettyJson(text) {
  if (!text) return '(kosong)';
  try {
    return JSON.stringify(JSON.parse(text), null, 2);
  } catch (e) {
    return text;
  }
}

document.addEventListener('click', function (event) {
  var btn = event.target.closest('[data-audit-json-btn]');
  if (!btn) return;

  var modal = document.getElementById('audit-json-modal');
  if (!modal) return;

  var beforeEl = document.getElementById('audit-json-before');
  var afterEl = document.getElementById('audit-json-after');
  if (beforeEl) beforeEl.textContent = auditPrettyJson(btn.getAttribute('data-audit-before'));
  if (afterEl) afterEl.textContent = auditPrettyJson(btn.getAttribute('data-audit-after'));

  modal.classList.remove('hidden');
});

document.addEventListener('click', function (event) {
  var modal = document.getElementById('audit-json-modal');
  if (!modal || modal.classList.contains('hidden')) return;
  if (event.target === modal || event.target.closest('[data-modal-cancel]')) {
    modal.classList.add('hidden');
  }
});

(function () {
  var modal = document.getElementById('delete-product-modal');
  if (!modal) return;

  var nameEl = document.getElementById('delete-product-name');
  var idInput = document.getElementById('delete-product-id');
  var form = document.getElementById('delete-product-form');
  var confirmBtn = document.getElementById('delete-product-confirm-btn');

  document.addEventListener('click', function (event) {
    var trigger = event.target.closest('[data-delete-trigger]');
    if (!trigger) return;
    if (nameEl) nameEl.textContent = trigger.getAttribute('data-delete-name') || '';
    if (idInput) idInput.value = trigger.getAttribute('data-delete-id') || '';
    modal.classList.remove('hidden');
  });

  modal.addEventListener('click', function (event) {
    if (event.target === modal || event.target.closest('[data-modal-cancel]')) {
      modal.classList.add('hidden');
    }
  });

  if (confirmBtn) {
    confirmBtn.addEventListener('click', function () {
      modal.classList.add('hidden');
      if (form) form.requestSubmit ? form.requestSubmit() : form.submit();
    });
  }
})();

(function () {
  var modal = document.getElementById('edit-success-modal');
  if (!modal) return;

  // The "?saved=1" trigger in the URL is one-shot — strip it immediately so
  // a manual refresh (or bookmark) doesn't reopen the modal on a page that
  // hasn't actually just been saved again.
  if (window.history && window.history.replaceState) {
    var url = new URL(window.location.href);
    url.searchParams.delete('saved');
    window.history.replaceState({}, '', url);
  }

  modal.addEventListener('click', function (event) {
    if (event.target === modal || event.target.closest('[data-modal-cancel]')) {
      modal.classList.add('hidden');
    }
  });
})();

// ---- POS stepper: exactly one step highlighted, reflecting where the
// cashier actually is right now (not a "completed steps stay lit" trail).
// Steps 1/2 are set server-side from whether the cart has items; 3/4 only
// exist client-side (there's no page of their own to render them from).
function setPosStep(step) {
  document.querySelectorAll('.pos-step').forEach(function (el) {
    el.classList.toggle('pos-step-active', el.getAttribute('data-pos-step') === String(step));
  });
}

function syncCashField() {
  var checked = document.querySelector('[data-method-radio]:checked');
  var cashField = document.getElementById('cash-field');
  if (checked && cashField) {
    cashField.style.display = checked.value === 'cash' ? '' : 'none';
  }
}
document.addEventListener('change', function (event) {
  if (event.target.matches('[data-method-radio]')) {
    syncCashField();
    setPosStep(3);
  }
});
document.addEventListener('focusin', function (event) {
  if (event.target.id === 'cash_received') setPosStep(3);
});

// ---- POS cart: typing a qty directly (not just the +/- stepper) submits
// on Enter/blur, same as picking a new value any other way ----
document.addEventListener('change', function (event) {
  if (!event.target.matches('.qty-stepper-input')) return;
  var form = event.target.closest('form');
  if (form) form.requestSubmit ? form.requestSubmit() : form.submit();
});

// ---- POS cart: the discount field is a plain always-editable input now
// (no more click-to-reveal) — same submit-on-change behavior as qty ----
document.addEventListener('change', function (event) {
  if (!event.target.matches('.pos-discount-input')) return;
  var form = event.target.closest('form');
  if (form) form.requestSubmit ? form.requestSubmit() : form.submit();
});

// ---- POS cart: the "ubah satuan" dropdown re-prices that line to the newly
// picked unit immediately, same submit-on-change behavior as qty/discount ----
document.addEventListener('change', function (event) {
  if (!event.target.matches('.pos-unit-pick-select')) return;
  var form = event.target.closest('form');
  if (form) form.requestSubmit ? form.requestSubmit() : form.submit();
});

// ---- POS: "Kosongkan Keranjang" asks for confirmation via a styled modal
// instead of the browser's native confirm() dialog ----
(function () {
  var openBtn = document.getElementById('pos-clear-cart-btn');
  var modal = document.getElementById('pos-clear-cart-modal');
  var confirmBtn = document.getElementById('pos-clear-cart-confirm-btn');
  var form = document.getElementById('pos-clear-cart-form');
  if (!openBtn || !modal || !confirmBtn || !form) return;

  openBtn.addEventListener('click', function () {
    modal.classList.remove('hidden');
  });
  modal.addEventListener('click', function (event) {
    if (event.target === modal || event.target.closest('[data-modal-cancel]')) {
      modal.classList.add('hidden');
    }
  });
  confirmBtn.addEventListener('click', function () {
    modal.classList.add('hidden');
    form.requestSubmit ? form.requestSubmit() : form.submit();
  });
})();
document.addEventListener('DOMContentLoaded', syncCashField);

// ---- POS checkout: quick cash-amount buttons + live change preview ----
function formatRupiah(amount) {
  var rounded = Math.round(amount);
  var sign = rounded < 0 ? '-' : '';
  var digits = String(Math.abs(rounded)).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  return sign + 'Rp' + digits;
}

function updateChangePreview() {
  var input = document.getElementById('cash_received');
  var preview = document.getElementById('change-preview');
  if (!input || !preview) return;

  var total = parseFloat(input.getAttribute('data-pos-total') || '0');
  var received = input.value === '' ? null : parseFloat(input.value);

  if (received === null || isNaN(received)) {
    preview.classList.add('hidden');
    return;
  }

  preview.classList.remove('hidden');
  var diff = received - total;
  if (diff >= 0) {
    preview.className = 'change-preview change-preview-ok';
    preview.innerHTML = 'Kembalian: <span class="change-amount">' + formatRupiah(diff) + '</span>';
  } else {
    preview.className = 'change-preview change-preview-short';
    preview.innerHTML = 'Uang belum cukup — kurang <span class="change-amount">' + formatRupiah(-diff) + '</span> lagi.';
  }
}

document.addEventListener('input', function (event) {
  if (event.target.id === 'cash_received') updateChangePreview();
});

document.addEventListener('click', function (event) {
  var btn = event.target.closest('[data-cash-amount]');
  if (!btn) return;
  event.preventDefault();
  var input = document.getElementById('cash_received');
  if (!input) return;
  input.value = btn.getAttribute('data-cash-amount');
  updateChangePreview();
});

document.addEventListener('DOMContentLoaded', updateChangePreview);

(function () {
  // Same one-shot-URL-flag pattern as the product edit success modal: strip
  // "?just_completed=1" right away so refreshing a completed sale's page
  // doesn't make it look like a brand new transaction just happened again.
  if (!document.getElementById('pos-success-banner')) return;
  if (window.history && window.history.replaceState) {
    var url = new URL(window.location.href);
    url.searchParams.delete('just_completed');
    window.history.replaceState({}, '', url);
  }
})();

// ---- POS sticky "Ringkasan Pembayaran" panel: numpad + AJAX payment submit
// + success animation. Always visible (not a modal) — the panel itself
// just swaps between its form view and its success view in place. ----
(function () {
  var panel = document.getElementById('pos-summary-panel');
  if (!panel) return;

  var formView = document.getElementById('pos-checkout-form-view');
  var successView = document.getElementById('pos-checkout-success-view');
  var form = document.getElementById('pos-checkout-form');
  var errorsBox = document.getElementById('pos-checkout-errors');
  var cashInput = document.getElementById('cash_received');
  var numpad = document.getElementById('pos-numpad');
  var numpadToggle = document.getElementById('pos-numpad-toggle');
  var successNumberEl = document.getElementById('pos-checkout-success-number');
  var successCardsEl = document.getElementById('pos-checkout-success-cards');
  var successTotalEl = document.getElementById('pos-checkout-success-total');
  var successChangeWrap = document.getElementById('pos-checkout-success-change');
  var successChangeAmountEl = document.getElementById('pos-checkout-success-change-amount');
  var receiptBtn = document.getElementById('pos-checkout-receipt-btn');
  var newSaleBtn = document.getElementById('pos-checkout-new-sale-btn');
  var checkoutUrl = panel.getAttribute('data-checkout-url');
  var resetUrl = panel.getAttribute('data-reset-url');

  // The cart TABLE and search box are separate cards from this summary
  // panel and don't get touched by the AJAX checkout response on their own
  // — without this, they'd keep showing the just-sold items/search behind
  // the success view until the cashier navigates away. Runs right alongside
  // showing the success view (not instead of it), so the struk/confirmation
  // is never interrupted or raced by the reset.
  function resetCartUiAfterSale() {
    var cartBody = document.getElementById('pos-cart-body');
    var cartCount = document.getElementById('pos-cart-count');
    var clearBtn = document.getElementById('pos-clear-cart-btn');
    var searchInput = document.getElementById('pos-search-input');

    if (cartBody) {
      cartBody.innerHTML = '<tr><td colspan="8" class="pos-cart-empty-cell"><div class="pos-cart-empty">'
        + '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
        + 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="21" r="1"></circle>'
        + '<circle cx="19" cy="21" r="1"></circle><path d="M2.5 3h2l2.6 12.2a2 2 0 0 0 2 1.6h8.4a2 2 0 0 0 2-1.6L21 8H6">'
        + '</path></svg><p>Keranjang masih kosong. Scan atau cari produk untuk memulai.</p></div></td></tr>';
    }
    if (cartCount) cartCount.textContent = '0 item';
    if (clearBtn) clearBtn.style.display = 'none';

    if (searchInput) {
      searchInput.value = '';
      searchInput.focus();
    }
    if (posLiveSearch) posLiveSearch.fetchNow();
  }

  function showCheckoutErrors(errors) {
    if (!errorsBox) return;
    if (!errors || !errors.length) {
      errorsBox.innerHTML = '';
      return;
    }
    errorsBox.innerHTML = errors.map(function (msg) {
      var div = document.createElement('div');
      div.className = 'flash flash-error';
      div.textContent = msg;
      return div.outerHTML;
    }).join('');
  }

  // The sale is already committed server-side by the time the success view
  // shows, so "Transaksi Baru" always means starting over on a clean cart
  // page rather than just hiding the success view over a now-stale keranjang.
  function goToFreshCart() {
    window.location.href = resetUrl;
  }

  if (numpadToggle && numpad) {
    numpadToggle.addEventListener('click', function () {
      numpad.classList.toggle('hidden');
    });
  }

  if (newSaleBtn) newSaleBtn.addEventListener('click', goToFreshCart);

  panel.addEventListener('click', function (event) {
    var key = event.target.closest('[data-numpad-key]');
    if (!key || !cashInput) return;
    var action = key.getAttribute('data-numpad-key');
    if (action === 'clear') {
      cashInput.value = '';
    } else if (action === 'back') {
      cashInput.value = cashInput.value.slice(0, -1);
    } else {
      cashInput.value = (cashInput.value + action).replace(/^0+(?=\d)/, '');
    }
    updateChangePreview();
  });

  if (form) {
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      showCheckoutErrors(null);
      window.PartambusLoading.show('Memproses transaksi...');

      fetch(checkoutUrl, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: new FormData(form),
      })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          window.PartambusLoading.hide();
          if (!data || !data.success) {
            showCheckoutErrors((data && data.errors) || ['Transaksi gagal. Coba lagi.']);
            return;
          }

          if (successNumberEl) successNumberEl.textContent = 'No. Transaksi: ' + (data.sale_number || '');
          if (successTotalEl && data.total !== null && data.total !== undefined) {
            successTotalEl.textContent = formatRupiah(data.total);
          }
          // Kembalian only makes sense for cash — non-cash methods are paid
          // exact, so that card is dropped entirely and Total Pembayaran
          // becomes the lone, centered card instead of a lopsided 2-col row.
          var isCash = data.method === 'cash' && data.change_amount !== null && data.change_amount !== undefined;
          if (isCash) {
            if (successChangeAmountEl) successChangeAmountEl.textContent = formatRupiah(data.change_amount);
            if (successChangeWrap) successChangeWrap.classList.remove('hidden');
          } else if (successChangeWrap) {
            successChangeWrap.classList.add('hidden');
          }
          if (successCardsEl) successCardsEl.classList.toggle('pos-success-cards-single', !isCash);
          if (receiptBtn && data.sale_id) {
            receiptBtn.setAttribute('data-sale-id', data.sale_id);
          }
          if (formView) formView.classList.add('hidden');
          if (successView) successView.classList.remove('hidden');
          setPosStep(4);
          resetCartUiAfterSale();
        })
        .catch(function () {
          window.PartambusLoading.hide();
          showCheckoutErrors(['Gagal terhubung ke server. Cek koneksi lalu coba lagi.']);
        });
    });
  }
})();

// ---- POS keyboard shortcuts: F2 search, F4 pay, Esc clear cart ----
document.addEventListener('keydown', function (event) {
  var searchInput = document.getElementById('pos-search-input');
  if (!searchInput) return; // not on the POS page

  if (event.key === 'F2') {
    event.preventDefault();
    searchInput.focus();
    searchInput.select();
  } else if (event.key === 'F4') {
    var payBtn = document.getElementById('pos-checkout-confirm-btn');
    if (payBtn && !payBtn.disabled) {
      event.preventDefault();
      payBtn.click();
    }
  } else if (event.key === 'Escape') {
    var clearBtn = document.getElementById('pos-clear-cart-btn');
    if (clearBtn) {
      event.preventDefault();
      clearBtn.click();
    }
  }
});

// ---- POS: click anywhere on a search-result row (not just "+") adds it to
// the cart — handy on touch screens. Skip when the click landed on the "+"
// button itself, since its own native click already submits the same form
// (submitting again from here would double-add). ----
document.addEventListener('click', function (event) {
  var row = event.target.closest('.pos-result-row');
  if (!row || event.target.closest('button')) return;
  var form = row.querySelector('form');
  if (form) form.requestSubmit ? form.requestSubmit() : form.submit();
});

// ---- Dashboard: range dropdown, chart metric switcher, and the
// "Lihat Semua Produk Terlaris" modal — grouped together since the modal
// and the range dropdown need to agree on which period is currently active.
(function () {
  var region = document.getElementById('dashboard-filtered-region');
  if (!region) return;

  var baseUrl = region.getAttribute('data-ajax-url');
  var paymentRegion = document.getElementById('dashboard-payment-region');
  var rangeDropdown = document.getElementById('dashboard-range-dropdown');
  var triggerLabel = document.getElementById('dashboard-range-trigger-label');
  var datePillLabel = document.getElementById('dashboard-date-pill-label');
  var currentRange = { key: 'today', customFrom: null, customTo: null };

  // Seed from whatever the page actually loaded with (survives a refresh
  // that landed on a bookmarked ?range=... URL).
  var urlParams = new URLSearchParams(window.location.search);
  if (urlParams.get('range')) currentRange.key = urlParams.get('range');
  if (urlParams.get('custom_from')) currentRange.customFrom = urlParams.get('custom_from');
  if (urlParams.get('custom_to')) currentRange.customTo = urlParams.get('custom_to');

  function buildQuery(extra) {
    var params = new URLSearchParams();
    params.set('range', currentRange.key);
    if (currentRange.key === 'custom') {
      if (currentRange.customFrom) params.set('custom_from', currentRange.customFrom);
      if (currentRange.customTo) params.set('custom_to', currentRange.customTo);
    }
    if (extra) {
      Object.keys(extra).forEach(function (k) { params.set(k, extra[k]); });
    }
    return params.toString();
  }

  function reloadFilteredRegion() {
    region.style.opacity = '0.5';
    if (paymentRegion) paymentRegion.style.opacity = '0.5';

    fetch(baseUrl + '?' + buildQuery({ ajax: '1' }))
      .then(function (res) { return res.text(); })
      .then(function (html) {
        // The response carries two named parts — Metode Pembayaran's card
        // shell lives in the static bottom row (next to 3 non-filtered
        // cards), so only its inner content is swapped, separately from
        // the main [chart | produk terlaris] region.
        var temp = document.createElement('div');
        temp.innerHTML = html;
        var mainPart = temp.querySelector('[data-ajax-part="main"]');
        var paymentPart = temp.querySelector('[data-ajax-part="payment"]');
        if (mainPart) region.innerHTML = mainPart.innerHTML;
        if (paymentPart && paymentRegion) paymentRegion.innerHTML = paymentPart.innerHTML;
        region.style.opacity = '1';
        if (paymentRegion) paymentRegion.style.opacity = '1';

        // The fragment carries the server-computed "Hari Ini, 23 Agustus
        // 2026" style label (correct Indonesian date formatting) — copy it
        // into the static date pill rather than reformatting dates in JS.
        var resolvedLabelEl = region.querySelector('#dashboard-resolved-label');
        if (resolvedLabelEl && datePillLabel) {
          datePillLabel.textContent = resolvedLabelEl.getAttribute('data-label');
        }
        if (window.history && window.history.replaceState) {
          window.history.replaceState({}, '', baseUrl + '?' + buildQuery());
        }
      })
      .catch(function () {
        region.style.opacity = '1';
        if (paymentRegion) paymentRegion.style.opacity = '1';
      });
  }

  if (rangeDropdown) {
    rangeDropdown.addEventListener('click', function (event) {
      var option = event.target.closest('[data-range-option]');
      if (option) {
        event.preventDefault();
        currentRange.key = option.getAttribute('data-range-option');
        currentRange.customFrom = null;
        currentRange.customTo = null;
        if (triggerLabel) triggerLabel.textContent = option.textContent.trim();
        rangeDropdown.querySelectorAll('.range-option').forEach(function (el) {
          el.classList.toggle('range-option-active', el === option);
        });
        var menu = rangeDropdown.querySelector('.dropdown-menu');
        if (menu) menu.classList.add('hidden');
        reloadFilteredRegion();
        return;
      }

      var applyBtn = event.target.closest('#dashboard-custom-apply-btn');
      if (applyBtn) {
        event.preventDefault();
        var fromInput = document.getElementById('dashboard-custom-from');
        var toInput = document.getElementById('dashboard-custom-to');
        if (!fromInput || !toInput || !fromInput.value || !toInput.value) return;

        currentRange.key = 'custom';
        currentRange.customFrom = fromInput.value;
        currentRange.customTo = toInput.value;

        if (triggerLabel) {
          var fmt = function (iso) {
            var parts = iso.split('-');
            return parts[2] + '/' + parts[1] + '/' + parts[0];
          };
          triggerLabel.textContent = fmt(fromInput.value) + ' - ' + fmt(toInput.value);
        }
        rangeDropdown.querySelectorAll('.range-option').forEach(function (el) {
          el.classList.remove('range-option-active');
        });
        var menu2 = rangeDropdown.querySelector('.dropdown-menu');
        if (menu2) menu2.classList.add('hidden');
        reloadFilteredRegion();
      }
    });
  }

  // ---- Chart metric switcher: all 4 metrics' charts are already
  // pre-rendered in the fragment (no extra round-trip) — just toggle
  // which one is visible. Delegated on `region` since the dropdown itself
  // is replaced every time the range reloads.
  region.addEventListener('click', function (event) {
    var option = event.target.closest('[data-metric-option]');
    if (!option) return;
    event.preventDefault();
    var metric = option.getAttribute('data-metric-option');
    var card = option.closest('.card');
    if (!card) return;

    card.querySelectorAll('[data-metric-block]').forEach(function (block) {
      block.classList.toggle('hidden', block.getAttribute('data-metric-block') !== metric);
    });
    card.querySelectorAll('.metric-option').forEach(function (el) {
      el.classList.toggle('metric-option-active', el === option);
    });
    var label = card.querySelector('[data-metric-trigger-label]');
    if (label) label.textContent = option.textContent.trim();
    var menu = option.closest('.dropdown-menu');
    if (menu) menu.classList.add('hidden');
  });

  // ---- "Lihat Semua Produk Terlaris" modal ----
  var modal = document.getElementById('dashboard-topproducts-modal');
  var modalBody = document.getElementById('dashboard-topproducts-modal-body');
  if (modal && modalBody) {
    document.addEventListener('click', function (event) {
      var trigger = event.target.closest('[data-open-topproducts-modal]');
      if (!trigger) return;
      event.preventDefault();

      modalBody.innerHTML = '<p class="text-muted">Memuat...</p>';
      modal.classList.remove('hidden');

      fetch(baseUrl + '?' + buildQuery({ ajax: 'top_products' }))
        .then(function (res) { return res.text(); })
        .then(function (html) { modalBody.innerHTML = html; })
        .catch(function () { modalBody.innerHTML = '<p class="text-muted">Gagal memuat data.</p>'; });
    });

    modal.addEventListener('click', function (event) {
      if (event.target === modal || event.target.closest('[data-modal-cancel]')) {
        modal.classList.add('hidden');
      }
    });
  }

  // ---- "Stok Menipis" card -> modal ----
  // The card lives inside #dashboard-filtered-region (re-rendered on every
  // range change even though its own value never depends on the range), so
  // this listens on the document instead of the card directly.
  var lowStockModal = document.getElementById('dashboard-lowstock-modal');
  var lowStockModalBody = document.getElementById('dashboard-lowstock-modal-body');
  if (!lowStockModal || !lowStockModalBody) return;

  function openLowStockModal() {
    lowStockModalBody.innerHTML = '<p class="text-muted">Memuat...</p>';
    lowStockModal.classList.remove('hidden');

    fetch(baseUrl + '?ajax=low_stock')
      .then(function (res) { return res.text(); })
      .then(function (html) { lowStockModalBody.innerHTML = html; })
      .catch(function () { lowStockModalBody.innerHTML = '<p class="text-muted">Gagal memuat data.</p>'; });
  }

  document.addEventListener('click', function (event) {
    if (event.target.closest('[data-open-lowstock-modal]')) openLowStockModal();
  });
  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Enter' && event.key !== ' ') return;
    if (!event.target.closest('[data-open-lowstock-modal]')) return;
    event.preventDefault();
    openLowStockModal();
  });

  lowStockModal.addEventListener('click', function (event) {
    if (event.target === lowStockModal || event.target.closest('[data-modal-cancel]')) {
      lowStockModal.classList.add('hidden');
    }
  });

  // ---- "Stok Habis" card -> modal (same pattern as "Stok Menipis" above,
  // just its own endpoint/count for products at exactly 0) ----
  var outOfStockModal = document.getElementById('dashboard-outofstock-modal');
  var outOfStockModalBody = document.getElementById('dashboard-outofstock-modal-body');
  if (!outOfStockModal || !outOfStockModalBody) return;

  function openOutOfStockModal() {
    outOfStockModalBody.innerHTML = '<p class="text-muted">Memuat...</p>';
    outOfStockModal.classList.remove('hidden');

    fetch(baseUrl + '?ajax=out_of_stock')
      .then(function (res) { return res.text(); })
      .then(function (html) { outOfStockModalBody.innerHTML = html; })
      .catch(function () { outOfStockModalBody.innerHTML = '<p class="text-muted">Gagal memuat data.</p>'; });
  }

  document.addEventListener('click', function (event) {
    if (event.target.closest('[data-open-outofstock-modal]')) openOutOfStockModal();
  });
  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Enter' && event.key !== ' ') return;
    if (!event.target.closest('[data-open-outofstock-modal]')) return;
    event.preventDefault();
    openOutOfStockModal();
  });

  outOfStockModal.addEventListener('click', function (event) {
    if (event.target === outOfStockModal || event.target.closest('[data-modal-cancel]')) {
      outOfStockModal.classList.add('hidden');
    }
  });

  // ---- "Lihat Semua Aktivitas" -> modal (same pattern again — this is
  // deliberately its own uncapped fetch of the dashboard's own merged
  // sales/cash/product timeline, NOT the separate Log Audit page) ----
  var activityModal = document.getElementById('dashboard-activity-modal');
  var activityModalBody = document.getElementById('dashboard-activity-modal-body');
  if (!activityModal || !activityModalBody) return;

  document.addEventListener('click', function (event) {
    if (!event.target.closest('[data-open-activity-modal]')) return;

    activityModalBody.innerHTML = '<p class="text-muted">Memuat...</p>';
    activityModal.classList.remove('hidden');

    fetch(baseUrl + '?ajax=recent_activity')
      .then(function (res) { return res.text(); })
      .then(function (html) { activityModalBody.innerHTML = html; })
      .catch(function () { activityModalBody.innerHTML = '<p class="text-muted">Gagal memuat data.</p>'; });
  });

  activityModal.addEventListener('click', function (event) {
    if (event.target === activityModal || event.target.closest('[data-modal-cancel]')) {
      activityModal.classList.add('hidden');
    }
  });
})();

// ---- POS: "Lihat Struk" modal (fetches the receipt fragment from
// sales/view.php?ajax=receipt for the just-completed sale) + "Kirim ke
// WhatsApp" (opens a wa.me link with the message pre-filled — the cashier
// still has to press "Kirim" inside WhatsApp themselves; see the note in the
// modal itself for why this stays manual on purpose). ----
(function () {
  var modal = document.getElementById('pos-receipt-modal');
  var body = document.getElementById('pos-receipt-modal-body');
  var openBtn = document.getElementById('pos-checkout-receipt-btn');
  var phoneInput = document.getElementById('pos-receipt-wa-phone');
  var phoneError = document.getElementById('pos-receipt-wa-error');
  var sendBtn = document.getElementById('pos-receipt-wa-send-btn');
  if (!modal || !body || !openBtn) return;

  openBtn.addEventListener('click', function () {
    var saleId = openBtn.getAttribute('data-sale-id');
    var baseUrl = openBtn.getAttribute('data-base-url');
    if (!saleId) return;

    body.innerHTML = '<p class="text-muted">Memuat...</p>';
    if (phoneError) phoneError.style.display = 'none';
    modal.classList.remove('hidden');

    fetch(baseUrl + '?id=' + encodeURIComponent(saleId) + '&ajax=receipt')
      .then(function (res) { return res.text(); })
      .then(function (html) { body.innerHTML = html; })
      .catch(function () { body.innerHTML = '<p class="text-muted">Gagal memuat struk.</p>'; });
  });

  modal.addEventListener('click', function (event) {
    if (event.target === modal || event.target.closest('[data-modal-cancel]')) {
      modal.classList.add('hidden');
    }
  });

  // Accepts 08xx…, 8xx…, 62xx…, or +62xx… and normalizes to the bare
  // "62xxxxxxxxxx" digits wa.me expects. Returns null if it doesn't look
  // like an Indonesian mobile number at all.
  function normalizeIndonesianPhone(raw) {
    var digits = (raw || '').replace(/\D/g, '');
    if (digits.indexOf('0') === 0) {
      digits = '62' + digits.slice(1);
    } else if (digits.indexOf('8') === 0) {
      digits = '62' + digits;
    }
    return /^62\d{8,13}$/.test(digits) ? digits : null;
  }

  if (sendBtn) {
    sendBtn.addEventListener('click', function () {
      var phone = normalizeIndonesianPhone(phoneInput ? phoneInput.value : '');
      if (!phone) {
        if (phoneError) {
          phoneError.textContent = 'Nomor WhatsApp tidak valid. Contoh: 0812xxxxxxxx.';
          phoneError.style.display = '';
        }
        return;
      }
      if (phoneError) phoneError.style.display = 'none';

      var textEl = document.getElementById('pos-receipt-wa-text');
      var text = textEl ? textEl.value : '';
      window.open('https://wa.me/' + phone + '?text=' + encodeURIComponent(text), '_blank');
    });
  }
})();

// ---- Riwayat Penjualan: date-range presets + AJAX filter/pagination ----
// "Filter Transaksi" applies without a full page reload, same fetch-and-
// swap pattern as the dashboard's range filter — only the table+pagination
// region re-renders; the KPI cards above stay put (they're always "today",
// unrelated to whatever's being filtered/searched below).
(function () {
  var region = document.getElementById('sales-results-region');
  var filterForm = document.getElementById('sales-filter-form');
  if (!region || !filterForm) return;

  var baseUrl = region.getAttribute('data-ajax-url');
  var fromInput = document.getElementById('filter-from');
  var toInput = document.getElementById('filter-to');
  var presetButtons = document.querySelectorAll('[data-date-preset]');
  var resetBtn = document.getElementById('sales-filter-reset-btn');

  function pad2(n) { return n < 10 ? '0' + n : String(n); }
  function isoDate(d) { return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate()); }

  function setActivePreset(preset) {
    presetButtons.forEach(function (btn) {
      btn.classList.toggle('date-preset-active', preset !== null && btn.getAttribute('data-date-preset') === preset);
    });
  }

  function loadResults(params) {
    params.set('ajax', '1');
    region.style.opacity = '0.5';

    fetch(baseUrl + '?' + params.toString())
      .then(function (res) { return res.text(); })
      .then(function (html) {
        region.innerHTML = html;
        region.style.opacity = '1';
        params.delete('ajax');
        window.history.replaceState({}, '', baseUrl + '?' + params.toString());
      })
      .catch(function () {
        region.style.opacity = '1';
      });
  }

  function applyFilterNow() {
    var params = new URLSearchParams(new FormData(filterForm));
    params.set('page', '1');
    loadResults(params);
  }

  // No submit button remains (filtering is real-time), but a lone text
  // field still implicitly submits its form on Enter per the HTML spec —
  // this keeps that working as an immediate apply, bypassing the debounce.
  filterForm.addEventListener('submit', function (event) {
    event.preventDefault();
    clearTimeout(qDebounceTimer);
    applyFilterNow();
  });

  var qInput = document.getElementById('filter-q');
  var qDebounceTimer = null;
  if (qInput) {
    qInput.addEventListener('input', function () {
      clearTimeout(qDebounceTimer);
      qDebounceTimer = setTimeout(applyFilterNow, 350);
    });
  }

  ['filter-status', 'filter-method', 'filter-cashier'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('change', applyFilterNow);
  });

  presetButtons.forEach(function (btn) {
    btn.addEventListener('click', function () {
      var preset = btn.getAttribute('data-date-preset');
      var today = new Date();
      var from = new Date(today);
      var to = new Date(today);
      if (preset === '7d') {
        from.setDate(from.getDate() - 6);
      } else if (preset === '30d') {
        from.setDate(from.getDate() - 29);
      } else if (preset === 'this_month') {
        from = new Date(today.getFullYear(), today.getMonth(), 1);
      }

      if (fromInput) fromInput.value = isoDate(from);
      if (toInput) toInput.value = isoDate(to);
      setActivePreset(preset);
      applyFilterNow();
    });
  });

  // Editing a date field by hand no longer matches any preset's exact
  // range — "input" clears the preset highlight immediately as they type,
  // "change" (fires once the value is actually committed) applies the filter.
  [fromInput, toInput].forEach(function (input) {
    if (!input) return;
    input.addEventListener('input', function () { setActivePreset(null); });
    input.addEventListener('change', applyFilterNow);
  });

  if (resetBtn) {
    resetBtn.addEventListener('click', function () {
      clearTimeout(qDebounceTimer);
      filterForm.querySelectorAll('input[type=search], input[type=text], input[type=date]').forEach(function (el) {
        el.value = '';
      });
      filterForm.querySelectorAll('select').forEach(function (el) {
        el.value = '';
      });
      setActivePreset(null);
      applyFilterNow();
    });
  }

  // Pagination controls (prev/next, per-page) live inside the AJAX-swapped
  // region, so they're delegated here instead of bound once at load.
  region.addEventListener('click', function (event) {
    var link = event.target.closest('.pagination-btn:not(.pagination-btn-disabled)');
    if (!link) return;
    event.preventDefault();
    var url = new URL(link.href, window.location.origin);
    loadResults(url.searchParams);
  });

  region.addEventListener('change', function (event) {
    if (!event.target.matches('.sales-per-page-select')) return;
    var url = new URL(event.target.value, window.location.origin);
    loadResults(url.searchParams);
  });
})();

// ---- Sales detail page: "Lihat Struk" modal, "Cetak" print, Void confirm ----
(function () {
  var receiptModal = document.getElementById('sale-receipt-modal');
  var receiptBody = document.getElementById('sale-receipt-modal-body');
  var viewReceiptBtn = document.getElementById('sale-view-receipt-btn');
  var printBtn = document.getElementById('sale-print-btn');
  var printArea = document.getElementById('sale-print-area');
  if (!receiptModal || !receiptBody) return;

  var receiptUrl = window.location.pathname + '?id=' + encodeURIComponent(new URLSearchParams(window.location.search).get('id') || '') + '&ajax=receipt';

  if (viewReceiptBtn) {
    viewReceiptBtn.addEventListener('click', function () {
      receiptBody.innerHTML = '<p class="text-muted">Memuat...</p>';
      receiptModal.classList.remove('hidden');
      fetch(receiptUrl)
        .then(function (res) { return res.text(); })
        .then(function (html) { receiptBody.innerHTML = html; })
        .catch(function () { receiptBody.innerHTML = '<p class="text-muted">Gagal memuat struk.</p>'; });
    });
  }

  receiptModal.addEventListener('click', function (event) {
    if (event.target === receiptModal || event.target.closest('[data-modal-cancel]')) {
      receiptModal.classList.add('hidden');
    }
  });

  if (printBtn && printArea) {
    printBtn.addEventListener('click', function () {
      fetch(receiptUrl)
        .then(function (res) { return res.text(); })
        .then(function (html) {
          printArea.innerHTML = html;
          window.print();
        });
    });
  }

  var voidModal = document.getElementById('sale-void-modal');
  var voidOpenBtn = document.getElementById('sale-void-open-btn');
  if (voidModal && voidOpenBtn) {
    voidOpenBtn.addEventListener('click', function () {
      voidModal.classList.remove('hidden');
    });
    voidModal.addEventListener('click', function (event) {
      if (event.target === voidModal || event.target.closest('[data-modal-cancel]')) {
        voidModal.classList.add('hidden');
      }
    });

    // Void Transaksi stays disabled until the reason is filled in — the
    // server also rejects an empty reason independently, this is just the
    // UI gate.
    var voidReasonInput = document.getElementById('void-reason-input');
    var voidSubmitBtn = document.getElementById('void-submit-btn');
    if (voidReasonInput && voidSubmitBtn) {
      var updateVoidSubmitState = function () {
        voidSubmitBtn.disabled = voidReasonInput.value.trim() === '';
      };
      voidReasonInput.addEventListener('input', updateVoidSubmitState);
    }
  }
})();

// ---- Edit Produk: clicking an existing unit's trash icon marks it for
// deletion (a hidden checkbox the server reads on save) instead of removing
// it from the DOM outright — the unit only actually disappears once
// "Simpan Perubahan" is submitted, matching how every other change on this
// form already works. ----
document.addEventListener('click', function (event) {
  var btn = event.target.closest('[data-delete-unit-row]');
  if (!btn) return;
  if (!window.confirm('Hapus satuan ini? Perubahan baru benar-benar tersimpan setelah klik "Simpan Perubahan".')) return;

  var row = btn.closest('.unit-row-existing');
  var flag = row ? row.querySelector('.unit-delete-flag') : null;
  if (flag) flag.checked = true;
  if (row) row.classList.add('unit-row-marked-delete');
});

// ---- Escape closes any currently-visible modal (applies app-wide) ----
document.addEventListener('keydown', function (event) {
  if (event.key !== 'Escape') return;
  document.querySelectorAll('.modal-overlay:not(.hidden)').forEach(function (modal) {
    modal.classList.add('hidden');
  });
});
