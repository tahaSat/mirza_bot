<?php $supportUnansweredCount = $supportUnansweredCount ?? 0; ?>
  </main>
</div>

<nav class="bottom-nav">
  <div class="bottom-nav-row">
    <?php if (!empty($panelIsN2)): ?>
    <a href="index.php" class="bnav-item <?= ($activeNav??'')==='dashboard'?'active':''?>"><?= icon('dashboard',22) ?><span>داشبورد</span></a>
    <a href="n2_categories.php" class="bnav-item <?= ($activeNav??'')==='n2_categories'?'active':''?>"><?= icon('package',22) ?><span>دسته‌ها</span></a>
    <a href="n2_products.php" class="bnav-item <?= ($activeNav??'')==='n2_products'?'active':''?>"><?= icon('package',22) ?><span>محصولات</span></a>
    <a href="n2_purchases.php" class="bnav-item <?= ($activeNav??'')==='n2_purchases'?'active':''?>"><?= icon('invoice',22) ?><span>خریدها</span></a>
    <a href="n2_messages.php" class="bnav-item <?= ($activeNav??'')==='n2_messages'?'active':''?>"><?= icon('message',22) ?><span>پیام‌ها</span></a>
    <?php else: ?>
    <a href="index.php"   class="bnav-item <?= ($activeNav??'')==='dashboard'?'active':''?>"><?= icon('dashboard',22) ?><span>داشبورد</span></a>
    <a href="users.php"   class="bnav-item <?= ($activeNav??'')==='users'?'active':''?>"><?= icon('users',22) ?><span>کاربران</span></a>
    <a href="invoice.php" class="bnav-item <?= ($activeNav??'')==='invoice'?'active':''?>"><?= icon('invoice',22) ?><span>سفارش</span></a>
    <a href="payment.php" class="bnav-item <?= ($activeNav??'')==='payment'?'active':''?>"><?= icon('card',22) ?><span>مالی</span></a>
    <a href="support.php" class="bnav-item <?= ($activeNav??'')==='support'?'active':''?>"><?= icon('message',22) ?><?php if (($supportUnansweredCount ?? 0) > 0): ?><b class="bnav-count"><?= number_format($supportUnansweredCount) ?></b><?php endif; ?><span>پشتیبانی</span></a>
    <?php endif; ?>
  </div>
</nav>
</div>

<script src="<?= htmlspecialchars(panel_asset('js/app.js')) ?>"></script>
<script>
window.openModal = function (id) {
  var m = document.getElementById(id);
  if (!m) return;
  if (m.parentNode !== document.body) document.body.appendChild(m);
  m.offsetWidth;
  m.classList.add('open');
};
window.closeModal = function (id) {
  var m = document.getElementById(id);
  if (m) m.classList.remove('open');
};
</script>
</body>
</html>
