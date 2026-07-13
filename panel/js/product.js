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

document.addEventListener('DOMContentLoaded', function () {
    bindPanelHwidToggle('edit_panel', 'edit_hwid_field');
    var addPanelSelect = document.querySelector('#addModal select[name="namepanel"]');
    if (addPanelSelect) {
        addPanelSelect.addEventListener('change', function () {
            toggleHwidField(addPanelSelect, 'add_hwid_field');
        });
        toggleHwidField(addPanelSelect, 'add_hwid_field');
    }
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
