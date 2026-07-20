<?php $supportUnansweredCount = $supportUnansweredCount ?? 0; ?>
  </main>
</div>

<nav class="bottom-nav">
  <div class="bottom-nav-row">
    <a href="index.php"   class="bnav-item <?= ($activeNav??'')==='dashboard'?'active':''?>"><?= icon('dashboard',22) ?><span>داشبورد</span></a>
    <a href="users.php"   class="bnav-item <?= ($activeNav??'')==='users'?'active':''?>"><?= icon('users',22) ?><span>کاربران</span></a>
    <a href="invoice.php" class="bnav-item <?= ($activeNav??'')==='invoice'?'active':''?>"><?= icon('invoice',22) ?><span>سفارش</span></a>
    <a href="payment.php" class="bnav-item <?= ($activeNav??'')==='payment'?'active':''?>"><?= icon('card',22) ?><span>تراکنش</span></a>
    <a href="support.php" class="bnav-item <?= ($activeNav??'')==='support'?'active':''?>"><?= icon('message',22) ?><?php if (($supportUnansweredCount ?? 0) > 0): ?><b class="bnav-count"><?= number_format($supportUnansweredCount) ?></b><?php endif; ?><span>پشتیبانی</span></a>
  </div>
</nav>
</div>

<script src="<?= htmlspecialchars(panel_asset('js/app.js')) ?>"></script>
</body>
</html>
