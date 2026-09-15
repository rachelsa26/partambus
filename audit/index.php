<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

require_role(['owner']);
$pageTitle = 'Log Audit';

$actionFilter = trim((string) ($_GET['action'] ?? ''));
$actorFilter = trim((string) ($_GET['actor'] ?? '')); // numeric user id, or 'system'
$entityTypeFilter = trim((string) ($_GET['entity_type'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$batchId = trim((string) ($_GET['batch_id'] ?? ''));

$allowedPerPage = [20, 50, 100];
$perPage = (int) ($_GET['per_page'] ?? 50);
if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 50;
}

$whereSql = ' WHERE 1=1';
$params = [];

if ($batchId !== '') {
    $whereSql .= ' AND al.batch_id = ?';
    $params[] = $batchId;
} else {
    // Collapse the (potentially thousands of) per-product rows a bulk import
    // generates under its one bulk_import_completed summary row; drilling
    // into a specific batch (above) bypasses this collapse entirely.
    $whereSql .= ' AND (al.batch_id IS NULL OR al.action = "bulk_import_completed")';
}
if ($actionFilter !== '') {
    $whereSql .= ' AND al.action = ?';
    $params[] = $actionFilter;
}
if ($actorFilter === 'system') {
    $whereSql .= ' AND al.actor_user_id IS NULL';
} elseif ($actorFilter !== '' && ctype_digit($actorFilter)) {
    $whereSql .= ' AND al.actor_user_id = ?';
    $params[] = (int) $actorFilter;
}
if ($entityTypeFilter !== '') {
    $whereSql .= ' AND al.entity_type = ?';
    $params[] = $entityTypeFilter;
}
if ($dateFrom !== '' || $dateTo !== '') {
    $range = resolve_date_range('custom', $dateFrom, $dateTo);
    $whereSql .= ' AND al.created_at BETWEEN ? AND ?';
    $params[] = $range['from'];
    $params[] = $range['to'];
}

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM audit_logs al' . $whereSql);
$countStmt->execute($params);
$totalCount = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalCount / $perPage));

