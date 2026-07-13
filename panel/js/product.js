function isPasarguardPanel(panelName) {
    return !!(window.PASARGUARD_PANELS && window.PASARGUARD_PANELS[panelName]);
}

function toggleHwidField(panelSelect, fieldId) {
    var field = document.getElementById(fieldId);
    if (!field || !panelSelect) {
        return;
    }
    field.style.display = isPasarguardPanel(panelSelect.value) ? '' : 'none';
}

function bindPanelHwidToggle(panelSelectId, fieldId) {
    var panelSelect = document.getElementById(panelSelectId);
    if (!panelSelect) {
        return;
    }
    panelSelect.addEventListener('change', function () {
        toggleHwidField(panelSelect, fieldId);
    });
    toggleHwidField(panelSelect, fieldId);
}

function updateProductSortIndexes(tbody) {
    if (!tbody) {
        return;
    }
    tbody.querySelectorAll('.product-sort-row').forEach(function (row, index) {
        var cell = row.querySelector('.product-sort-index');
        if (cell) {
            cell.textContent = String(index + 1);
        }
    });
}

function saveProductOrder(tbody) {
    if (!tbody || tbody.dataset.saving === '1') {
        return;
    }
    var category = tbody.dataset.category || '';
    var order = Array.prototype.map.call(
        tbody.querySelectorAll('.product-sort-row'),
        function (row) { return row.dataset.id; }
    );
    var body = new URLSearchParams();
    body.set('action', 'reorder');
    body.set('_csrf', window.PRODUCT_CSRF || '');
    body.set('category', category);
    order.forEach(function (id) {
        body.append('order[]', id);
    });

    tbody.dataset.saving = '1';
    fetch('product.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: body.toString()
    })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (!data.ok) {
                throw new Error(data.error || 'ذخیره ترتیب ناموفق بود.');
            }
            updateProductSortIndexes(tbody);
            if (window.toast) {
                window.toast('ترتیب محصولات ذخیره شد.', 'ok', 2200);
            }
        })
        .catch(function (err) {
            if (window.toast) {
                window.toast(err.message || 'خطا در ذخیره ترتیب.', 'no');
            }
            location.reload();
        })
        .finally(function () {
            tbody.dataset.saving = '0';
        });
}

function initProductSortables() {
    if (typeof Sortable === 'undefined') {
        return;
    }
    document.querySelectorAll('.product-sortable').forEach(function (tbody) {
        if (tbody.dataset.sortableReady === '1') {
            return;
        }
        tbody.dataset.sortableReady = '1';
        Sortable.create(tbody, {
            handle: '.product-sort-handle',
            animation: 160,
            ghostClass: 'product-sort-ghost',
            dragClass: 'product-sort-drag',
            onEnd: function () {
                updateProductSortIndexes(tbody);
                saveProductOrder(tbody);
            }
        });
    });
}

function initProductSearch() {
    var input = document.querySelector('[data-filter="prodOrder"]');
    var list = document.getElementById('prodOrder');
    if (!input || !list) {
        return;
    }
    input.addEventListener('input', function () {
        var q = input.value.trim().toLowerCase();
        list.querySelectorAll('.product-order-group').forEach(function (group) {
            var visibleRows = 0;
            group.querySelectorAll('.product-sort-row').forEach(function (row) {
                var show = !q || row.textContent.toLowerCase().includes(q);
                row.style.display = show ? '' : 'none';
                if (show) {
                    visibleRows += 1;
                }
            });
            group.style.display = visibleRows ? '' : 'none';
        });
    });
}

document.addEventListener('DOMContentLoaded', function () {
    bindPanelHwidToggle('edit_panel', 'edit_hwid_field');
    var addPanelSelect = document.querySelector('#addModal select[name="namepanel"]');
    if (addPanelSelect) {
        addPanelSelect.addEventListener('change', function () {
            toggleHwidField(addPanelSelect, 'add_hwid_field');
        });
        toggleHwidField(addPanelSelect, 'add_hwid_field');
    }
    initProductSortables();
    initProductSearch();
});

window.openEditModal = function (p) {
    document.getElementById('edit_id').value = p.id || '';
    document.getElementById('edit_name').value = p.name_product || '';
    document.getElementById('edit_price').value = p.price_product || '';
    document.getElementById('edit_volume').value = p.Volume_constraint || '';
    document.getElementById('edit_time').value = p.Service_time || '';
    document.getElementById('edit_agent').value = p.agent || '';
    document.getElementById('edit_note').value = p.note || '';
    document.getElementById('edit_hwid_limit').value = (p.hwid_limit === null || p.hwid_limit === '' || p.hwid_limit === undefined) ? '' : p.hwid_limit;

    var catSel = document.getElementById('edit_cat');
    if (catSel) {
        var catVal = p.category || '';
        for (var j = 0; j < catSel.options.length; j++) {
            catSel.options[j].selected = catSel.options[j].value === catVal;
        }
    }

    var sel = document.getElementById('edit_panel');
    if (sel) {
        for (var i = 0; i < sel.options.length; i++) {
            sel.options[i].selected = sel.options[i].value === (p.Location || '');
        }
        toggleHwidField(sel, 'edit_hwid_field');
    }

    openModal('editModal');
};
