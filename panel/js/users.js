(function () {
    var list = document.getElementById('usersList');
    if (!list) return;

    var filteredCount = parseInt(list.dataset.filteredCount || '0', 10) || 0;
    var pageChecks = Array.prototype.slice.call(document.querySelectorAll('.user-check'));
    var selectPage = document.getElementById('selectPageUsers');
    var selectFilteredBtn = document.getElementById('selectFilteredBtn');
    var clearBtn = document.getElementById('clearSelectedBtn');
    var openBtn = document.getElementById('openCampaignBtn');
    var label = document.getElementById('campaignSelectedLabel');
    var form = document.getElementById('usersCampaignForm');
    var scopeInput = document.getElementById('campaignScope');
    var idsWrap = document.getElementById('campaignUserIds');
    var hint = document.getElementById('campaignCountHint');
    var filterKey = location.search.replace(/([?&])page=\d+&?/, '$1').replace(/[?&]$/, '');
    var storageKey = 'users-campaign:' + filterKey;
    var selected = new Set();
    var allFiltered = false;

    try {
        var saved = JSON.parse(sessionStorage.getItem(storageKey) || '{}');
        if (saved && saved.filterKey === filterKey) {
            allFiltered = !!saved.allFiltered;
            (saved.ids || []).forEach(function (id) { selected.add(String(id)); });
        }
    } catch (e) {}

    function persist() {
        try {
            sessionStorage.setItem(storageKey, JSON.stringify({
                filterKey: filterKey,
                allFiltered: allFiltered,
                ids: Array.from(selected),
            }));
        } catch (e) {}
    }

    function selectedCount() {
        return allFiltered ? filteredCount : selected.size;
    }

    function syncChecks() {
        pageChecks.forEach(function (box) {
            box.checked = allFiltered || selected.has(box.value);
        });
        if (selectPage) {
            var pageSelected = pageChecks.length > 0 && pageChecks.every(function (box) { return box.checked; });
            selectPage.checked = pageSelected;
            selectPage.indeterminate = !pageSelected && pageChecks.some(function (box) { return box.checked; });
        }
        if (label) {
            label.textContent = allFiltered
                ? (filteredCount.toLocaleString('en-US') + ' کاربر فیلترشده')
                : (selected.size.toLocaleString('en-US') + ' کاربر انتخاب شده');
        }
        if (openBtn) {
            openBtn.disabled = openBtn.hasAttribute('data-busy') || selectedCount() < 1;
        }
    }

    pageChecks.forEach(function (box) {
        box.addEventListener('change', function () {
            allFiltered = false;
            if (box.checked) selected.add(box.value);
            else selected.delete(box.value);
            persist();
            syncChecks();
        });
    });

    if (selectPage) {
        selectPage.addEventListener('change', function () {
            allFiltered = false;
            pageChecks.forEach(function (box) {
                box.checked = selectPage.checked;
                if (selectPage.checked) selected.add(box.value);
                else selected.delete(box.value);
            });
            persist();
            syncChecks();
        });
    }

    if (selectFilteredBtn) {
        selectFilteredBtn.addEventListener('click', function () {
            allFiltered = true;
            pageChecks.forEach(function (box) { selected.add(box.value); });
            persist();
            syncChecks();
        });
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            allFiltered = false;
            selected.clear();
            persist();
            syncChecks();
        });
    }

    if (openBtn) {
        openBtn.addEventListener('click', function () {
            if (openBtn.hasAttribute('data-busy') || selectedCount() < 1) return;
            if (scopeInput) scopeInput.value = allFiltered ? 'filtered' : 'selected';
            if (idsWrap) {
                idsWrap.innerHTML = '';
                if (!allFiltered) {
                    selected.forEach(function (id) {
                        var input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'user_ids[]';
                        input.value = id;
                        idsWrap.appendChild(input);
                    });
                }
            }
            if (hint) {
                hint.textContent = allFiltered
                    ? ('پیام برای همه ' + filteredCount.toLocaleString('en-US') + ' کاربر مطابق فیلتر فعلی ارسال می‌شود.')
                    : ('پیام برای ' + selected.size.toLocaleString('en-US') + ' کاربر انتخاب‌شده ارسال می‌شود.');
            }
            if (typeof openModal === 'function') openModal('usersCampaignModal');
        });
    }

    if (form) {
        form.addEventListener('submit', function (event) {
            var count = selectedCount();
            if (count < 1) {
                event.preventDefault();
                return;
            }
            if (!window.confirm('ارسال پیام به ' + count.toLocaleString('en-US') + ' کاربر آغاز شود؟')) {
                event.preventDefault();
            }
        });
    }

    syncChecks();
}());
