window.switchTab = function (name) {
    var panes = { services: 'paneServices', orders: 'paneOrders', pay: 'panePay', refs: 'paneRefs' };
    var tabs = { services: 'tabServices', orders: 'tabOrders', pay: 'tabPay', refs: 'tabRefs' };

    Object.values(panes).forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.style.display = 'none';
    });

    Object.values(tabs).forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.classList.remove('active');
    });

    var pane = document.getElementById(panes[name]);
    if (pane) pane.style.display = 'block';

    var tab = document.getElementById(tabs[name]);
    if (tab) tab.classList.add('active');
};

document.addEventListener('DOMContentLoaded', function () {
    var hash = (location.hash || '').replace('#', '');
    if (hash && ['services', 'orders', 'pay', 'refs'].indexOf(hash) !== -1) {
        switchTab(hash);
    }

    // Collapse admin actions by default on small screens
    var details = document.querySelector('.u-actions-details');
    if (details && window.matchMedia('(max-width: 768px)').matches) {
        details.open = false;
    }
});
