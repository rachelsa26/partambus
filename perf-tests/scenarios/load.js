// LOAD TEST: beban "hari ramai" yang realistis.
//   - Kasir: naik bertahap sampai 10 kasir bersamaan, bertahan 3 menit.
//     (Toko normal 1-2 kasir, jadi ini sekitar 5x beban normal.)
//   - Owner: 2 orang membuka dashboard & laporan sepanjang test.
// Durasi total sekitar 5 menit. Threshold = target waktu respons yang harus tercapai.
import { buildSummary, summaryTrendStats } from '../lib/summary.js';
export { kasir, owner } from './_shared.js';
import { setupStock } from './_shared.js';

export const options = {
  scenarios: {
    kasir: {
      executor: 'ramping-vus',
      exec: 'kasir',
      startVUs: 0,
      stages: [
        { duration: '30s', target: 5 },
        { duration: '1m', target: 10 },
        { duration: '3m', target: 10 },
        { duration: '30s', target: 0 },
      ],
      gracefulRampDown: '30s',
    },
    owner: { executor: 'constant-vus', exec: 'owner', vus: 2, duration: '5m' },
  },
  thresholds: {
    // Keseluruhan
    http_req_failed: ['rate<0.01'], // < 1% request error
    checks: ['rate>0.99'],
    checkout_success: ['rate>0.99'],
    http_req_duration: ['p(95)<800', 'p(99)<1500'],
    // Per endpoint penting
    'http_req_duration{name:POST /auth/login.php}': ['p(95)<1000'],
    'http_req_duration{name:GET /pos/index.php?ajax=1 (cari)}': ['p(95)<500'],
    'http_req_duration{name:GET /pos/index.php?ajax=scan}': ['p(95)<300'],
    'http_req_duration{name:POST /pos/index.php (add_to_cart)}': ['p(95)<800'],
    'http_req_duration{name:POST /pos/checkout.php}': ['p(95)<1000'],
    'http_req_duration{name:GET /reports/sales.php}': ['p(95)<1500'],
  },
  summaryTrendStats,
  // Simpan cookie sesi antar putaran: kasir tidak login ulang untuk tiap pelanggan.
  noCookiesReset: true,
};

// Perkiraan kebutuhan stok (PCS) untuk seluruh skenario ini, dengan cadangan.
export const setup = setupStock(3000);
export const handleSummary = buildSummary('load');
