(function () {
    var panelSelect = document.getElementById('servicePanel');
    var productSelect = document.getElementById('serviceProduct');
    var products = window.__serviceProducts || [];

    function fillProducts(panel) {
        if (!productSelect) return;
        productSelect.innerHTML = '';
        if (!panel) {
            productSelect.disabled = true;
            productSelect.innerHTML = '<option value="">ابتدا پنل را انتخاب کنید</option>';
            return;
        }
        var matches = products.filter(function (p) {
            return p.Location === panel || p.Location === '/all';
        });
        if (!matches.length) {
            productSelect.disabled = true;
            productSelect.innerHTML = '<option value="">محصولی برای این پنل یافت نشد</option>';
            return;
        }
        productSelect.disabled = false;
        productSelect.innerHTML = '<option value="">انتخاب محصول...</option>';
        matches.forEach(function (p) {
            var opt = document.createElement('option');
            opt.value = p.name_product;
            opt.textContent = p.name_product;
            productSelect.appendChild(opt);
        });
    }

    if (panelSelect) {
        panelSelect.addEventListener('change', function () {
            fillProducts(panelSelect.value);
        });
    }

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
