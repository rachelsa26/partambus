// Langkah-langkah pengguna PARTAMBUS sebagai fungsi kecil yang bisa dipakai ulang
// oleh semua skenario (mirip Page Object di Playwright, tapi untuk HTTP).
import http from 'k6/http';
import { check, fail, sleep } from 'k6';
import { Counter, Rate, Trend } from 'k6/metrics';
import { BASE_URL, PRODUCT_BARCODE, SEARCH_TERMS } from './config.js';

// Metrik bisnis tambahan, muncul di ringkasan dan dashboard k6.
export const salesCreated = new Counter('sales_created');
export const checkoutSuccess = new Rate('checkout_success');
export const journeyDuration = new Trend('kasir_journey_duration', true);

const FORM = { 'Content-Type': 'application/x-www-form-urlencoded' };
const AJAX = { 'X-Requested-With': 'XMLHttpRequest' };

// Tag "name" menyatukan URL yang mirip (misal ?q=kopi dan ?q=rokok) jadi satu
// baris statistik, supaya threshold per endpoint bisa dipasang.
const tag = (name) => ({ tags: { name } });

export function extract(body, field) {
  const match = String(body).match(new RegExp(`name="${field}" value="([a-f0-9]+)"`));
  return match ? match[1] : null;
}

export function login(user) {
  // Mulai dari sesi bersih: kalau VU ini masih login sebagai user lain,
  // halaman login akan langsung redirect ke dashboard tanpa CSRF token.
  http.get(`${BASE_URL}/auth/logout.php`, tag('GET /auth/logout.php'));
  const page = http.get(`${BASE_URL}/auth/login.php`, tag('GET /auth/login.php'));
  const csrf = extract(page.body, 'csrf_token');
  if (!csrf) fail(`Halaman login tidak berisi CSRF token (status ${page.status})`);

  const res = http.post(
    `${BASE_URL}/auth/login.php`,
    { csrf_token: csrf, username: user.username, password: user.password },
    { headers: FORM, ...tag('POST /auth/login.php') },
  );
  const ok = check(res, {
    'login berhasil -> dashboard': (r) => r.status === 200 && r.url.includes('/dashboard.php'),
  });
  if (!ok) fail(`Login ${user.username} gagal (status ${res.status}, url ${res.url})`);
  return res;
}

// Kasir mengetik kata kunci huruf demi huruf; halaman memanggil ?ajax=1 tiap ketikan.
export function searchAsYouType() {
  const term = SEARCH_TERMS[Math.floor(Math.random() * SEARCH_TERMS.length)];
  for (let length = 3; length <= term.length; length += 2) {
    const res = http.get(
      `${BASE_URL}/pos/index.php?ajax=1&q=${encodeURIComponent(term.slice(0, length))}`,
      tag('GET /pos/index.php?ajax=1 (cari)'),
    );
    check(res, { 'pencarian: status 200': (r) => r.status === 200 && !r.url.includes('/auth/login.php') });
    sleep(0.3);
  }
}

export function scanBarcode(barcode = PRODUCT_BARCODE) {
  const res = http.get(
    `${BASE_URL}/pos/index.php?ajax=scan&q=${encodeURIComponent(barcode)}`,
    tag('GET /pos/index.php?ajax=scan'),
  );
  let body = {};
  try {
    body = res.json();
  } catch {
    // dibiarkan kosong, check di bawah yang akan gagal
  }
  const ok = check(res, {
    'scan: produk dikenali (action add)': () => body.action === 'add',
  });
  if (!ok) console.warn(`scan: ${res.status} ${res.body.slice(0, 300)}`);
  return ok ? { productId: body.product_id, unitId: body.unit_id } : null;
}

// Tambah ke keranjang. Server redirect ke halaman kasir, yang sekaligus berisi
// csrf_token dan request_token untuk pembayaran, jadi tidak perlu request ekstra.
export function addToCart(csrf, product, qty) {
  const res = http.post(
    `${BASE_URL}/pos/index.php`,
    { csrf_token: csrf, pos_action: 'add_to_cart', product_id: product.productId, unit_id: product.unitId, qty },
    { headers: FORM, ...tag('POST /pos/index.php (add_to_cart)') },
  );
  const tokens = {
    csrf: extract(res.body, 'csrf_token'),
    requestToken: extract(res.body, 'request_token'),
  };
  check(res, {
    'keranjang: halaman kasir tampil': (r) => r.status === 200 && r.url.includes('/pos/index.php'),
    'keranjang: request_token tersedia': () => tokens.requestToken !== null,
  });
  return tokens;
}

export function openPos() {
  const res = http.get(`${BASE_URL}/pos/index.php`, tag('GET /pos/index.php'));
  check(res, { 'halaman kasir: tampil (masih login)': (r) => r.status === 200 && r.url.includes('/pos/index.php') });
  return extract(res.body, 'csrf_token');
}

export function clearCart(csrf) {
  http.post(
    `${BASE_URL}/pos/index.php`,
    { csrf_token: csrf, pos_action: 'clear_cart' },
    {
      headers: FORM,
      ...tag('POST /pos/index.php (clear_cart)'),
    },
  );
}

