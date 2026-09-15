<?php
/** @var array $user */
$currentPath = $_SERVER['SCRIPT_NAME'] ?? '';
function nav_active(string $needle, string $currentPath): string
{
    return str_contains($currentPath, $needle) ? ' active' : '';
}

/** @return array{0:string,1:string,2:string} [nav_active needle, href, icon name] */
const SIDEBAR_ITEMS = [
    'Dashboard' => ['/dashboard.php', '/dashboard.php', 'home'],
    'Kasir (POS)' => ['/pos/', '/pos/index.php', 'cart'],
    'Riwayat Penjualan' => ['/sales/', '/sales/index.php', 'invoice'],
    'Cash Session' => ['/cash/', '/cash/index.php', 'money'],
    'Produk' => ['/products/', '/products/index.php', 'box'],
];
const SIDEBAR_OWNER_ITEMS = [
    'Inventaris' => ['/inventory/', '/inventory/index.php', 'layers'],
    'Supplier' => ['/suppliers/', '/suppliers/index.php', 'truck'],
    'Pembelian' => ['/purchases/', '/purchases/index.php', 'tag'],
    'Laporan' => ['/reports/', '/reports/index.php', 'pie'],
    'Pengguna' => ['/users/', '/users/index.php', 'users'],
    'Pengaturan' => ['/settings/', '/settings/index.php', 'gear'],
    'Log Audit' => ['/audit/', '/audit/index.php', 'warning'],
    'Backup & Restore' => ['/backup/', '/backup/index.php', 'shield-check'],
];
?>
<nav class="sidebar">
  <div class="sidebar-brand">
    <span class="sidebar-brand-text"><?= e(APP_NAME) ?></span>
  </div>
  <ul class="sidebar-nav">
    <?php foreach (SIDEBAR_ITEMS as $label => [$needle, $href, $icon]): ?>
      <li><a class="<?= nav_active($needle, $currentPath) ?>" href="<?= APP_BASE_PATH . $href ?>" title="<?= e($label) ?>">
        <?= partambus_icon($icon, 18) ?><span class="sidebar-nav-label"><?= e($label) ?></span>
      </a></li>
    <?php endforeach; ?>
    <?php if (is_owner()): ?>
      <?php foreach (SIDEBAR_OWNER_ITEMS as $label => [$needle, $href, $icon]): ?>
        <li><a class="<?= nav_active($needle, $currentPath) ?>" href="<?= APP_BASE_PATH . $href ?>" title="<?= e($label) ?>">
          <?= partambus_icon($icon, 18) ?><span class="sidebar-nav-label"><?= e($label) ?></span>
        </a></li>
      <?php endforeach; ?>
    <?php endif; ?>
  </ul>
  <div class="sidebar-footer">
    <div class="sidebar-user-info">
      <div class="sidebar-user-avatar"><?= e(mb_strtoupper(mb_substr($user['full_name'], 0, 1))) ?></div>
      <div>
        <div class="sidebar-user-name"><?= e($user['full_name']) ?></div>
        <div class="sidebar-user-role"><?= e($user['role']) ?></div>
      </div>
    </div>
    <a href="<?= APP_BASE_PATH ?>/auth/logout.php" class="sidebar-logout-btn" title="Keluar">
      <span class="sidebar-logout-btn-label">Keluar</span>
    </a>
    <button type="button" class="sidebar-collapse-btn" id="sidebar-collapse-toggle" title="Ciutkan/lebarkan menu">
      <?= partambus_icon('chevrons-left', 16) ?>
    </button>
  </div>
</nav>
