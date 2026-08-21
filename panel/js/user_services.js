(function () {
    var panelSelect = document.getElementById('servicePanel');
    var productSelect = document.getElementById('serviceProduct');
    var usernameField = document.getElementById('serviceUsernameField');
    var usernameInput = document.getElementById('serviceUsername');
    var usernameHint = document.getElementById('usernameAutoHint');
    var customFields = document.getElementById('customServiceFields');
    var customGb = document.getElementById('customGb');
    var customGbHint = document.getElementById('customGbHint');
    var customMonths = document.getElementById('customMonths');
    var products = window.__serviceProducts || [];
    var panelsMeta = window.__servicePanels || {};
    var customToken = window.__customServiceToken || '__customvolume__';
    var usageCfg = window.__serviceUsage || {};

    function currentPanelMeta() {
        var name = panelSelect ? panelSelect.value : '';
        return name && panelsMeta[name] ? panelsMeta[name] : null;
    }

    function setUsernameMode(asks) {
        if (usernameField) {
            usernameField.hidden = !asks;
        }
        if (usernameHint) {
            usernameHint.hidden = !!asks;
        }
        if (usernameInput) {
            usernameInput.required = !!asks;
            if (!asks) {
                usernameInput.value = '';
            }
        }
    }

    function setCustomMode(on, meta) {
        if (customFields) {
            customFields.hidden = !on;
        }
        if (customGb) {
            customGb.required = !!on;
            if (on && meta) {
                customGb.min = String(meta.minVolume || 1);
                customGb.max = String(meta.maxVolume || 1000);
            }
            if (!on) {
                customGb.value = '';
            }
        }
        if (customGbHint && meta) {
            customGbHint.textContent = on
                ? ('حداقل ' + (meta.minVolume || 1) + ' و حداکثر ' + (meta.maxVolume || 1000) + ' گیگابایت')
                : '';
        }
        if (customMonths) {
            customMonths.required = !!on;
            customMonths.innerHTML = '<option value="">انتخاب مدت...</option>';
            if (on && meta && Array.isArray(meta.months)) {
                meta.months.forEach(function (row) {
                    var opt = document.createElement('option');
                    opt.value = String(row.months);
                    opt.textContent = row.label || (row.months + ' ماهه');
                    customMonths.appendChild(opt);
                });
            }
        }
    }

    function fillProducts(panel) {
        if (!productSelect) return;
        productSelect.innerHTML = '';
        var meta = panel && panelsMeta[panel] ? panelsMeta[panel] : null;
        setUsernameMode(!!(meta && meta.asksUsername));
        if (usernameHint && meta && !meta.asksUsername) {
            usernameHint.textContent = meta.method
                ? ('نام کاربری طبق روش پنل ساخته می‌شود: ' + meta.method)
                : 'نام کاربری طبق روش نام‌گذاری پنل به‌صورت خودکار ساخته می‌شود.';
        }
        if (!panel) {
            productSelect.disabled = true;
            productSelect.innerHTML = '<option value="">ابتدا پنل را انتخاب کنید</option>';
            setCustomMode(false, meta);
            return;
        }
        var matches = products.filter(function (p) {
            return p.Location === panel || p.Location === '/all';
        });
        productSelect.innerHTML = '<option value="">انتخاب محصول...</option>';
        if (meta && meta.customEnabled) {
            var customOpt = document.createElement('option');
            customOpt.value = customToken;
            customOpt.textContent = meta.customLabel || 'سرویس دلخواه';
            productSelect.appendChild(customOpt);
        }
        matches.forEach(function (p) {
            var opt = document.createElement('option');
            opt.value = p.name_product;
            opt.textContent = p.name_product;
            productSelect.appendChild(opt);
        });
        if (!meta || (!meta.customEnabled && !matches.length)) {
            productSelect.disabled = true;
            productSelect.innerHTML = '<option value="">محصولی برای این پنل یافت نشد</option>';
            setCustomMode(false, meta);
            return;
        }
        productSelect.disabled = false;
        setCustomMode(false, meta);
    }

    if (panelSelect) {
        panelSelect.addEventListener('change', function () {
            fillProducts(panelSelect.value);
        });
    }
    if (productSelect) {
        productSelect.addEventListener('change', function () {
            var meta = currentPanelMeta();
            setCustomMode(productSelect.value === customToken, meta);
        });
    }

    var addForm = document.getElementById('addServiceForm');
    if (addForm) {
        addForm.addEventListener('submit', function (e) {
            var meta = currentPanelMeta();
            if (productSelect && productSelect.value === customToken) {
                var gb = customGb ? parseInt(customGb.value, 10) : 0;
                var months = customMonths ? parseInt(customMonths.value, 10) : 0;
                var minV = meta ? (meta.minVolume || 1) : 1;
                var maxV = meta ? (meta.maxVolume || 1000) : 1000;
                if (!gb || gb < minV || gb > maxV || !months) {
                    e.preventDefault();
                    if (typeof toast === 'function') {
                        toast('حجم و مدت سرویس دلخواه را کامل وارد کنید.', 'warn');
                    } else {
                        alert('حجم و مدت سرویس دلخواه را کامل وارد کنید.');
                    }
                }
            }
        });
    }

    var extendProduct = document.getElementById('extendProduct');
    var extendCustomFields = document.getElementById('extendCustomFields');
    var extendCustomGb = document.getElementById('extendCustomGb');
    var extendCustomGbHint = document.getElementById('extendCustomGbHint');
    var extendCustomMonths = document.getElementById('extendCustomMonths');
    var extendProductHint = document.getElementById('extendProductHint');
    var extendPanelName = '';

    function formatPrice(n) {
        n = parseInt(n, 10) || 0;
        try {
            return n.toLocaleString('fa-IR');
        } catch (err) {
            return String(n);
        }
    }

    function setExtendCustomMode(on, meta) {
        if (extendCustomFields) {
            extendCustomFields.hidden = !on;
        }
        if (extendCustomGb) {
            extendCustomGb.required = !!on;
            if (on && meta) {
                extendCustomGb.min = String(meta.minVolume || 1);
                extendCustomGb.max = String(meta.maxVolume || 1000);
            }
            if (!on) {
                extendCustomGb.value = '';
            }
        }
        if (extendCustomGbHint) {
            extendCustomGbHint.textContent = on && meta
                ? ('حداقل ' + (meta.minVolume || 1) + ' و حداکثر ' + (meta.maxVolume || 1000) + ' گیگابایت')
                : '';
        }
        if (extendCustomMonths) {
            extendCustomMonths.required = !!on;
            extendCustomMonths.innerHTML = '<option value="">انتخاب مدت...</option>';
            if (on && meta && Array.isArray(meta.months)) {
                meta.months.forEach(function (row) {
                    var opt = document.createElement('option');
                    opt.value = String(row.months);
                    opt.textContent = row.label || (row.months + ' ماهه');
                    extendCustomMonths.appendChild(opt);
                });
            }
        }
    }

    function updateExtendProductHint() {
        if (!extendProductHint) return;
        if (!extendProduct || !extendProduct.value) {
            extendProductHint.textContent = '';
            return;
        }
        if (extendProduct.value === customToken) {
            extendProductHint.textContent = 'حجم و مدت را وارد کنید. قیمت بر اساس تنظیمات پنل محاسبه می‌شود.';
            return;
        }
        var match = products.filter(function (p) {
            return p.name_product === extendProduct.value
                && (p.Location === extendPanelName || p.Location === '/all');
        })[0];
        if (!match) {
            extendProductHint.textContent = '';
            return;
        }
        var parts = [];
        if (match.Volume_constraint) {
            parts.push(match.Volume_constraint + ' گیگ');
        }
        if (match.Service_time) {
            parts.push(match.Service_time + ' روز');
        }
        if (match.price_product) {
            parts.push(formatPrice(match.price_product) + ' تومان');
        }
        extendProductHint.textContent = parts.length ? parts.join(' · ') : '';
    }

    function fillExtendProducts(panel) {
        if (!extendProduct) return;
        var meta = panel && panelsMeta[panel] ? panelsMeta[panel] : null;
        extendProduct.innerHTML = '<option value="">انتخاب محصول...</option>';
        if (!panel) {
            extendProduct.disabled = true;
            setExtendCustomMode(false, meta);
            updateExtendProductHint();
            return;
        }
        var matches = products.filter(function (p) {
            return p.Location === panel || p.Location === '/all';
        });
        if (meta && meta.customEnabled) {
            var customOpt = document.createElement('option');
            customOpt.value = customToken;
            customOpt.textContent = meta.customLabel || 'سرویس دلخواه';
            extendProduct.appendChild(customOpt);
        }
        matches.forEach(function (p) {
            var opt = document.createElement('option');
            opt.value = p.name_product;
            opt.textContent = p.name_product;
            extendProduct.appendChild(opt);
        });
        if ((!meta || !meta.customEnabled) && !matches.length) {
            extendProduct.disabled = true;
            extendProduct.innerHTML = '<option value="">محصولی برای این پنل یافت نشد</option>';
            setExtendCustomMode(false, meta);
            updateExtendProductHint();
            return;
        }
        extendProduct.disabled = false;
        setExtendCustomMode(false, meta);
        updateExtendProductHint();
    }

    if (extendProduct) {
        extendProduct.addEventListener('change', function () {
            var meta = extendPanelName && panelsMeta[extendPanelName] ? panelsMeta[extendPanelName] : null;
            setExtendCustomMode(extendProduct.value === customToken, meta);
            updateExtendProductHint();
        });
    }

    document.querySelectorAll('.btn-extend-service').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var invoiceId = btn.dataset.invoice || '';
            var username = btn.dataset.username || '';
            var panel = btn.dataset.panel || '';
            var idInput = document.getElementById('extendInvoiceId');
            var msgEl = document.getElementById('extendServiceText');
            var payCheck = document.querySelector('#extendServiceForm input[name="record_payment"]');
            extendPanelName = panel;
            if (idInput) idInput.value = invoiceId;
            if (msgEl) {
                msgEl.textContent = 'سرویس «' + username + '» با محصول انتخاب‌شده تمدید می‌شود. حجم و زمان طبق روش تمدید پنل اعمال می‌گردد.';
            }
            if (payCheck) payCheck.checked = true;
            fillExtendProducts(panel);
            if (typeof openModal === 'function') {
                openModal('extendServiceModal');
            }
        });
    });

    var extendForm = document.getElementById('extendServiceForm');
    if (extendForm) {
        extendForm.addEventListener('submit', function (e) {
            var meta = extendPanelName && panelsMeta[extendPanelName] ? panelsMeta[extendPanelName] : null;
            if (extendProduct && extendProduct.value === customToken) {
                var gb = extendCustomGb ? parseInt(extendCustomGb.value, 10) : 0;
                var months = extendCustomMonths ? parseInt(extendCustomMonths.value, 10) : 0;
                var minV = meta ? (meta.minVolume || 1) : 1;
                var maxV = meta ? (meta.maxVolume || 1000) : 1000;
                if (!gb || gb < minV || gb > maxV || !months) {
                    e.preventDefault();
                    if (typeof toast === 'function') {
                        toast('حجم و مدت سرویس دلخواه را کامل وارد کنید.', 'warn');
                    } else {
                        alert('حجم و مدت سرویس دلخواه را کامل وارد کنید.');
                    }
                    return;
                }
            }
            if (typeof showConfirm === 'function') {
                e.preventDefault();
                var payCheck = extendForm.querySelector('input[name="record_payment"]');
                var extra = payCheck && payCheck.checked
                    ? 'پرداخت جدید به‌عنوان «تمدید توسط ادمین» ثبت می‌شود.'
                    : 'پرداخت جدیدی ثبت نمی‌شود.';
                showConfirm(extra + '\n\nادامه می‌دهید؟', function () {
                    extendForm.submit();
                }, 'تأیید تمدید سرویس');
            }
        });
    }

    function setUsageCell(el, text) {
        if (!el) return;
        el.textContent = text || '—';
    }

    function loadRowUsage(row) {
        var invoiceId = row.getAttribute('data-invoice') || '';
        var volEl = row.querySelector('.js-usage-volume');
        var timeEl = row.querySelector('.js-usage-time');
        if (!invoiceId || !usageCfg.userId || !usageCfg.csrf) {
            setUsageCell(volEl, '—');
            setUsageCell(timeEl, '—');
            return;
        }
        var ctrl = typeof AbortController === 'function' ? new AbortController() : null;
        var timer = setTimeout(function () {
            if (ctrl) ctrl.abort();
        }, 8000);
        var url = 'user_service_usage.php?user_id=' + encodeURIComponent(usageCfg.userId)
            + '&id_invoice=' + encodeURIComponent(invoiceId)
            + '&_csrf=' + encodeURIComponent(usageCfg.csrf);
        var opts = { credentials: 'same-origin' };
        if (ctrl) opts.signal = ctrl.signal;
        fetch(url, opts).then(function (res) {
            return res.json().then(function (data) {
                return { okHttp: res.ok, data: data };
            }).catch(function () {
                return { okHttp: false, data: null };
            });
        }).then(function (result) {
            var data = result && result.data ? result.data : {};
            setUsageCell(volEl, data.usage_volume || '—');
            setUsageCell(timeEl, data.usage_time || '—');
        }).catch(function () {
            setUsageCell(volEl, '—');
            setUsageCell(timeEl, '—');
        }).then(function () {
            clearTimeout(timer);
        });
    }

    document.querySelectorAll('tr[data-invoice]').forEach(loadRowUsage);

    document.querySelectorAll('.btn-remove-service').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var invoiceId = btn.dataset.invoice || '';
            var username = btn.dataset.username || '';
            var msgEl = document.getElementById('removeServiceText');
            var idInput = document.getElementById('removeInvoiceId');
            if (idInput) idInput.value = invoiceId;
            if (msgEl) {
                msgEl.textContent = 'سرویس «' + username + '» از پنل VPN حذف و در ربات غیرفعال می‌شود. این عمل قابل بازگشت نیست.';
            }
            if (typeof openModal === 'function') {
                openModal('removeServiceModal');
            }
        });
    });

    document.querySelectorAll('.btn-refund-service').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var invoiceId = btn.dataset.invoice || '';
            var username = btn.dataset.username || '';
            var price = parseInt(btn.dataset.price || '0', 10) || 0;
            var msgEl = document.getElementById('refundServiceText');
            var idInput = document.getElementById('refundInvoiceId');
            var disableCheck = document.getElementById('refundDisableProduct');
            var walletCheck = document.getElementById('refundCreditWallet');
            var walletLabel = document.getElementById('refundCreditWalletLabel');
            if (idInput) idInput.value = invoiceId;
            if (disableCheck) disableCheck.checked = true;
            if (walletCheck) walletCheck.checked = false;
            if (walletLabel) {
                walletLabel.textContent = price > 0
                    ? 'مبلغ سرویس (' + price.toLocaleString('fa-IR') + ' تومان) به کیف پول کاربر بازگردانده شود؟'
                    : 'مبلغ سرویس به کیف پول کاربر بازگردانده شود؟';
            }
            if (msgEl) {
                msgEl.textContent = 'سرویس «' + username + '» مرجوعی می‌شود. در صورت تمایل، مبلغ به کیف پول کاربر برمی‌گردد.';
            }
            if (typeof openModal === 'function') {
                openModal('refundServiceModal');
            }
        });
    });

    var removeForm = document.getElementById('removeServiceForm');
    if (removeForm) {
        removeForm.addEventListener('submit', function (e) {
            var username = document.getElementById('removeServiceText');
            var label = username ? username.textContent : 'این سرویس';
            if (typeof showConfirm === 'function') {
                e.preventDefault();
                showConfirm(label + '\n\nادامه می‌دهید؟', function () {
                    removeForm.submit();
                }, 'تأیید حذف سرویس');
            }
        });
    }

    var refundForm = document.getElementById('refundServiceForm');
    if (refundForm) {
        refundForm.addEventListener('submit', function (e) {
            var walletCheck = document.getElementById('refundCreditWallet');
            var disableCheck = document.getElementById('refundDisableProduct');
            if (!((walletCheck && walletCheck.checked) || (disableCheck && disableCheck.checked))) {
                e.preventDefault();
                if (typeof toast === 'function') {
                    toast('یکی از گزینه‌های بازگشت مبلغ به کیف پول یا غیرفعال‌سازی سرویس را انتخاب کنید.', 'warn');
                } else {
                    alert('یکی از گزینه‌های بازگشت مبلغ به کیف پول یا غیرفعال‌سازی سرویس را انتخاب کنید.');
                }
                return;
            }
            if (typeof showConfirm === 'function') {
                e.preventDefault();
                var parts = [];
                if (walletCheck && walletCheck.checked) {
                    parts.push('مبلغ به کیف پول کاربر بازگردانده می‌شود.');
                }
                if (disableCheck && disableCheck.checked) {
                    parts.push('سرویس در پنل و ربات غیرفعال می‌شود.');
                }
                showConfirm(parts.join('\n') + '\n\nادامه می‌دهید؟', function () {
                    refundForm.submit();
                }, 'تأیید مرجوعی سرویس');
            }
        });
    }
}());
