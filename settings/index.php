<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

require_role(['owner']);
$pageTitle = 'Pengaturan';
$topbarSubtitle = 'Kelola informasi toko, kebijakan bisnis, dan preferensi sistem.';

$errors = [];
$unitsList = get_active_units($pdo);
$unitCodes = array_column($unitsList, 'code');

if (is_post()) {
    verify_csrf();

    $storeName = post('store_name');
    $storeAddress = post('store_address');
    $storePhone = post('store_phone');
    $discount = post('cashier_max_discount_percent', '0');
    $defaultUnitCode = post('default_unit_code', '');
    $dateFormat = post('date_format', 'd/m/Y');

    if ($storeName === '') {
        $errors[] = 'Nama toko wajib diisi.';
    }
    if (!ctype_digit($discount) || (int) $discount < 0 || (int) $discount > 100) {
        $errors[] = 'Batas diskon kasir harus angka 0–100.';
    }
    if ($defaultUnitCode !== '' && !in_array($defaultUnitCode, $unitCodes, true)) {
        $errors[] = 'Satuan jualan utama tidak valid.';
    }
    if (!array_key_exists($dateFormat, DATE_FORMAT_OPTIONS)) {
        $errors[] = 'Format tanggal tidak valid.';
    }

    if (!$errors) {
        $before = get_all_settings($pdo);
        set_setting($pdo, 'store_name', $storeName);
        set_setting($pdo, 'store_address', $storeAddress);
        set_setting($pdo, 'store_phone', $storePhone);
        set_setting($pdo, 'cashier_max_discount_percent', $discount);
        set_setting($pdo, 'default_unit_code', $defaultUnitCode);
        set_setting($pdo, 'date_format', $dateFormat);
        $beforeForAudit = [
            'store_name' => $before['store_name'] ?? null,
            'store_address' => $before['store_address'] ?? null,
            'store_phone' => $before['store_phone'] ?? null,
            'cashier_max_discount_percent' => $before['cashier_max_discount_percent'] ?? null,
            'default_unit_code' => $before['default_unit_code'] ?? null,
            'date_format' => $before['date_format'] ?? null,
        ];
        $afterForAudit = [
            'store_name' => $storeName,
            'store_address' => $storeAddress,
            'store_phone' => $storePhone,
            'cashier_max_discount_percent' => $discount,
            'default_unit_code' => $defaultUnitCode,
            'date_format' => $dateFormat,
        ];
        if (audit_has_real_change($beforeForAudit, $afterForAudit)) {
            log_audit($pdo, 'settings_updated', 'app_settings', null, $beforeForAudit, $afterForAudit);
        }
        flash_set('success', 'Pengaturan berhasil disimpan.');
        redirect('/settings/index.php');
    }
}

$settings = get_all_settings($pdo);

require __DIR__ . '/../includes/header.php';
?>

<?php foreach ($errors as $error): ?>
  <div class="flash flash-error"><?= e($error) ?></div>
<?php endforeach; ?>

