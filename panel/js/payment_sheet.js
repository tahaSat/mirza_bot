(function () {
  var cfg = window.PAYMENT_SHEET || null;
  if (!cfg) return;

  var body = document.getElementById('paySheetBody');
  var addBtn = document.getElementById('payAddRowBtn');
  if (!body) return;

  var pendingStatus = null;
  var isCostTab = cfg.tab === 'costs';

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
      html += '<option value="' + escapeHtml(key) + '"' + (key === selected ? ' selected' : '') + '>'
        + escapeHtml(cfg.methodOptions[key]) + '</option>';
    });
    return html;
  }

  function statusOptionsHtml(selected) {
    var html = '';
    Object.keys(cfg.statusOptions || {}).forEach(function (key) {
      html += '<option value="' + escapeHtml(key) + '"' + (key === selected ? ' selected' : '') + '>'
        + escapeHtml(cfg.statusOptions[key].lbl) + '</option>';
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

  function closePickers(except) {
    body.querySelectorAll('.pay-sheet-row').forEach(function (row) {
      if (row === except) return;
      row.classList.remove('is-picking-status', 'is-picking-method');
    });
  }

  function collectRow(row) {
    var methodSel = row.querySelector('.pay-method-select');
    var statusSel = row.querySelector('.pay-status-select');
    var isCost = row.classList.contains('is-cost') || isCostTab;
    return {
      order_id: row.dataset.orderId || '',
      id_user: (row.querySelector('.pay-user-input') || {}).value || '',
      amount: (row.querySelector('.pay-price-input') || {}).value || '',
      payment_method: isCost ? 'cost' : (methodSel ? methodSel.value : (row.dataset.method || '')),
      note: (row.querySelector('.pay-note-input') || {}).value || '',
      time: (row.querySelector('.pay-time-input') || {}).value || '',
      status: isCost ? 'cost' : (statusSel ? statusSel.value : (row.dataset.status || '')),
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
    row.dataset.hasProduct = data.has_product ? '1' : '0';
    if (data.is_cost) row.classList.add('is-cost');

    var oid = row.querySelector('.pay-oid');
    if (oid) oid.textContent = data.id_order || '';

    var userInput = row.querySelector('.pay-user-input');
    var userView = row.querySelector('.pay-user-view');
    if (userInput) userInput.value = data.id_user || '';
    if (userView) userView.innerHTML = userViewHtml(data.id_user, data.user_known);

    var priceInput = row.querySelector('.pay-price-input');
    var priceView = row.querySelector('.pay-price-view');
    if (priceInput) priceInput.value = data.price || '';
    if (priceView) {
      priceView.innerHTML = escapeHtml(data.price_fmt || '0')
        + ' <span style="color:var(--text-dim);font-weight:400;font-size:.72rem">ت</span>';
    }

    var methodLabel = row.querySelector('.pay-method-label');
    var methodSel = row.querySelector('.pay-method-select');
    if (methodLabel) methodLabel.textContent = data.method_label || '—';
    if (methodSel) methodSel.value = data.method || '';

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
    var statusSel = row.querySelector('.pay-status-select');
    var meta = statusMeta(data.status);
    if (statusTag) {
      statusTag.className = 'tag ' + meta.cls + (data.is_cost ? '' : ' pay-view') + ' pay-status-tag';
      statusTag.textContent = meta.lbl;
    }
    if (statusSel) statusSel.value = data.status || '';
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
    var sel = row.querySelector('.pay-status-select');
    if (sel) sel.value = prev;
    row.classList.remove('is-picking-status');
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
      note: fields.note,
      time: fields.time,
      new_status: fields.status
    }).then(function (data) {
      applyRowData(row, data.row);
      toast(data.msg || 'روش پرداخت ذخیره شد.', 'ok');
    }).catch(function (err) {
      var sel = row.querySelector('.pay-method-select');
      if (sel) sel.value = row.dataset.method || '';
      row.classList.remove('is-picking-method');
      toast(err.message || 'خطا در ذخیره روش پرداخت', 'error');
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
    if (!confirm(isCostTab ? 'این هزینه حذف شود؟' : 'این تراکنش حذف شود؟')) return;
    postForm('delete_row', { order_id: row.dataset.orderId }).then(function (data) {
      row.remove();
      showEmptyIfNeeded();
      toast(data.msg || 'حذف شد.', 'ok');
    }).catch(function (err) {
      toast(err.message || 'خطا در حذف', 'error');
    });
  }

  function addRow() {
    removeEmptyRow();
    var oid = randomOrderId();
    var now = cfg.nowJalali || '';
    var defaultMethod = 'manual invoice';
    var defaultStatus = 'paid';
    var methodLabel = (cfg.methodOptions && cfg.methodOptions[defaultMethod]) || 'فاکتور دستی';
    var meta = statusMeta(defaultStatus);
    var costMeta = cfg.costStatus || { cls: 'tag-plain', lbl: 'هزینه شده' };

    var methodCell = isCostTab
      ? '<span class="pay-method-label">هزینه</span>'
      : '<span class="pay-view pay-method-label">' + escapeHtml(methodLabel) + '</span>'
        + '<select class="select pay-method-select">' + methodOptionsHtml(defaultMethod) + '</select>';

    var statusCell = isCostTab
      ? '<span class="tag ' + costMeta.cls + ' pay-status-tag">' + escapeHtml(costMeta.lbl) + '</span>'
      : '<span class="tag ' + meta.cls + ' pay-view pay-status-tag">' + escapeHtml(meta.lbl) + '</span>'
        + '<select class="select pay-status-select">' + statusOptionsHtml(defaultStatus) + '</select>';

    var tr = document.createElement('tr');
    tr.className = 'pay-sheet-row is-new is-editing' + (isCostTab ? ' is-cost' : '');
    tr.dataset.orderId = oid;
    tr.dataset.status = isCostTab ? 'cost' : defaultStatus;
    tr.dataset.method = isCostTab ? 'cost' : defaultMethod;
    tr.dataset.hasProduct = '0';
    tr.innerHTML =
      '<td class="pay-idx" style="color:var(--text-dim)">—</td>'
      + '<td><span class="pay-view pay-user-view"><span style="color:var(--text-dim)">بدون کاربر</span></span>'
      + '<input class="input pay-edit pay-cell-input pay-user-input" type="text" value="" placeholder="آیدی کاربر"></td>'
      + '<td class="cell-mono pay-oid">' + escapeHtml(oid) + '</td>'
      + '<td><span class="pay-view cell-strong cell-num pay-price-view">0 <span style="color:var(--text-dim);font-weight:400;font-size:.72rem">ت</span></span>'
      + '<input class="input pay-edit pay-cell-input pay-price-input" type="number" min="1" step="1" value=""></td>'
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

  body.addEventListener('click', function (e) {
    var row = e.target.closest('.pay-sheet-row');
    if (!row) return;

    if (e.target.closest('.pay-btn-edit')) {
      enterEdit(row);
      return;
    }
    if (e.target.closest('.pay-btn-save')) {
      saveRow(row);
      return;
    }
    if (e.target.closest('.pay-btn-delete')) {
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
      return;
    }

    if (row.classList.contains('is-editing') || row.classList.contains('is-new') || row.classList.contains('is-cost')) {
      return;
    }

    if (e.target.closest('.pay-status-tag')) {
      closePickers(row);
      row.classList.add('is-picking-status');
      var sel = row.querySelector('.pay-status-select');
      if (sel) {
        sel.focus();
        if (typeof sel.showPicker === 'function') {
          try { sel.showPicker(); } catch (err) {}
        }
      }
      return;
    }
    if (e.target.closest('.pay-method-label')) {
      closePickers(row);
      row.classList.add('is-picking-method');
      var msel = row.querySelector('.pay-method-select');
      if (msel) {
        msel.focus();
        if (typeof msel.showPicker === 'function') {
          try { msel.showPicker(); } catch (err) {}
        }
      }
    }
  });

  body.addEventListener('change', function (e) {
    var row = e.target.closest('.pay-sheet-row');
    if (!row) return;

    if (e.target.classList.contains('pay-status-select')) {
      var prev = row.dataset.status;
      var next = e.target.value;
      if (row.classList.contains('is-editing') || row.classList.contains('is-new')) {
        var meta = statusMeta(next);
        var tag = row.querySelector('.pay-status-tag');
        if (tag) {
          tag.className = 'tag ' + meta.cls + ' pay-view pay-status-tag';
          tag.textContent = meta.lbl;
        }
        return;
      }
      if (prev === next) {
        row.classList.remove('is-picking-status');
        return;
      }
      if (needsRejectPrompt(prev, next, row.dataset.hasProduct === '1')) {
        openStatusSideModal(row, prev, next, function (rejectInvoice, removeProduct) {
          saveStatus(row, next, rejectInvoice, removeProduct);
        });
        return;
      }
      saveStatus(row, next, false, false);
      return;
    }

    if (e.target.classList.contains('pay-method-select')) {
      var label = row.querySelector('.pay-method-label');
      if (label && cfg.methodOptions) {
        label.textContent = cfg.methodOptions[e.target.value] || e.target.value;
      }
      if (row.classList.contains('is-editing') || row.classList.contains('is-new')) return;
      if (e.target.value === row.dataset.method) {
        row.classList.remove('is-picking-method');
        return;
      }
      saveMethod(row);
    }
  });

  body.addEventListener('focusout', function (e) {
    var row = e.target.closest('.pay-sheet-row');
    if (!row) return;
    if (!e.target.classList.contains('pay-status-select') && !e.target.classList.contains('pay-method-select')) {
      return;
    }
    setTimeout(function () {
      if (row.contains(document.activeElement)) return;
      row.classList.remove('is-picking-status', 'is-picking-method');
    }, 150);
  });

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

  if (window.jQuery) {
    window.jQuery(function () {
      document.querySelectorAll('#paymentFilterModal .jalali-datetime-picker').forEach(initJalaliPicker);
    });
  }
})();
