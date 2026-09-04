(function () {
  var modal = document.getElementById('paymentFilterModal');
  if (!modal) return;

  var PRICE_MIN = 0;
  var PRICE_MAX = 100000000;

  var form = modal.querySelector('form');
  var kindSelect = document.getElementById('payFilterKind');
  var incomeGroup = document.getElementById('payFilterIncomeGroup');
  var expenseGroup = document.getElementById('payFilterExpenseGroup');

  var priceOn = document.getElementById('payPriceFilterOn');
  var priceWrap = document.getElementById('payPriceRangeWrap');
  var minRange = document.getElementById('payPriceMinRange');
  var maxRange = document.getElementById('payPriceMaxRange');
  var minLabel = document.getElementById('payPriceMinLabel');
  var maxLabel = document.getElementById('payPriceMaxLabel');
  var priceFill = document.getElementById('payPriceFill');
  var minHidden = document.getElementById('payPriceMinHidden');
  var maxHidden = document.getElementById('payPriceMaxHidden');

  var fromDate = document.getElementById('payFilterFromDate');
  var fromTime = document.getElementById('payFilterFromTime');
  var fromHidden = document.getElementById('payFilterFrom');
  var toDate = document.getElementById('payFilterToDate');
  var toTime = document.getElementById('payFilterToTime');
  var toHidden = document.getElementById('payFilterTo');

  var ignoreDayTimeReset = false;

  function setGroupDisabled(group, disabled) {
    if (!group) return;
    group.classList.toggle('is-disabled', disabled);
    group.querySelectorAll('select, input, textarea, button').forEach(function (el) {
      el.disabled = disabled;
    });
  }

  function syncKindGroups() {
    var kind = kindSelect ? kindSelect.value : '';
    setGroupDisabled(incomeGroup, kind === 'expense');
    setGroupDisabled(expenseGroup, kind === 'income');
  }

  function fmtAmount(n) {
    return String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }

  function clampPrice(n) {
    n = parseInt(n, 10);
    if (isNaN(n)) return PRICE_MIN;
    return Math.max(PRICE_MIN, Math.min(PRICE_MAX, n));
  }

  function syncPriceFill() {
    if (!minRange || !maxRange || !priceFill) return;
    var min = clampPrice(minRange.value);
    var max = clampPrice(maxRange.value);
    var span = PRICE_MAX - PRICE_MIN || 1;
    var left = ((min - PRICE_MIN) / span) * 100;
    var right = ((max - PRICE_MIN) / span) * 100;
    priceFill.style.left = left + '%';
    priceFill.style.width = Math.max(0, right - left) + '%';
    if (minLabel) minLabel.textContent = fmtAmount(min);
    if (maxLabel) maxLabel.textContent = fmtAmount(max);
  }

  function syncPriceHidden() {
    if (!minHidden || !maxHidden) return;
    var on = !!(priceOn && priceOn.checked);
    if (priceWrap) priceWrap.classList.toggle('is-off', !on);
    minHidden.disabled = !on;
    maxHidden.disabled = !on;
    if (on && minRange && maxRange) {
      minHidden.value = String(clampPrice(minRange.value));
      maxHidden.value = String(clampPrice(maxRange.value));
    }
  }

  function onMinInput() {
    if (!minRange || !maxRange) return;
    var min = clampPrice(minRange.value);
    var max = clampPrice(maxRange.value);
    if (min > max) {
      minRange.value = String(max);
    }
    minRange.style.zIndex = '2';
    maxRange.style.zIndex = '1';
    syncPriceFill();
    syncPriceHidden();
  }

  function onMaxInput() {
    if (!minRange || !maxRange) return;
    var min = clampPrice(minRange.value);
    var max = clampPrice(maxRange.value);
    if (max < min) {
      maxRange.value = String(min);
    }
    maxRange.style.zIndex = '2';
    minRange.style.zIndex = '1';
    syncPriceFill();
    syncPriceHidden();
  }

  function splitDt(value) {
    var raw = String(value || '').trim();
    var m = raw.match(/^(\d{4}\/\d{1,2}\/\d{1,2})(?:\s+(\d{1,2}:\d{2}))?/);
    if (!m) return { date: '', time: '' };
    var time = m[2] || '';
    if (time) {
      var tm = time.split(':');
      time = ('0' + tm[0]).slice(-2) + ':' + ('0' + tm[1]).slice(-2);
    }
    return { date: m[1], time: time };
  }

  function combineDt(dateVal, timeVal) {
    dateVal = String(dateVal || '').trim();
    if (!dateVal) return '';
    timeVal = String(timeVal || '').trim();
    if (!timeVal) timeVal = '00:00';
    if (timeVal.length === 5) return dateVal + ' ' + timeVal;
    return dateVal + ' ' + timeVal.slice(0, 5);
  }

  function syncHiddenDates() {
    if (fromHidden && fromDate && fromTime) {
      fromHidden.value = combineDt(fromDate.value, fromTime.value);
    }
    if (toHidden && toDate && toTime) {
      toHidden.value = combineDt(toDate.value, toTime.value);
    }
  }

  function setDateTimePair(dateInput, timeInput, full, fallbackTime) {
    var parts = splitDt(full);
    if (dateInput) dateInput.value = parts.date;
    if (timeInput) timeInput.value = parts.time || fallbackTime;
  }

  function initJalaliDatePicker(input, onDaySelect) {
    if (!window.jQuery || !input || input.dataset.pdp === '1') return;
    var $el = window.jQuery(input);
    var skip = true;
    $el.persianDatepicker({
      calendarType: 'persian',
      format: 'YYYY/MM/DD',
      initialValue: $el.val() !== '',
      initialValueType: 'persian',
      autoClose: true,
      responsive: true,
      observer: true,
      navigator: { scroll: { enabled: false } },
      toolbox: {
        calendarSwitch: { enabled: false },
        todayButton: { enabled: true },
        submitButton: { enabled: false }
      },
      timePicker: { enabled: false },
      onSelect: function () {
        if (skip || ignoreDayTimeReset) return;
        if (typeof onDaySelect === 'function') onDaySelect();
        syncHiddenDates();
      }
    });
    input.dataset.pdp = '1';
    setTimeout(function () { skip = false; }, 0);
  }

  if (kindSelect) {
    kindSelect.addEventListener('change', syncKindGroups);
    syncKindGroups();
  }

  if (minRange && maxRange) {
    minRange.addEventListener('input', onMinInput);
    maxRange.addEventListener('input', onMaxInput);
    syncPriceFill();
  }
  if (priceOn) {
    priceOn.addEventListener('change', syncPriceHidden);
  }
  syncPriceHidden();

  if (fromTime) fromTime.addEventListener('change', syncHiddenDates);
  if (fromTime) fromTime.addEventListener('input', syncHiddenDates);
  if (toTime) toTime.addEventListener('change', syncHiddenDates);
  if (toTime) toTime.addEventListener('input', syncHiddenDates);

  modal.querySelectorAll('.pay-date-preset').forEach(function (btn) {
    btn.addEventListener('click', function () {
      ignoreDayTimeReset = true;
      setDateTimePair(fromDate, fromTime, btn.getAttribute('data-from') || '', '00:00');
      setDateTimePair(toDate, toTime, btn.getAttribute('data-to') || '', '23:59');
      syncHiddenDates();
      setTimeout(function () { ignoreDayTimeReset = false; }, 80);
    });
  });

  if (form) {
    form.addEventListener('submit', function () {
      syncHiddenDates();
      syncPriceHidden();
    });
  }

  function bootPickers() {
    initJalaliDatePicker(fromDate, function () {
      if (fromTime) fromTime.value = '00:00';
    });
    initJalaliDatePicker(toDate, function () {
      if (toTime) toTime.value = '23:59';
    });
    syncHiddenDates();
  }

  if (window.jQuery) {
    window.jQuery(bootPickers);
  } else {
    bootPickers();
  }
})();