// Bayar non-tunai (QRIS) seperti tombol "Bayar" di modal: request AJAX, balasan JSON.
export function checkoutQris(tokens, { expectSuccess = true } = {}) {
  const res = http.post(
    `${BASE_URL}/pos/checkout.php`,
    { csrf_token: tokens.csrf, method: 'qris', request_token: tokens.requestToken },
    { headers: { ...FORM, ...AJAX }, ...tag('POST /pos/checkout.php') },
  );
  let body = {};
  try {
    body = res.json();
  } catch {
    // bukan JSON: dihitung gagal
  }
  if (!expectSuccess) return { res, body };
  const ok = check(res, {
    'checkout: transaksi tersimpan': () => body.success === true && body.sale_id > 0,
  });
  checkoutSuccess.add(ok);
  if (ok) salesCreated.add(1);
  else console.warn(`Checkout gagal: status ${res.status}, ${JSON.stringify(body.errors || res.body.slice(0, 200))}`);
  return ok;
}

// Satu pelanggan dilayani kasir: cari, scan, masukkan keranjang, bayar QRIS.
export function kasirJourney() {
  const started = Date.now();
  const csrf = openPos();
  searchAsYouType();
  const product = scanBarcode();
  if (!product || !csrf) return;
  const tokens = addToCart(csrf, product, 1 + Math.floor(Math.random() * 3));
  if (!tokens.csrf || !tokens.requestToken) {
    clearCart(tokens.csrf || csrf);
    return;
  }
  sleep(1); // kasir menekan "Bayar" dan pelanggan menunjukkan QRIS
  checkoutQris(tokens);
  journeyDuration.add(Date.now() - started);
}

// Owner memantau toko: dashboard, riwayat penjualan, laporan.
export function ownerJourney() {
  const pages = [
    ['/dashboard.php', 'GET /dashboard.php'],
    ['/sales/index.php', 'GET /sales/index.php'],
    ['/reports/sales.php?preset=month', 'GET /reports/sales.php'],
    ['/reports/inventory.php', 'GET /reports/inventory.php'],
  ];
  for (const [path, name] of pages) {
    const res = http.get(`${BASE_URL}${path}`, tag(name));
    check(res, { 'halaman owner: tampil (masih login)': (r) => r.status === 200 && !r.url.includes('/auth/login.php') });
    sleep(1 + Math.random() * 2);
  }
}

// Baca stok produk uji lewat halaman Penyesuaian Stok (butuh login owner).
// Produk dicari lewat product picker, bukan scan, karena scan menolak
// produk yang stoknya sudah habis.
export function readStock(barcode = PRODUCT_BARCODE) {
  const picker = http.get(
    `${BASE_URL}/inventory/adjustment.php?ajax=1&q=${encodeURIComponent(barcode)}`,
    tag('GET /inventory/adjustment.php?ajax=1'),
  );
  const productId = (String(picker.body).match(/\?product_id=(\d+)/) || [])[1];
  if (!productId) fail(`Produk dengan barcode ${barcode} tidak ditemukan. Apakah data seed sudah dimuat?`);

  const url = `${BASE_URL}/inventory/adjustment.php?product_id=${productId}`;
  const page = http.get(url, tag('GET /inventory/adjustment.php'));
  return {
    url,
    csrf: extract(page.body, 'csrf_token'),
    current: Number((page.body.match(/Stok saat ini: (\d+)/) || [])[1] || 0),
    // Opsi pertama di dropdown Satuan adalah satuan dasar (PCS).
    baseUnitId: (page.body.match(/<select id="unit_id"[\s\S]*?<option value="(\d+)"/) || [])[1],
  };
}

// Ubah stok menjadi tepat `target` PCS lewat Penyesuaian Stok (masuk/keluar),
// sehingga tetap tercatat di ledger dan test DB tetap konsisten.
export function setStock(target, reason, barcode = PRODUCT_BARCODE) {
  const stock = readStock(barcode);
  const diff = target - stock.current;
  if (diff === 0) return stock.current;
  const res = http.post(
    stock.url,
    {
      csrf_token: stock.csrf,
      direction: diff > 0 ? 'in' : 'out',
      unit_id: stock.baseUnitId,
      qty: String(Math.abs(diff)),
      reason,
    },
    { headers: FORM, ...tag('POST /inventory/adjustment.php') },
  );
  if (!check(res, { 'setup: stok disesuaikan': (r) => r.url.includes('/products/edit.php') })) {
    fail(`Gagal menyesuaikan stok (status ${res.status})`);
  }
  return stock.current;
}

// Dipanggil sekali di setup(): pastikan stok produk uji cukup untuk seluruh test.
export function ensureStock(owner, minStock) {
  login(owner);
  const before = readStock().current;
  if (before < minStock) setStock(minStock, 'Stok tambahan untuk tes performa k6');
  return { stockBefore: before, stockAfterSetup: Math.max(before, minStock) };
}
