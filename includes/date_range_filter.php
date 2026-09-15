<?php
/**
 * @var array{preset:string, from_date:string, to_date:string} $range
 */
?>
<form method="get" class="search-bar" style="flex-wrap:wrap">
  <?php foreach (REPORT_DATE_PRESETS as $key => $label): ?>
    <?php if ($key === 'custom') continue; ?>
    <a class="btn btn-small <?= $range['preset'] === $key ? 'btn' : 'btn-secondary' ?>" href="?preset=<?= e($key) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
  <span class="text-muted">atau kustom:</span>
  <input type="date" name="from" value="<?= e($range['from_date']) ?>">
  <input type="date" name="to" value="<?= e($range['to_date']) ?>">
  <input type="hidden" name="preset" value="custom">
  <button type="submit" class="btn btn-small btn-secondary">Terapkan</button>
</form>
