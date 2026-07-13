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
}());
