// RACE CONDITION TEST: 30 kasir menekan "Bayar" pada DETIK YANG SAMA untuk
// produk yang stoknya tinggal 10.
// Yang diharapkan dari sistem kasir yang benar:
//   - tepat 10 transaksi berhasil, 20 ditolak dengan pesan "stok tidak mencukupi"
//   - stok akhir tepat 0 (tidak pernah minus / tidak ada "overselling")
//   - tidak ada error lain (500, timeout, deadlock)
// Setelah selesai, stok dikembalikan ke jumlah semula.
import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter, Gauge } from 'k6/metrics';
import { BASE_URL, CASHIER, OWNER } from '../lib/config.js';
import { addToCart, checkoutQris, login, openPos, readStock, scanBarcode, setStock } from '../lib/partambus.js';
import { buildSummary, summaryTrendStats } from '../lib/summary.js';

const BUYERS = Number(__ENV.RACE_BUYERS || 30);
const STOCK = Number(__ENV.RACE_STOCK || 10);

const sold = new Counter('race_sold');
const rejected = new Counter('race_rejected_out_of_stock');
const otherErrors = new Counter('race_other_errors');
const finalStock = new Gauge('race_final_stock');

export const options = {
  scenarios: {
    race: { executor: 'per-vu-iterations', vus: BUYERS, iterations: 1, maxDuration: '2m' },
  },
  thresholds: {
    race_sold: [`count==${STOCK}`],
    race_rejected_out_of_stock: [`count==${BUYERS - STOCK}`],
    race_other_errors: ['count==0'],
    race_final_stock: ['value==0'],
    http_req_failed: ['rate==0'],
  },
  summaryTrendStats,
};

export function setup() {
  login(OWNER);
  const original = setStock(STOCK, `Tes race condition k6: stok diset ${STOCK}`);
  // Semua kasir virtual menunggu sampai waktu ini, lalu membayar bersamaan.
  return { original, payAt: Date.now() + 15000 };
}

export default function (data) {
  login(CASHIER);
  const csrf = openPos();
  const product = scanBarcode();
  if (!product || !csrf) {
    otherErrors.add(1);
    return;
  }
  const tokens = addToCart(csrf, product, 1);

  sleep(Math.max(0, (data.payAt - Date.now()) / 1000)); // tunggu aba-aba

  const { res, body } = checkoutQris(tokens, { expectSuccess: false });
  const errors = (body.errors || []).join(' ');
  if (body.success === true) sold.add(1);
  else if (/stok/i.test(errors)) rejected.add(1);
  else {
    otherErrors.add(1);
    console.warn(`Respons tak terduga: status ${res.status}, ${JSON.stringify(body)}`);
  }
  check(res, { 'race: sukses ATAU ditolak karena stok': () => body.success === true || /stok/i.test(errors) });
}

export function teardown(data) {
  login(OWNER);
  const stock = readStock().current;
  finalStock.add(stock);
  check(stock, { 'race: stok akhir tepat 0 (tidak minus)': (s) => s === 0 });
  setStock(data.original, 'Tes race condition k6 selesai: stok dikembalikan');
  http.get(`${BASE_URL}/auth/logout.php`);
}

export const handleSummary = buildSummary('race');