$page = max(1, min($totalPages, (int) ($_GET['page'] ?? 1)));
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare(
    'SELECT al.*, u.full_name AS actor_name, pu2.product_id AS resolved_product_id
     FROM audit_logs al
     LEFT JOIN users u ON u.id = al.actor_user_id
     LEFT JOIN product_units pu2 ON al.entity_type = "product_unit" AND al.entity_id = pu2.id' . $whereSql . '
     ORDER BY al.created_at DESC, al.id DESC
     LIMIT ? OFFSET ?'
);
$i = 1;
foreach ($params as $p) {
    $stmt->bindValue($i++, $p);
}
$stmt->bindValue($i++, $perPage, PDO::PARAM_INT);
$stmt->bindValue($i++, $offset, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll();

$actors = $pdo->query(
    'SELECT DISTINCT u.id, u.full_name FROM audit_logs al JOIN users u ON u.id = al.actor_user_id ORDER BY u.full_name'
)->fetchAll();
$entityTypes = $pdo->query('SELECT DISTINCT entity_type FROM audit_logs ORDER BY entity_type')->fetchAll(PDO::FETCH_COLUMN);
$hasSystemActor = (bool) $pdo->query('SELECT 1 FROM audit_logs WHERE actor_user_id IS NULL LIMIT 1')->fetchColumn();

$hasActiveFilters = $actionFilter !== '' || $actorFilter !== '' || $entityTypeFilter !== '' || $dateFrom !== '' || $dateTo !== '';

/** Builds a pagination/filter link preserving every current filter, only overriding `page`. */
function audit_page_url(int $targetPage, array $filters, int $perPage): string
{
    $qs = array_filter($filters, static fn ($v) => $v !== '');
    $qs['page'] = $targetPage;
    $qs['per_page'] = $perPage;
    return APP_BASE_PATH . '/audit/index.php?' . http_build_query($qs);
}

$currentFilters = [
    'action' => $actionFilter, 'actor' => $actorFilter, 'entity_type' => $entityTypeFilter,
    'date_from' => $dateFrom, 'date_to' => $dateTo,
];

require __DIR__ . '/../includes/header.php';
?>

<?php if ($batchId !== ''): ?>
  <div class="flash flash-warning">
    Menampilkan detail satu batch import saja (termasuk tiap produk yang diproses).
    <a href="<?= APP_BASE_PATH ?>/audit/index.php">Kembali ke Log Audit lengkap</a>
  </div>
<?php endif; ?>

<details class="card filter-panel" <?= $hasActiveFilters ? 'open' : '' ?>>
  <summary>Filter</summary>
  <form method="get">
    <div class="filter-grid">
      <div class="form-group">
        <label for="filter-action">Jenis Aksi</label>
        <select id="filter-action" name="action">
          <option value="">Semua aksi</option>
          <?php foreach (AUDIT_ACTION_LABELS as $key => $meta): ?>
            <option value="<?= e($key) ?>" <?= $actionFilter === $key ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label for="filter-actor">Aktor</label>
        <select id="filter-actor" name="actor">
          <option value="">Semua aktor</option>
          <?php if ($hasSystemActor): ?><option value="system" <?= $actorFilter === 'system' ? 'selected' : '' ?>>Sistem</option><?php endif; ?>
          <?php foreach ($actors as $a): ?>
            <option value="<?= (int) $a['id'] ?>" <?= $actorFilter === (string) $a['id'] ? 'selected' : '' ?>><?= e($a['full_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label for="filter-entity">Entitas</label>
        <select id="filter-entity" name="entity_type">
          <option value="">Semua entitas</option>
          <?php foreach ($entityTypes as $et): ?>
            <option value="<?= e($et) ?>" <?= $entityTypeFilter === $et ? 'selected' : '' ?>><?= e(audit_entity_label($et)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label for="filter-date-from">Dari Tanggal</label>
        <input type="date" id="filter-date-from" name="date_from" value="<?= e($dateFrom) ?>">
      </div>
      <div class="form-group">
        <label for="filter-date-to">Sampai Tanggal</label>
        <input type="date" id="filter-date-to" name="date_to" value="<?= e($dateTo) ?>">
      </div>
      <div class="form-group">
        <label for="filter-per-page">Per Halaman</label>
        <select id="filter-per-page" name="per_page">
          <?php foreach ($allowedPerPage as $opt): ?>
            <option value="<?= $opt ?>" <?= $perPage === $opt ? 'selected' : '' ?>><?= $opt ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="btn-row">
      <button type="submit" class="btn btn-secondary">Terapkan Filter</button>
      <?php if ($hasActiveFilters): ?><a class="btn btn-secondary" href="<?= APP_BASE_PATH ?>/audit/index.php">Reset</a><?php endif; ?>
    </div>
  </form>
</details>

<?php if ($totalCount > 0): ?>
<p class="text-muted" style="margin-bottom:10px">
  Menampilkan <?= number_format($offset + 1, 0, ',', '.') ?>&ndash;<?= number_format(min($offset + $perPage, $totalCount), 0, ',', '.') ?> dari <?= number_format($totalCount, 0, ',', '.') ?> log
</p>
<?php endif; ?>

<div class="card">
<table class="table-align-top">
  <thead>
    <tr><th>Waktu</th><th>Aktor</th><th>Aksi</th><th>Entitas</th><th>Sebelum</th><th>Sesudah</th><th></th></tr>
  </thead>
  <tbody>
  <?php foreach ($logs as $log): ?>
    <?php
      $diff = audit_diff_summary($log['before_value'], $log['after_value'], $log['action']);
      $actionMeta = audit_action_label($log['action']);
      $entityUrl = audit_entity_url($log['entity_type'], $log['entity_id'] !== null ? (int) $log['entity_id'] : null, $log['resolved_product_id'] !== null ? (int) $log['resolved_product_id'] : null);
      $entityText = audit_entity_label($log['entity_type']) . ($log['entity_id'] ? ' #' . (int) $log['entity_id'] : '');
    ?>
    <tr>
      <td class="text-muted"><?= e(fmt_date($log['created_at'])) ?></td>
      <td><?= e($log['actor_name'] ?? 'Sistem') ?></td>
      <td><span class="badge <?= e($actionMeta['class']) ?>"><?= e($actionMeta['label']) ?></span></td>
      <td>
        <?php if ($entityUrl): ?>
          <a href="<?= e($entityUrl) ?>"><?= e($entityText) ?></a>
        <?php else: ?>
          <?= e($entityText) ?>
        <?php endif; ?>
        <?php if ($log['action'] === 'bulk_import_completed' && $log['batch_id']): ?>
          <?php $afterData = json_decode((string) $log['after_value'], true) ?: []; ?>
          <br><a href="<?= e(audit_page_url(1, ['batch_id' => $log['batch_id']], $perPage)) ?>" style="font-size:12px">
            Lihat detail (<?= (int) (($afterData['created'] ?? 0) + ($afterData['updated'] ?? 0)) ?> produk)
          </a>
        <?php endif; ?>
      </td>
      <?php if ($diff['mode'] === 'none'): ?>
        <td class="text-muted" style="font-size:12px">-</td>
        <td class="text-muted" style="font-size:12px">-</td>
      <?php elseif ($diff['mode'] === 'diff' && !$diff['lines']): ?>
        <td class="text-muted" style="font-size:12px" colspan="2">Tidak ada perubahan nilai</td>
      <?php elseif ($diff['mode'] === 'summary'): ?>
        <td class="text-muted" style="font-size:12px">(data baru)</td>
        <td style="max-width:260px;font-size:12px">
          <ul style="margin:0;padding-left:16px">
            <?php foreach ($diff['lines'] as $line): ?>
              <li><strong><?= e($line['field']) ?></strong>: <?= e($line['value']) ?></li>
            <?php endforeach; ?>
          </ul>
        </td>
      <?php else: ?>
        <td style="max-width:220px;font-size:12px">
          <ul style="margin:0;padding-left:16px">
            <?php foreach ($diff['lines'] as $line): ?>
              <li><strong><?= e($line['field']) ?></strong>: <?= e($line['before']) ?></li>
            <?php endforeach; ?>
          </ul>
        </td>
        <td style="max-width:220px;font-size:12px">
          <ul style="margin:0;padding-left:16px">
            <?php foreach ($diff['lines'] as $line): ?>
              <li><strong><?= e($line['field']) ?></strong>: <?= e($line['after']) ?></li>
            <?php endforeach; ?>
          </ul>
        </td>
      <?php endif; ?>
      <td>
        <?php if ($log['before_value'] !== null || $log['after_value'] !== null): ?>
          <button type="button" class="btn btn-small btn-secondary"
            data-audit-json-btn
            data-audit-before="<?= e($log['before_value'] ?? '') ?>"
            data-audit-after="<?= e($log['after_value'] ?? '') ?>">Lihat JSON lengkap</button>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$logs): ?>
    <tr><td colspan="7" class="empty-state">Tidak ada log yang cocok.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
</div>

<?php if ($totalCount > 0): ?>
<div class="btn-row" style="justify-content:center">
  <a class="btn btn-secondary btn-small<?= $page <= 1 ? ' disabled' : '' ?>"
     href="<?= $page > 1 ? e(audit_page_url($page - 1, array_merge($currentFilters, ['batch_id' => $batchId]), $perPage)) : '#' ?>"
     <?= $page <= 1 ? 'aria-disabled="true" onclick="return false;"' : '' ?>>&laquo; Sebelumnya</a>
  <span class="text-muted" style="font-size:13px">Halaman <?= $page ?> dari <?= $totalPages ?></span>
  <a class="btn btn-secondary btn-small<?= $page >= $totalPages ? ' disabled' : '' ?>"
     href="<?= $page < $totalPages ? e(audit_page_url($page + 1, array_merge($currentFilters, ['batch_id' => $batchId]), $perPage)) : '#' ?>"
     <?= $page >= $totalPages ? 'aria-disabled="true" onclick="return false;"' : '' ?>>Selanjutnya &raquo;</a>
</div>
<?php endif; ?>

<div class="modal-overlay hidden" id="audit-json-modal">
  <div class="modal-box" style="max-width:600px">
    <strong>Detail JSON</strong>
    <p class="text-muted" style="margin:4px 0 10px">Sebelum</p>
    <pre id="audit-json-before" style="background:#fafafa;border:1px solid var(--color-border);border-radius:var(--radius);padding:10px;font-size:12px;max-height:200px;overflow:auto;white-space:pre-wrap;word-break:break-word"></pre>
    <p class="text-muted" style="margin:10px 0 10px">Sesudah</p>
    <pre id="audit-json-after" style="background:#fafafa;border:1px solid var(--color-border);border-radius:var(--radius);padding:10px;font-size:12px;max-height:200px;overflow:auto;white-space:pre-wrap;word-break:break-word"></pre>
    <div class="btn-row">
      <button type="button" class="btn btn-secondary" data-modal-cancel>Tutup</button>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
