// STRESS TEST: menaikkan jumlah kasir melewati batas wajar (sampai 50) untuk
// mencari titik di mana aplikasi mulai melambat atau error.
// Target di sini lebih longgar: yang dicari adalah "kapan mulai rusak",
// bukan "apakah secepat load test". Test dihentikan otomatis kalau error > 5%.
// Durasi sekitar 7 menit. Jangan jalankan ke server produksi!
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
        { duration: '1m', target: 10 },
        { duration: '1m', target: 25 },
        { duration: '2m', target: 50 },
        { duration: '2m', target: 50 },
        { duration: '1m', target: 0 },
      ],
      gracefulRampDown: '30s',
    },
    owner: { executor: 'constant-vus', exec: 'owner', vus: 3, duration: '7m' },
  },
  thresholds: {
    http_req_failed: [{ threshold: 'rate<0.05', abortOnFail: true, delayAbortEval: '30s' }],
    checkout_success: ['rate>0.95'],
    http_req_duration: ['p(95)<3000'],
    'http_req_duration{name:POST /pos/checkout.php}': ['p(95)<3000'],
  },
  summaryTrendStats,
  // Simpan cookie sesi antar putaran: kasir tidak login ulang untuk tiap pelanggan.
  noCookiesReset: true,
};

// Perkiraan kebutuhan stok (PCS) untuk seluruh skenario ini, dengan cadangan.
export const setup = setupStock(15000);
export const handleSummary = buildSummary('stress');
