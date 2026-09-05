(function () {
  var cfg = window.PAYMENT_SHEET || null;
  if (!cfg) return;

  var body = document.getElementById('paySheetBody');
  var addBtn = document.getElementById('payAddRowBtn');
  if (!body) return;

  var pendingStatus = null;
  var isCostTab = cfg.tab === 'costs';
  var isInvestmentTab = cfg.tab === 'investment';

  function rowIsCost(row) {
    return isCostTab || !!(row && row.classList.contains('is-cost'));
  }

  function rowIsInvestment(row) {
    return isInvestmentTab || !!(row && row.classList.contains('is-investment'));
  }

  function rowIsLocked(row) {
    return rowIsCost(row) || rowIsInvestment(row);
  }

  function escapeHtml(str) {
    return String(str == null ? '' : str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function trunc(str, max) {
    str = String(str || '');
    return str.length > max ? str.slice(0, max) + '…' : str;
  }

  function toast(msg, type) {
    if (window.toast) window.toast(msg, type === 'error' ? 'no' : 'ok');
  }

  function randomOrderId() {
    var bytes = new Uint8Array(5);
    if (window.crypto && crypto.getRandomValues) {
      crypto.getRandomValues(bytes);
    } else {
      for (var i = 0; i < 5; i++) bytes[i] = Math.floor(Math.random() * 256);
    }
    return Array.from(bytes, function (b) {
      return ('0' + b.toString(16)).slice(-2);
    }).join('');
  }

  function pickerOptions($el) {
    return {
      calendarType: 'persian',
      format: 'YYYY/MM/DD HH:mm',
      initialValue: $el.val() !== '',
      initialValueType: 'persian',
      autoClose: false,
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
      },
      onShow: function () {
        setTimeout(function () {
          document.querySelectorAll('.pwt-btn-today, .toolbox-today-button').forEach(function (btn) {
            if (btn.textContent) btn.textContent = 'اکنون';
          });
        }, 0);
      }
    };
  }

  function initJalaliPicker(input) {
    if (!window.jQuery || !input || input.dataset.pdp === '1') return;
    var $el = window.jQuery(input);
    $el.persianDatepicker(pickerOptions($el));
    input.dataset.pdp = '1';
  }

  function setPickerNow(input) {
    if (window.persianDate) {
      try {
        input.value = new window.persianDate().format('YYYY/MM/DD HH:mm');
        return;
      } catch (e) {}
    }
    input.value = cfg.nowJalali || '';
  }

  function methodOptionsHtml(selected) {
    var html = '';
    Object.keys(cfg.methodOptions || {}).forEach(function (key) {
      html += '<button type="button" class="pay-sheet-menu-item' + (key === selected ? ' active' : '') + '" data-value="'
        + escapeHtml(key) + '">' + escapeHtml(cfg.methodOptions[key]) + '</button>';
    });
    return html;
  }

  function categoryOptionsHtml(selected) {
    var html = '';
    Object.keys(cfg.categoryOptions || {}).forEach(function (key) {
      html += '<button type="button" class="pay-sheet-menu-item' + (key === selected ? ' active' : '') + '" data-value="'
        + escapeHtml(key) + '">' + escapeHtml(cfg.categoryOptions[key]) + '</button>';
    });
    return html;
  }

  function statusOptionsHtml(selected) {
    var html = '';
    Object.keys(cfg.statusOptions || {}).forEach(function (key) {
      var meta = cfg.statusOptions[key];
      html += '<button type="button" class="pay-sheet-menu-item' + (key === selected ? ' active' : '') + '" data-value="'
        + escapeHtml(key) + '"><span class="tag ' + escapeHtml(meta.cls) + '">' + escapeHtml(meta.lbl) + '</span></button>';
    });
    return html;
  }

  function userViewHtml(uid, known) {
    if (!uid || uid === '0') {
      return '<span style="color:var(--text-dim)">بدون کاربر</span>';
    }
    if (known) {
      return '<a href="user.php?id=' + encodeURIComponent(uid) + '" class="cell-mono" style="color:var(--accent)">'
        + escapeHtml(uid) + '</a>';
    }
    return '<span>' + escapeHtml(uid) + '</span>';
  }

  function noteViewHtml(note) {
    if (!note) return '<span style="color:var(--text-dim)">—</span>';
    return escapeHtml(trunc(note, 40));
  }

  function statusMeta(status) {
    if (status === 'cost') return cfg.costStatus || { cls: 'tag-plain', lbl: 'هزینه شده' };
    return (cfg.statusOptions && cfg.statusOptions[status]) || { cls: 'tag-plain', lbl: status || '—' };
  }

  function closePickers() {
    var menu = document.getElementById('paySheetMenu');
    if (menu) {
      menu.hidden = true;
      menu.innerHTML = '';
      menu._payRow = null;
      menu._payKind = null;
    }
    body.querySelectorAll('.pay-sheet-row').forEach(function (row) {
      row.classList.remove('is-picking-status', 'is-picking-method');
    });
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

  function formatPriceInput(input) {
    if (!input) return;
    var start = input.selectionStart;
    var before = parsePrice(input.value.slice(0, start)).length;
    var formatted = formatPrice(input.value);
    input.value = formatted;
    var pos = 0;
    var seen = 0;
    while (pos < formatted.length && seen < before) {
      if (/\d/.test(formatted.charAt(pos))) seen++;
      pos++;
    }
    try { input.setSelectionRange(pos, pos); } catch (e) {}
  }

  function collectRow(row) {
    var methodVal = row.querySelector('.pay-method-value');
    var statusVal = row.querySelector('.pay-status-value');
    var isCost = rowIsCost(row);
    var isInvestment = rowIsInvestment(row);
    return {
      order_id: row.dataset.orderId || '',
      id_user: (row.querySelector('.pay-user-input') || {}).value || '',
      amount: parsePrice((row.querySelector('.pay-price-input') || {}).value || ''),
      payment_method: isCost ? 'cost' : (isInvestment ? (cfg.investmentMethod || 'capital_injection') : (methodVal ? methodVal.value : (row.dataset.method || ''))),
      expense_category: isCost ? (methodVal ? methodVal.value : (row.dataset.category || '')) : '',
      note: (row.querySelector('.pay-note-input') || {}).value || '',
      time: (row.querySelector('.pay-time-input') || {}).value || '',
      status: isCost ? 'cost' : (isInvestment ? 'investment' : (statusVal ? statusVal.value : (row.dataset.status || ''))),
      is_new: row.classList.contains('is-new')
    };
  }

  function postForm(action, fields) {
    var bodyData = new URLSearchParams();
    bodyData.set('action', action);
    bodyData.set('_csrf', cfg.csrf);
    bodyData.set('tab', cfg.tab);
    Object.keys(fields || {}).forEach(function (key) {
      if (fields[key] === true) bodyData.set(key, '1');
      else if (fields[key] === false || fields[key] == null) return;
      else bodyData.set(key, String(fields[key]));
    });
    return fetch('payment.php?tab=' + encodeURIComponent(cfg.tab), {
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
          throw new Error(data.msg || data.error || 'خطا در ذخیره');
        }
        return data;
      });
    });
  }

  function applyRowData(row, data) {
    if (!data) return;
    row.classList.remove('is-new', 'is-editing', 'is-picking-status', 'is-picking-method');
    row.dataset.orderId = data.id_order || '';
    row.dataset.status = data.status || '';
    row.dataset.method = data.method || '';
    row.dataset.category = data.expense_category || '';
    row.dataset.hasProduct = data.has_product ? '1' : '0';
    if (data.is_cost) row.classList.add('is-cost');
    else row.classList.remove('is-cost');
    if (data.is_investment) row.classList.add('is-investment');
    else row.classList.remove('is-investment');

    var oid = row.querySelector('.pay-oid');
    if (oid) oid.textContent = data.id_order || '';

    var userInput = row.querySelector('.pay-user-input');
    var userView = row.querySelector('.pay-user-view');
    if (userInput) userInput.value = data.id_user || '';
    if (userView) userView.innerHTML = userViewHtml(data.id_user, data.user_known);

    var priceInput = row.querySelector('.pay-price-input');
    var priceView = row.querySelector('.pay-price-view');
    if (priceInput) priceInput.value = formatPrice(String(data.price || ''));
    if (priceView) {
      priceView.innerHTML = escapeHtml(data.price_fmt || '0')
        + ' <span style="color:var(--text-dim);font-weight:400;font-size:.72rem">ت</span>';
    }

    var methodLabel = row.querySelector('.pay-method-label');
    var methodVal = row.querySelector('.pay-method-value');
    if (data.is_cost || isCostTab) {
      if (methodLabel) methodLabel.textContent = data.category_label || '—';
      if (methodVal) methodVal.value = data.expense_category || '';
    } else if (data.is_investment || isInvestmentTab) {
      if (methodLabel) methodLabel.textContent = data.method_label || cfg.investmentLabel || 'ورود سرمایه';
      if (methodVal) methodVal.value = cfg.investmentMethod || 'capital_injection';
    } else {
      if (methodLabel) methodLabel.textContent = data.method_label || '—';
      if (methodVal) methodVal.value = data.method || '';
    }

    var noteInput = row.querySelector('.pay-note-input');
    var noteView = row.querySelector('.pay-note-view');
    if (noteInput) noteInput.value = data.note || '';
    if (noteView) {
      noteView.innerHTML = noteViewHtml(data.note);
      noteView.title = data.note || '';
    }

    var timeInput = row.querySelector('.pay-time-input');
    var timeView = row.querySelector('.pay-time-view');
    if (timeInput) timeInput.value = data.time || '';
    if (timeView) timeView.textContent = data.time || '—';

    var statusTag = row.querySelector('.pay-status-tag');
    var statusVal = row.querySelector('.pay-status-value');
    var meta = statusMeta(data.status);
    if (statusTag) {
      statusTag.className = 'tag ' + meta.cls + ' pay-status-tag';
      statusTag.textContent = meta.lbl;
    }
    if (statusVal) statusVal.value = data.status || '';
  }

  function enterEdit(row) {
    closePickers();
    row.classList.add('is-editing');
    var timeInput = row.querySelector('.pay-time-input');
    if (timeInput) initJalaliPicker(timeInput);
    var first = row.querySelector('.pay-user-input');
    if (first) first.focus();
  }

  function leaveEdit(row) {
    row.classList.remove('is-editing', 'is-picking-status', 'is-picking-method');
  }

  function removeEmptyRow() {
    var empty = body.querySelector('.pay-empty-row');
    if (empty) empty.remove();
  }

  function showEmptyIfNeeded() {
    if (body.querySelector('.pay-sheet-row')) return;
    body.insertAdjacentHTML('afterbegin',
      '<tr class="pay-empty-row"><td colspan="9"><div class="empty"><div class="empty-mark">—</div><p>'
      + escapeHtml(cfg.emptyText || 'تراکنشی یافت نشد') + '</p></div></td></tr>'
    );
  }

  function needsRejectPrompt(fromStatus, toStatus, hasProduct) {
    return fromStatus === 'paid' && toStatus === 'reject';
  }

  function revertStatus(row, prev) {
    var val = row.querySelector('.pay-status-value');
    if (val) val.value = prev;
    var meta = statusMeta(prev);
    var tag = row.querySelector('.pay-status-tag');
    if (tag) {
      tag.className = 'tag ' + meta.cls + ' pay-status-tag';
      tag.textContent = meta.lbl;
    }
    closePickers();
  }

  function openStatusSideModal(row, prev, next, afterConfirm) {
    var modal = document.getElementById('statusSideModal');
    if (!modal) {
      afterConfirm(false, false);
      return;
    }
    pendingStatus = { row: row, prev: prev, next: next, afterConfirm: afterConfirm };
    var hasProduct = row.dataset.hasProduct === '1';
    var rejectWrap = document.getElementById('rejectInvoiceWrap');
    var removeWrap = document.getElementById('removeProductWrap');
    var rejectCheck = document.getElementById('rejectInvoiceCheck');
    var removeCheck = document.getElementById('removeProductCheck');
    if (rejectWrap) rejectWrap.style.display = 'block';
    if (removeWrap) removeWrap.style.display = hasProduct ? 'block' : 'none';
    if (rejectCheck) rejectCheck.checked = false;
    if (removeCheck) removeCheck.checked = false;
    openModal('statusSideModal');
  }

  function saveStatus(row, next, rejectInvoice, removeProduct) {
    postForm('set_status', {
      order_id: row.dataset.orderId,
      new_status: next,
      reject_invoice: !!rejectInvoice,
      remove_product: !!removeProduct
    }).then(function (data) {
      applyRowData(row, data.row);
      toast(data.msg || 'وضعیت ذخیره شد.', 'ok');
    }).catch(function (err) {
      revertStatus(row, row.dataset.status);
      toast(err.message || 'خطا در تغییر وضعیت', 'error');
    });
  }

  function saveMethod(row) {
    var fields = collectRow(row);
    postForm('save_row', {
      order_id: fields.order_id,
      id_user: fields.id_user,
      amount: fields.amount,
      payment_method: fields.payment_method,
      expense_category: fields.expense_category,
      note: fields.note,
      time: fields.time,
      new_status: fields.status
    }).then(function (data) {
      applyRowData(row, data.row);
      toast(data.msg || (isCostTab ? 'دسته هزینه ذخیره شد.' : 'روش پرداخت ذخیره شد.'), 'ok');
    }).catch(function (err) {
      var val = row.querySelector('.pay-method-value');
      var prev = isCostTab ? (row.dataset.category || '') : (row.dataset.method || '');
      if (val) val.value = prev;
      var label = row.querySelector('.pay-method-label');
      var opts = isCostTab ? cfg.categoryOptions : cfg.methodOptions;
      if (label && opts) {
        label.textContent = opts[prev] || prev || '—';
      }
      closePickers();
      toast(err.message || (isCostTab ? 'خطا در ذخیره دسته هزینه' : 'خطا در ذخیره روش پرداخت'), 'error');
    });
  }

  function saveRow(row) {
    var fields = collectRow(row);
    if (!fields.amount || Number(fields.amount) < 1) {
      toast('مبلغ باید عدد مثبت باشد.', 'error');
      return;
    }
    var send = function (rejectInvoice, removeProduct) {
      postForm('save_row', {
        order_id: fields.order_id,
        id_user: fields.id_user,
        amount: fields.amount,
        payment_method: fields.payment_method,
        expense_category: fields.expense_category,
        note: fields.note,
        time: fields.time,
        new_status: fields.status,
        reject_invoice: !!rejectInvoice,
        remove_product: !!removeProduct
      }).then(function (data) {
        applyRowData(row, data.row);
        toast(data.msg || 'ذخیره شد.', 'ok');
      }).catch(function (err) {
        toast(err.message || 'خطا در ذخیره', 'error');
      });
    };

    if (!fields.is_new && needsRejectPrompt(row.dataset.status, fields.status, row.dataset.hasProduct === '1')) {
      openStatusSideModal(row, row.dataset.status, fields.status, send);
      return;
    }
    send(false, false);
  }

  function deleteRow(row) {
    if (row.classList.contains('is-new')) {
      row.remove();
      showEmptyIfNeeded();
      return;
    }
    var run = function () {
      postForm('delete_row', { order_id: row.dataset.orderId }).then(function (data) {
        row.remove();
        showEmptyIfNeeded();
        toast(data.msg || 'حذف شد.', 'ok');
      }).catch(function (err) {
        toast(err.message || 'خطا در حذف', 'error');
      });
    };
    if (typeof window.showConfirm === 'function') {
      window.showConfirm(
        isCostTab ? 'این هزینه حذف شود؟' : (isInvestmentTab ? 'این ورود سرمایه حذف شود؟' : 'این تراکنش حذف شود؟'),
        run,
        'تأیید حذف'
      );
      return;
    }
    run();
  }

  function addRow() {
    removeEmptyRow();
    var oid = randomOrderId();
    var now = cfg.nowJalali || '';
    var defaultMethod = cfg.defaultMethod || 'manual invoice';
    var defaultStatus = 'paid';
    var defaultCategory = cfg.defaultCategory || Object.keys(cfg.categoryOptions || {})[0] || 'other';
    var methodLabel = (cfg.methodOptions && cfg.methodOptions[defaultMethod]) || 'فاکتور دستی';
    var categoryLabel = (cfg.categoryOptions && cfg.categoryOptions[defaultCategory]) || 'سایر';
    var meta = statusMeta(defaultStatus);
    var costMeta = cfg.costStatus || { cls: 'tag-plain', lbl: 'هزینه شده' };
    var investmentMeta = cfg.investmentStatus || { cls: 'tag-mint', lbl: 'ورود سرمایه' };
    var investmentMethod = cfg.investmentMethod || 'capital_injection';
    var investmentLabel = cfg.investmentLabel || 'ورود سرمایه';

    var methodCell = isCostTab
      ? '<button type="button" class="pay-dd-trigger" data-pay-menu="category"><span class="pay-method-label">'
        + escapeHtml(categoryLabel) + '</span><span class="pay-dd-caret">▾</span></button>'
        + '<input type="hidden" class="pay-method-value" value="' + escapeHtml(defaultCategory) + '">'
      : (isInvestmentTab
        ? '<span class="pay-method-label">' + escapeHtml(investmentLabel) + '</span>'
          + '<input type="hidden" class="pay-method-value" value="' + escapeHtml(investmentMethod) + '">'
        : '<button type="button" class="pay-dd-trigger" data-pay-menu="method"><span class="pay-method-label">'
          + escapeHtml(methodLabel) + '</span><span class="pay-dd-caret">▾</span></button>'
          + '<input type="hidden" class="pay-method-value" value="' + escapeHtml(defaultMethod) + '">');

    var statusCell = isCostTab
      ? '<span class="tag ' + costMeta.cls + ' pay-status-tag">' + escapeHtml(costMeta.lbl) + '</span>'
      : (isInvestmentTab
        ? '<span class="tag ' + investmentMeta.cls + ' pay-status-tag">' + escapeHtml(investmentMeta.lbl) + '</span>'
        : '<button type="button" class="pay-dd-trigger" data-pay-menu="status"><span class="tag '
          + meta.cls + ' pay-status-tag">' + escapeHtml(meta.lbl) + '</span><span class="pay-dd-caret">▾</span></button>'
          + '<input type="hidden" class="pay-status-value" value="' + escapeHtml(defaultStatus) + '">');

    var tr = document.createElement('tr');
    tr.className = 'pay-sheet-row is-new is-editing' + (isCostTab ? ' is-cost' : '') + (isInvestmentTab ? ' is-investment' : '');
    tr.dataset.orderId = oid;
    tr.dataset.status = isCostTab ? 'cost' : (isInvestmentTab ? 'investment' : defaultStatus);
    tr.dataset.method = isCostTab ? 'cost' : (isInvestmentTab ? investmentMethod : defaultMethod);
    tr.dataset.category = isCostTab ? defaultCategory : '';
    tr.dataset.hasProduct = '0';
    tr.innerHTML =
      '<td class="pay-idx" style="color:var(--text-dim)">—</td>'
      + '<td><span class="pay-view pay-user-view"><span style="color:var(--text-dim)">بدون کاربر</span></span>'
      + '<input class="input pay-edit pay-cell-input pay-user-input" type="text" value="" placeholder="آیدی یا یوزرنیم" autocomplete="off"></td>'
      + '<td class="cell-mono pay-oid">' + escapeHtml(oid) + '</td>'
      + '<td><span class="pay-view cell-strong cell-num pay-price-view">0 <span style="color:var(--text-dim);font-weight:400;font-size:.72rem">ت</span></span>'
      + '<input class="input pay-edit pay-cell-input pay-price-input" type="text" inputmode="numeric" dir="ltr" autocomplete="off" placeholder="0" value=""></td>'
      + '<td class="pay-method-view">' + methodCell + '</td>'
      + '<td><span class="pay-view pay-note-view"><span style="color:var(--text-dim)">—</span></span>'
      + '<input class="input pay-edit pay-cell-input pay-note-input" type="text" value="" placeholder="یادداشت"></td>'
      + '<td><span class="pay-view pay-time-view">' + escapeHtml(now) + '</span>'
      + '<div class="pay-edit pay-time-edit"><input class="input pay-cell-input jalali-datetime-picker pay-time-input" type="text" value="'
      + escapeHtml(now) + '" placeholder="تاریخ و ساعت" autocomplete="off">'
      + '<button type="button" class="btn btn-ghost btn-sm pay-time-now" title="تاریخ و ساعت الان">اکنون</button></div></td>'
      + '<td>' + statusCell + '</td>'
      + '<td><div class="pay-actions">'
      + '<button type="button" class="btn btn-ghost btn-sm btn-icon pay-btn-edit" title="ویرایش">' + (cfg.icons.edit || '') + '</button>'
      + '<button type="button" class="btn btn-primary btn-sm btn-icon pay-btn-save" title="ذخیره">' + (cfg.icons.save || '') + '</button>'
      + '<button type="button" class="btn btn-no btn-sm btn-icon pay-btn-delete" title="حذف">' + (cfg.icons.trash || '') + '</button>'
      + '</div></td>';

    body.insertBefore(tr, body.firstChild);
    var timeInput = tr.querySelector('.pay-time-input');
    if (timeInput) {
      setPickerNow(timeInput);
      initJalaliPicker(timeInput);
    }
    var priceInput = tr.querySelector('.pay-price-input');
    if (priceInput) priceInput.focus();
  }

  function ensureMenu() {
    var menu = document.getElementById('paySheetMenu');
    if (menu) return menu;
    menu = document.createElement('div');
    menu.id = 'paySheetMenu';
    menu.className = 'pay-sheet-menu';
    menu.hidden = true;
    menu.addEventListener('click', function (e) {
      var btn = e.target.closest('.pay-sheet-menu-item');
      if (!btn) return;
      e.preventDefault();
      e.stopPropagation();
      var row = menu._payRow;
      var kind = menu._payKind;
      var value = btn.getAttribute('data-value') || '';
      if (row && kind === 'status') applyStatusChoice(row, value);
      else if (row && (kind === 'method' || kind === 'category')) applyMethodChoice(row, value, kind);
      else if (row && kind === 'user') applyUserChoice(row, value);
    });
    document.body.appendChild(menu);
    return menu;
  }

  function positionMenu(menu, anchor) {
    var rect = anchor.getBoundingClientRect();
    var vw = document.documentElement.clientWidth;
    var vh = document.documentElement.clientHeight;
    menu.style.visibility = 'hidden';
    menu.hidden = false;
    var mw = menu.offsetWidth;
    var mh = menu.offsetHeight;
    var top = rect.bottom + 6;
    if (top + mh > vh - 8 && rect.top - 6 - mh > 8) {
      top = rect.top - 6 - mh;
    }
    var left = rect.right - mw;
    if (left < 8) left = 8;
    if (left + mw > vw - 8) left = Math.max(8, vw - mw - 8);
    menu.style.top = Math.max(8, top) + 'px';
    menu.style.left = left + 'px';
    menu.style.right = 'auto';
    menu.style.visibility = 'visible';
  }

  function openCellMenu(row, kind, anchor) {
    if (rowIsLocked(row) && (kind === 'status' || kind === 'method')) return;
    var existing = document.getElementById('paySheetMenu');
    if (existing && !existing.hidden && existing._payRow === row && existing._payKind === kind) {
      closePickers();
      return;
    }
    closePickers();
    var menu = ensureMenu();
    var current;
    var html;
    if (kind === 'status') {
      current = (row.querySelector('.pay-status-value') || {}).value || row.dataset.status || '';
      html = statusOptionsHtml(current);
    } else if (kind === 'category') {
      current = (row.querySelector('.pay-method-value') || {}).value || row.dataset.category || '';
      html = categoryOptionsHtml(current);
    } else {
      current = (row.querySelector('.pay-method-value') || {}).value || row.dataset.method || '';
      html = methodOptionsHtml(current);
    }
    menu.innerHTML = html;
    menu._payRow = row;
    menu._payKind = kind;
    row.classList.add(kind === 'status' ? 'is-picking-status' : 'is-picking-method');
    positionMenu(menu, anchor);
  }

  function applyStatusChoice(row, next) {
    var prev = row.dataset.status;
    var val = row.querySelector('.pay-status-value');
    if (val) val.value = next;
    var meta = statusMeta(next);
    var tag = row.querySelector('.pay-status-tag');
    if (tag) {
      tag.className = 'tag ' + meta.cls + ' pay-status-tag';
      tag.textContent = meta.lbl;
    }
    closePickers();
    if (row.classList.contains('is-editing') || row.classList.contains('is-new')) {
      return;
    }
    if (prev === next) return;
    if (needsRejectPrompt(prev, next, row.dataset.hasProduct === '1')) {
      openStatusSideModal(row, prev, next, function (rejectInvoice, removeProduct) {
        saveStatus(row, next, rejectInvoice, removeProduct);
      });
      return;
    }
    saveStatus(row, next, false, false);
  }

  function applyMethodChoice(row, next, kind) {
    var isCategory = kind === 'category' || isCostTab || row.classList.contains('is-cost');
    var val = row.querySelector('.pay-method-value');
    if (val) val.value = next;
    var label = row.querySelector('.pay-method-label');
    var opts = isCategory ? cfg.categoryOptions : cfg.methodOptions;
    if (label && opts) {
      label.textContent = opts[next] || next;
    }
    closePickers();
    if (row.classList.contains('is-editing') || row.classList.contains('is-new')) return;
    var prev = isCategory ? (row.dataset.category || '') : (row.dataset.method || '');
    if (next === prev) return;
    saveMethod(row);
  }

  function applyUserChoice(row, id) {
    var input = row.querySelector('.pay-user-input');
    if (input) {
      input.value = id || '';
      input.focus();
    }
    closePickers();
  }

  function userResultsHtml(users) {
    if (!users.length) {
      return '<div class="pay-sheet-menu-empty">کاربری یافت نشد</div>';
    }
    return users.map(function (u, i) {
      var username = u.username || '';
      var name = u.name || '';
      var title = username ? '@' + username : (name || ('کاربر #' + u.id));
      var meta = [];
      if (username && name) meta.push(name);
      meta.push(u.id);
      return '<button type="button" class="pay-sheet-menu-item pay-user-item' + (i === 0 ? ' active' : '') + '" data-value="'
        + escapeHtml(u.id) + '"><span class="pay-user-item-title">' + escapeHtml(title)
        + '</span><span class="pay-user-item-meta">' + escapeHtml(meta.join(' · ')) + '</span></button>';
    }).join('');
  }

  var userSearchTimer = null;
  var userSearchSeq = 0;

  function showUserResults(row, input, users) {
    var menu = ensureMenu();
    menu.innerHTML = userResultsHtml(users);
    menu._payRow = row;
    menu._payKind = 'user';
    positionMenu(menu, input);
  }

  function scheduleUserSearch(input) {
    var row = input.closest('.pay-sheet-row');
    if (!row) return;
    var q = String(input.value || '').trim().replace(/^@+/, '');
    clearTimeout(userSearchTimer);
    if (q.length < 1) {
      var menu = document.getElementById('paySheetMenu');
      if (menu && menu._payKind === 'user') closePickers();
      return;
    }
    userSearchTimer = setTimeout(function () {
      var seq = ++userSearchSeq;
      postForm('search_users', { q: q }).then(function (data) {
        if (seq !== userSearchSeq) return;
        if (document.activeElement !== input) return;
        showUserResults(row, input, data.users || []);
      }).catch(function () {});
    }, 200);
  }

  function moveUserHighlight(delta) {
    var menu = document.getElementById('paySheetMenu');
    if (!menu || menu.hidden || menu._payKind !== 'user') return;
    var items = menu.querySelectorAll('.pay-sheet-menu-item');
    if (!items.length) return;
    var idx = Array.prototype.findIndex.call(items, function (el) {
      return el.classList.contains('active');
    });
    if (idx < 0) idx = 0;
    else idx = (idx + delta + items.length) % items.length;
    items.forEach(function (el) { el.classList.remove('active'); });
    items[idx].classList.add('active');
    if (items[idx].scrollIntoView) items[idx].scrollIntoView({ block: 'nearest' });
  }

  function selectHighlightedUser() {
    var menu = document.getElementById('paySheetMenu');
    if (!menu || menu.hidden || menu._payKind !== 'user' || !menu._payRow) return false;
    var item = menu.querySelector('.pay-sheet-menu-item.active') || menu.querySelector('.pay-sheet-menu-item');
    if (!item) return false;
    applyUserChoice(menu._payRow, item.getAttribute('data-value') || '');
    return true;
  }

  body.addEventListener('input', function (e) {
    if (e.target.classList.contains('pay-price-input')) {
      formatPriceInput(e.target);
      return;
    }
    if (!e.target.classList.contains('pay-user-input')) return;
    scheduleUserSearch(e.target);
  });

  body.addEventListener('focusin', function (e) {
    if (!e.target.classList.contains('pay-user-input')) return;
    if (String(e.target.value || '').trim()) scheduleUserSearch(e.target);
  });

  body.addEventListener('keydown', function (e) {
    if (!e.target.classList.contains('pay-user-input')) return;
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      moveUserHighlight(1);
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      moveUserHighlight(-1);
    } else if (e.key === 'Enter') {
      if (selectHighlightedUser()) e.preventDefault();
    }
  });

  body.addEventListener('click', function (e) {
    var row = e.target.closest('.pay-sheet-row');
    var trigger = e.target.closest('.pay-dd-trigger');
    if (trigger && row) {
      var kind = trigger.getAttribute('data-pay-menu');
      if (rowIsLocked(row) && (kind === 'status' || kind === 'method')) return;
      e.preventDefault();
      e.stopPropagation();
      openCellMenu(row, kind, trigger);
      return;
    }

    if (!row) return;

    if (e.target.closest('.pay-btn-edit')) {
      closePickers();
      enterEdit(row);
      return;
    }
    if (e.target.closest('.pay-btn-save')) {
      closePickers();
      saveRow(row);
      return;
    }
    if (e.target.closest('.pay-btn-delete')) {
      closePickers();
      deleteRow(row);
      return;
    }
    if (e.target.closest('.pay-time-now')) {
      var timeInput = row.querySelector('.pay-time-input');
      if (timeInput) {
        setPickerNow(timeInput);
        if (timeInput.dataset.pdp === '1' && window.jQuery) {
          window.jQuery(timeInput).trigger('change');
        }
      }
    }
  });

  document.addEventListener('click', function (e) {
    if (e.target.closest('#paySheetMenu') || e.target.closest('.pay-dd-trigger') || e.target.closest('.pay-user-input')) return;
    closePickers();
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closePickers();
  });

  window.addEventListener('resize', closePickers);
  window.addEventListener('scroll', function (e) {
    var t = e.target;
    if (t && t.closest && t.closest('#paySheetMenu')) return;
    closePickers();
  }, true);

  if (addBtn) addBtn.addEventListener('click', addRow);

  var confirmBtn = document.getElementById('statusSideConfirm');
  var cancelBtn = document.getElementById('statusSideCancel');
  if (confirmBtn) {
    confirmBtn.addEventListener('click', function () {
      if (!pendingStatus) {
        closeModal('statusSideModal');
        return;
      }
      var rejectInvoice = !!(document.getElementById('rejectInvoiceCheck') || {}).checked;
      var removeProduct = !!(document.getElementById('removeProductCheck') || {}).checked;
      var cb = pendingStatus.afterConfirm;
      pendingStatus = null;
      closeModal('statusSideModal');
      if (cb) cb(rejectInvoice, removeProduct);
    });
  }
  if (cancelBtn) {
    cancelBtn.addEventListener('click', function () {
      if (pendingStatus) {
        revertStatus(pendingStatus.row, pendingStatus.prev);
        pendingStatus = null;
      }
      closeModal('statusSideModal');
    });
  }

})();
