function toggleTemplateField(selectEl) {
    if (!selectEl) return;
    var targetId = selectEl.getAttribute('data-template-target');
    var wrap = targetId ? document.getElementById(targetId) : null;
    if (!wrap) return;
    var option = selectEl.options[selectEl.selectedIndex];
    var show = option && option.getAttribute('data-pasarguard') === '1';
    wrap.style.display = show ? '' : 'none';
}

document.querySelectorAll('.panel-select').forEach(function (selectEl) {
    selectEl.addEventListener('change', function () {
        toggleTemplateField(selectEl);
    });
    toggleTemplateField(selectEl);
});

window.openEditModal = function (p) {
    document.getElementById('edit_id').value = p.id || '';
    document.getElementById('edit_name').value = p.name_product || '';
    document.getElementById('edit_price').value = p.price_product || '';
    document.getElementById('edit_volume').value = p.Volume_constraint || '';
    document.getElementById('edit_time').value = p.Service_time || '';
    document.getElementById('edit_agent').value = p.agent || '';
    document.getElementById('edit_note').value = p.note || '';
    document.getElementById('edit_template_id').value = p.template_id || '';

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
        toggleTemplateField(sel);
    }

    openModal('editModal');
};