<div class="settings-grid">
  <div class="settings-col">

    <div class="card">
      <div class="card-title-row">
        <span class="card-title-icon-box"><?= partambus_icon('home', 18) ?></span>
        <strong style="font-size:16px">Informasi Toko</strong>
      </div>

      <form method="post" id="settings-form" style="margin-top:16px">
        <?= csrf_field() ?>
        <div class="form-row">
          <div class="form-group">
            <label for="store_name">Nama Toko</label>
            <input type="text" id="store_name" name="store_name" value="<?= e($settings['store_name'] ?? '') ?>" required>
          </div>
          <div class="form-group">
            <label for="store_phone">No. Telepon Toko</label>
            <input type="text" id="store_phone" name="store_phone" value="<?= e($settings['store_phone'] ?? '') ?>" placeholder="Contoh: 0812-3456-7890">
            <p class="form-hint">Tampil di header struk.</p>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label for="store_address">Alamat Toko</label>
            <textarea id="store_address" name="store_address" rows="2" placeholder="Contoh: Jl. Sisingamangaraja No. 12, Balige"><?= e($settings['store_address'] ?? '') ?></textarea>
            <p class="form-hint">Tampil di header struk. Boleh dikosongkan.</p>
          </div>
          <div class="form-group">
            <label for="default_unit_code">Satuan Jualan Utama (Dalam Toko)</label>
            <select id="default_unit_code" name="default_unit_code">
              <option value="">-- tidak ada default --</option>
              <?php foreach ($unitsList as $u): ?>
                <option value="<?= e($u['code']) ?>" <?= ($settings['default_unit_code'] ?? '') === $u['code'] ? 'selected' : '' ?>><?= e($u['code']) ?><?= $u['description'] ? ' — ' . e($u['description']) : '' ?></option>
              <?php endforeach; ?>
            </select>
            <p class="form-hint">Otomatis terpilih sebagai satuan dasar saat menambah produk baru.</p>
          </div>
        </div>
        <div class="form-group">
          <label for="cashier_max_discount_percent">Batas Maksimum Diskon Kasir (%)</label>
          <input type="number" id="cashier_max_discount_percent" name="cashier_max_discount_percent" value="<?= e($settings['cashier_max_discount_percent'] ?? '0') ?>" min="0" max="100" step="1" style="max-width:160px">
          <p class="form-hint">Diterapkan langsung saat kasir memberi diskon per item di Kasir — Owner tidak dibatasi. Default 0 = kasir tidak bisa memberi diskon sama sekali.</p>
        </div>
        <div class="btn-row">
          <button type="submit" class="btn"><?= partambus_icon('save', 15) ?> Simpan Perubahan</button>
        </div>
      </form>
    </div>

    <div class="card">
      <div class="card-title-row">
        <span class="card-title-icon-box"><?= partambus_icon('shield-check', 18) ?></span>
        <strong style="font-size:16px">Kebijakan Toko Tetap</strong>
      </div>
      <p class="form-hint" style="margin-top:6px">Aturan sistem yang dikunci (tidak bisa diubah dari sini) — tercatat di sini sebagai referensi.</p>
      <table style="margin-top:10px">
        <tbody>
          <tr><td>Zona Waktu</td><td class="text-right"><?= e($settings['timezone'] ?? '') ?></td></tr>
          <tr><td>Batas hari toko</td><td class="text-right"><?= e($settings['store_day_start'] ?? '') ?> (tidak lewat tengah malam)</td></tr>
          <tr><td>Prefix nomor jual</td><td class="text-right"><?= e($settings['sale_number_prefix'] ?? '') ?>-YYYYMMDD-0001</td></tr>
          <tr><td>Prefix nomor beli</td><td class="text-right"><?= e($settings['purchase_number_prefix'] ?? '') ?>-YYYYMMDD-0001</td></tr>
          <tr><td>Wajib cash session utk jual tunai</td><td class="text-right"><?= ($settings['require_cash_session_for_cash_sale'] ?? '') === '1' ? 'Ya' : 'Tidak' ?></td></tr>
          <tr><td>Owner boleh jual di bawah modal</td><td class="text-right"><?= ($settings['allow_below_cost_owner'] ?? '') === '1' ? 'Ya (dengan warning)' : 'Tidak' ?></td></tr>
          <tr><td>Kasir boleh jual di bawah modal</td><td class="text-right"><?= ($settings['allow_below_cost_cashier'] ?? '') === '1' ? 'Ya' : 'Tidak' ?></td></tr>
          <tr><td>Modal stok awal wajib diisi</td><td class="text-right"><?= ($settings['initial_cost_required'] ?? '') === '1' ? 'Ya' : 'Tidak (boleh unknown + warning)' ?></td></tr>
        </tbody>
      </table>
    </div>

  </div>

  <div class="settings-col">

    <div class="card">
      <div class="card-title-row">
        <span class="card-title-icon-box"><?= partambus_icon('monitor', 18) ?></span>
        <strong style="font-size:16px">Preferensi Tampilan</strong>
      </div>
      <p class="form-hint" style="margin-top:6px">Pilih tema tampilan yang nyaman untuk Anda.</p>

      <div class="theme-option-grid" id="theme-option-grid">
        <label class="theme-option" data-theme-option="light">
          <input type="radio" name="theme_choice" value="light" style="position:absolute;opacity:0;width:0;height:0">
          <div class="theme-option-header"><?= partambus_icon('sun', 15) ?> Light Theme</div>
          <p class="theme-option-desc">Tampilan terang, cocok untuk siang hari.</p>
          <div class="theme-preview">
            <div class="theme-preview-sidebar"></div>
            <div class="theme-preview-body theme-preview-body-light">
              <div class="theme-preview-bar" style="width:60%;background:#93C5FD"></div>
              <div class="theme-preview-bar" style="width:85%;background:#E2E8F0"></div>
              <div class="theme-preview-bar" style="width:70%;background:#E2E8F0"></div>
            </div>
          </div>
        </label>
        <label class="theme-option" data-theme-option="dark">
          <input type="radio" name="theme_choice" value="dark" style="position:absolute;opacity:0;width:0;height:0">
          <div class="theme-option-header"><?= partambus_icon('moon', 15) ?> Dark Theme</div>
          <p class="theme-option-desc">Tampilan gelap, nyaman di malam hari.</p>
          <div class="theme-preview">
            <div class="theme-preview-sidebar"></div>
            <div class="theme-preview-body theme-preview-body-dark">
              <div class="theme-preview-bar" style="width:60%;background:#3B82F6"></div>
              <div class="theme-preview-bar" style="width:85%;background:#2A3647"></div>
              <div class="theme-preview-bar" style="width:70%;background:#2A3647"></div>
            </div>
          </div>
        </label>
      </div>
      <div class="flash flash-success" style="margin:14px 0 0;display:flex;align-items:center;gap:8px">
        <?= partambus_icon('info', 15) ?> Sistem mengingat preferensi tema Anda di perangkat ini.
      </div>
    </div>

    <div class="card">
      <div class="card-title-row">
        <span class="card-title-icon-box"><?= partambus_icon('gear', 18) ?></span>
        <strong style="font-size:16px">Lainnya</strong>
      </div>

      <div class="settings-lite-row">
        <div>
          <div class="settings-lite-label">Bahasa Aplikasi</div>
          <p class="form-hint">Saat ini hanya tersedia Bahasa Indonesia.</p>
        </div>
        <select disabled style="width:auto;min-width:170px">
          <option>Bahasa Indonesia</option>
        </select>
      </div>

      <div class="settings-lite-row">
        <div>
          <div class="settings-lite-label">Format Tanggal</div>
          <p class="form-hint">Berlaku untuk tabel &amp; daftar (Riwayat Penjualan, Log Audit, dll).</p>
        </div>
        <select name="date_format" form="settings-form" style="width:auto;min-width:170px">
          <?php foreach (DATE_FORMAT_OPTIONS as $value => $label): ?>
            <option value="<?= e($value) ?>" <?= ($settings['date_format'] ?? 'd/m/Y') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

  </div>
</div>

<script>
(function () {
  var grid = document.getElementById('theme-option-grid');
  if (!grid) return;
  var options = grid.querySelectorAll('[data-theme-option]');

  function applyTheme(theme) {
    if (theme === 'dark') {
      document.documentElement.setAttribute('data-theme', 'dark');
    } else {
      document.documentElement.removeAttribute('data-theme');
    }
  }

  function setActive(theme) {
    options.forEach(function (opt) {
      var input = opt.querySelector('input');
      var isMatch = opt.getAttribute('data-theme-option') === theme;
      input.checked = isMatch;
      opt.classList.toggle('theme-option-active', isMatch);
    });
  }

  var current = 'light';
  try {
    current = localStorage.getItem('partambus_theme') === 'dark' ? 'dark' : 'light';
  } catch (e) {}
  setActive(current);

  options.forEach(function (opt) {
    opt.addEventListener('click', function () {
      var theme = opt.getAttribute('data-theme-option');
      setActive(theme);
      applyTheme(theme);
      try { localStorage.setItem('partambus_theme', theme); } catch (e) {}
    });
  });
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
