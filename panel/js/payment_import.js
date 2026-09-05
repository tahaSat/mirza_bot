(function () {
  var cfg = window.PAYMENT_IMPORT || null;
  if (!cfg) return;

  var openBtn = document.getElementById('payImportOpenBtn');
  var modal = document.getElementById('paymentImportModal');
  if (!openBtn || !modal) return;

  var fileInput = document.getElementById('payImportFile');
  var fileNameEl = document.getElementById('payImportFileName');
  var rateInput = document.getElementById('payImportUsdRate');
  var stepFile = document.getElementById('payImportStepFile');
  var stepRate = document.getElementById('payImportStepRate');
  var stepPreview = document.getElementById('payImportStepPreview');
  var titleEl = document.getElementById('payImportTitle');
  var errorEl = document.getElementById('payImportError');
  var statsEl = document.getElementById('payImportStats');
  var bodyEl = document.getElementById('payImportPreviewBody');
  var nextBtn = document.getElementById('payImportNextBtn');
  var backBtn = document.getElementById('payImportBackBtn');

  var step = 'file';
  var selectedFile = null;
  var previewRows = [];
  var expenseCategories = {};
  var incomeCategories = {};
  var busy = false;

  function escapeHtml(str) {
    return String(str == null ? '' : str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function toast(msg, type) {
    if (window.toast) window.toast(msg, type === 'error' ? 'no' : 'ok');
  }

  function toEnDigits(str) {
    return String(str == null ? '' : str)
      .replace(/[۰-۹]/g, function (d) { return String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d)); })
      .replace(/[٠-٩]/g, function (d) { return String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)); });
  }

  function parsePrice(str) {
    return toEnDigits(str).replace(/[^\d]/g, '');
  }

  function formatPrice(str) {
    var digits = parsePrice(str);
    if (!digits) return '';
    return digits.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }

  function showError(msg) {
    if (!errorEl) return;
    if (!msg) {
      errorEl.hidden = true;
      errorEl.textContent = '';
      return;
    }
    errorEl.hidden = false;
    errorEl.textContent = msg;
  }

  function setBusy(on) {
    busy = !!on;
    if (nextBtn) nextBtn.disabled = !!on || (step === 'file' && !selectedFile);
    if (backBtn) backBtn.disabled = !!on;
  }

  function pickerOptions($el) {
    return {
      calendarType: 'persian',
      format: 'YYYY/MM/DD HH:mm',
      initialValue: $el.val() !== '',
      initialValueType: 'persian',
      autoClose: true,
      responsive: true,
      observer: false,
      navigator: { scroll: { enabled: false } },
      toolbox: {
        calendarSwitch: { enabled: false },
        todayButton: { enabled: true, text: { fa: 'اکنون', en: 'Now' } },
        submitButton: { enabled: true }
      },
      timePicker: {
        enabled: true,
        second: { enabled: false },
        meridian: { enabled: false }
      }
    };
  }

  function initJalaliPicker(input) {
    if (!window.jQuery || !input || input.dataset.pdp === '1') return;
    var $el = window.jQuery(input);
    $el.persianDatepicker(pickerOptions($el));
    input.dataset.pdp = '1';
  }

  var investmentCategories = {};

  function categoryMap(kind) {
    if (kind === 'income') return incomeCategories;
    if (kind === 'investment') return investmentCategories;
    return expenseCategories;
  }

  function categoryOptionsHtml(kind, selected) {
    var map = categoryMap(kind);
    var html = '<option value="">یافت نشده</option>';
    Object.keys(map).forEach(function (slug) {
      html += '<option value="' + escapeHtml(slug) + '"' + (slug === selected ? ' selected' : '') + '>'
        + escapeHtml(map[slug]) + '</option>';
    });
    return html;
  }

  function setStep(name) {
    step = name;
    stepFile.hidden = name !== 'file';
    stepRate.hidden = name !== 'rate';
    stepPreview.hidden = name !== 'preview';
    backBtn.hidden = name === 'file';
    showError('');
    if (name === 'file') {
      titleEl.textContent = 'ورود دیتا با اکسل';
      nextBtn.textContent = 'ادامه';
      nextBtn.disabled = !selectedFile;
    } else if (name === 'rate') {
      titleEl.textContent = 'نرخ تبدیل دلار';
      nextBtn.textContent = 'پردازش فایل';
      nextBtn.disabled = false;
    } else {
      titleEl.textContent = 'پیش‌نمایش ورود داده';
      nextBtn.textContent = 'ورود به دیتابیس';
      updateConfirmState();
    }
  }

  function resetWizard() {
    selectedFile = null;
    previewRows = [];
    expenseCategories = {};
    incomeCategories = {};
    investmentCategories = {};
    if (fileInput) fileInput.value = '';
    if (fileNameEl) fileNameEl.textContent = '';
    if (rateInput) rateInput.value = '';
    if (bodyEl) bodyEl.innerHTML = '';
    if (statsEl) statsEl.innerHTML = '';
    setStep('file');
  }

  function collectPreviewRows() {
    if (!bodyEl) return [];
    return Array.prototype.map.call(bodyEl.querySelectorAll('tr'), function (tr) {
      var kind = (tr.querySelector('.pay-import-kind') || {}).value || 'expense';
      var cat = (tr.querySelector('.pay-import-cat') || {}).value || '';
      return {
        source_row: parseInt(tr.dataset.sourceRow || '0', 10) || 0,
        kind: kind,
        time: (tr.querySelector('.pay-import-time') || {}).value || '',
        amount: parsePrice((tr.querySelector('.pay-import-amount') || {}).value || ''),
        note: (tr.querySelector('.pay-import-note') || {}).value || '',
        category: cat
      };
    });
  }

  function rowIsValid(tr) {
    var amount = parsePrice((tr.querySelector('.pay-import-amount') || {}).value || '');
    var time = ((tr.querySelector('.pay-import-time') || {}).value || '').trim();
    var cat = ((tr.querySelector('.pay-import-cat') || {}).value || '').trim();
    var kind = ((tr.querySelector('.pay-import-kind') || {}).value || '').trim();
    return amount !== '' && parseInt(amount, 10) >= 1 && time !== '' && (kind === 'investment' || cat !== '');
  }

  function updateRowWarn(tr) {
    var cat = (tr.querySelector('.pay-import-cat') || {});
    var missing = !cat.value;
    tr.classList.toggle('pay-import-row-warn', !rowIsValid(tr));
    if (cat.classList) cat.classList.toggle('pay-import-cat-missing', missing);
  }

  function updateConfirmState() {
    if (step !== 'preview' || !nextBtn) return;
    var rows = bodyEl ? bodyEl.querySelectorAll('tr') : [];
    var ok = rows.length > 0;
    rows.forEach(function (tr) {
      updateRowWarn(tr);
      if (!rowIsValid(tr)) ok = false;
    });
    nextBtn.disabled = busy || !ok;
  }

  function renderStats(stats) {
    stats = stats || {};
    var unmatched = 0;
    if (bodyEl) {
      bodyEl.querySelectorAll('.pay-import-cat').forEach(function (sel) {
        if (!sel.value) unmatched++;
      });
    } else {
      unmatched = stats.unmatched || 0;
    }
    statsEl.innerHTML = ''
      + '<span class="tag tag-info">' + (stats.total || previewRows.length) + ' سطر</span>'
      + (stats.dollar ? '<span class="tag tag-ok">' + stats.dollar + ' سطر دلاری</span>' : '')
      + (unmatched ? '<span class="tag tag-warn">' + unmatched + ' دسته یافت نشده</span>' : '');
  }

  function renderPreview(payload) {
    previewRows = payload.rows || [];
    expenseCategories = payload.expense_categories || {};
    incomeCategories = payload.income_categories || {};
    investmentCategories = { capital_injection: payload.investment_label || 'ورود سرمایه' };
    bodyEl.innerHTML = previewRows.map(function (row, idx) {
      var kind = row.kind === 'income' ? 'income' : (row.kind === 'investment' ? 'investment' : 'expense');
      var cat = kind === 'investment' ? 'capital_injection' : (row.category || '');
      var warnings = row.warnings || [];
      var warnClass = warnings.length ? ' pay-import-row-warn' : '';
      return '<tr class="' + warnClass + '" data-source-row="' + escapeHtml(row.source_row || '') + '">'
        + '<td>' + (idx + 1) + '</td>'
        + '<td><select class="select pay-import-kind">'
        + '<option value="expense"' + (kind === 'expense' ? ' selected' : '') + '>هزینه</option>'
        + '<option value="income"' + (kind === 'income' ? ' selected' : '') + '>درآمد</option>'
        + '<option value="investment"' + (kind === 'investment' ? ' selected' : '') + '>سرمایه‌گذاری</option>'
        + '</select></td>'
        + '<td><input class="input jalali-datetime-picker pay-import-time" type="text" value="'
        + escapeHtml(row.time || '') + '" placeholder="تاریخ و ساعت" autocomplete="off"></td>'
        + '<td><input class="input pay-import-amount" type="text" inputmode="numeric" value="'
        + escapeHtml(formatPrice(String(row.amount || ''))) + '"></td>'
        + '<td><input class="input pay-import-note" type="text" value="' + escapeHtml(row.note || '') + '"></td>'
        + '<td><select class="select pay-import-cat' + (cat ? '' : ' pay-import-cat-missing') + '">'
        + categoryOptionsHtml(kind, cat)
        + '</select></td>'
        + '</tr>';
    }).join('');
    bodyEl.querySelectorAll('.pay-import-time').forEach(initJalaliPicker);
    renderStats(payload.stats || {});
    updateConfirmState();
  }

  function parseFile() {
    if (!selectedFile) {
      showError('فایل را انتخاب کنید.');
      return;
    }
    var fd = new FormData();
    fd.append('_csrf', cfg.csrf);
    fd.append('action', 'import_parse');
    fd.append('tab', cfg.tab || 'list');
    fd.append('file', selectedFile);
    fd.append('usd_rate', (rateInput && rateInput.value) ? rateInput.value : '');
    setBusy(true);
    showError('');
    fetch('payment.php?tab=' + encodeURIComponent(cfg.tab || 'list'), {
      method: 'POST',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json'
      },
      body: fd
    }).then(function (res) {
      return res.json().then(function (data) {
        if (!res.ok || !data.ok) {
          throw new Error(data.msg || 'پردازش فایل ناموفق بود.');
        }
        return data;
      });
    }).then(function (data) {
      renderPreview(data);
      setStep('preview');
    }).catch(function (err) {
      showError(err.message || 'پردازش فایل ناموفق بود.');
    }).finally(function () {
      setBusy(false);
      if (step === 'rate') nextBtn.disabled = false;
      if (step === 'preview') updateConfirmState();
    });
  }

  function commitRows() {
    var rows = collectPreviewRows();
    if (!rows.length) {
      showError('سطری برای ورود وجود ندارد.');
      return;
    }
    var invalid = rows.findIndex(function (row) {
      return !row.kind || !row.time || !row.amount || parseInt(row.amount, 10) < 1
        || (row.kind !== 'investment' && !row.category);
    });
    if (invalid !== -1) {
      showError('سطر ' + (invalid + 1) + ' را کامل کنید. دسته، تاریخ و مبلغ الزامی است.');
      updateConfirmState();
      return;
    }
    var bodyData = new URLSearchParams();
    bodyData.set('_csrf', cfg.csrf);
    bodyData.set('action', 'import_commit');
    bodyData.set('tab', cfg.tab || 'list');
    bodyData.set('rows', JSON.stringify(rows));
    setBusy(true);
    showError('');
    fetch('payment.php?tab=' + encodeURIComponent(cfg.tab || 'list'), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json'
      },
      body: bodyData.toString()
    }).then(function (res) {
      return res.json().then(function (data) {
        if (!res.ok || !data.ok) {
          throw new Error(data.msg || 'ورود داده‌ها ناموفق بود.');
        }
        return data;
      });
    }).then(function (data) {
      toast(data.msg || 'ورود داده انجام شد.', 'ok');
      closeModal('paymentImportModal');
      window.location.reload();
    }).catch(function (err) {
      showError(err.message || 'ورود داده‌ها ناموفق بود.');
      setBusy(false);
      updateConfirmState();
    });
  }

  openBtn.addEventListener('click', function () {
    resetWizard();
    openModal('paymentImportModal');
  });

  fileInput.addEventListener('change', function () {
    var file = fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
    selectedFile = file;
    fileNameEl.textContent = file ? file.name : '';
    if (step === 'file') nextBtn.disabled = !file;
    showError('');
  });

  nextBtn.addEventListener('click', function () {
    if (busy) return;
    if (step === 'file') {
      if (!selectedFile) {
        showError('فایل را انتخاب کنید.');
        return;
      }
      setStep('rate');
      return;
    }
    if (step === 'rate') {
      parseFile();
      return;
    }
    commitRows();
  });

  backBtn.addEventListener('click', function () {
    if (busy) return;
    if (step === 'rate') setStep('file');
    else if (step === 'preview') setStep('rate');
  });

  bodyEl.addEventListener('input', function (e) {
    var t = e.target;
    if (t.classList.contains('pay-import-amount')) {
      t.value = formatPrice(t.value);
    }
    updateConfirmState();
    renderStats({ total: previewRows.length });
  });

  bodyEl.addEventListener('change', function (e) {
    var t = e.target;
    if (t.classList.contains('pay-import-kind')) {
      var tr = t.closest('tr');
      var cat = tr ? tr.querySelector('.pay-import-cat') : null;
      if (cat) {
        if (t.value === 'investment') {
          cat.innerHTML = categoryOptionsHtml('investment', 'capital_injection');
          cat.value = 'capital_injection';
        } else {
          var prev = cat.value;
          cat.innerHTML = categoryOptionsHtml(t.value, prev);
          if (!categoryMap(t.value)[prev]) cat.value = '';
        }
      }
    }
    updateConfirmState();
    renderStats({ total: previewRows.length });
  });
})();
