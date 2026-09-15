<?php
/** @var string $pageTitle */
$pageTitle ??= APP_NAME;
$user = current_user();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> — <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= APP_BASE_PATH ?>/assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
</head>
<body class="<?= e($bodyClass ?? '') ?>">
<?php if ($user): ?>
<script>
// Applied before the sidebar itself is parsed, so a collapsed preference
// takes effect on first paint instead of flashing full-width then snapping
// narrow once app.js runs. Theme preference lives per-browser in
// localStorage (not app_settings) since it's a personal display choice,
// not a shared business setting — same reasoning as the sidebar state.
(function () {
  try {
    if (localStorage.getItem('partambus_sidebar_collapsed') === '1') {
      document.body.classList.add('sidebar-collapsed-pref');
    }
    if (localStorage.getItem('partambus_theme') === 'dark') {
      document.documentElement.setAttribute('data-theme', 'dark');
    }
  } catch (e) {}
})();
</script>
<?php endif; ?>
<div class="app">
<?php if ($user): ?>
<?php require __DIR__ . '/sidebar.php'; ?>
<?php endif; ?>
<main class="content">
<?php if ($user): ?>
<?php if (!empty($backUrl)): ?>
  <a href="<?= e($backUrl) ?>" class="back-link">&larr; <?= e($backLabel ?? 'Kembali') ?></a>
<?php endif; ?>
<div class="topbar">
  <div>
    <h1><?= e($pageTitle) ?></h1>
    <?php if (!empty($topbarSubtitle)): ?><p class="topbar-subtitle"><?= e($topbarSubtitle) ?></p><?php endif; ?>
  </div>
  <?php if (!empty($topbarExtra)): ?>
    <div class="topbar-extra"><?= $topbarExtra ?></div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php foreach (flash_get() as $flash): ?>
  <div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
<?php endforeach; ?>
