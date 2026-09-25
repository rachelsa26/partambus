// SMOKE TEST: 1 kasir + 1 owner, beberapa putaran saja (~30 detik).
// Tujuan: memastikan skrip dan aplikasi berfungsi SEBELUM menjalankan test beban.
// Semua check harus lulus 100%.
import { buildSummary, summaryTrendStats } from '../lib/summary.js';
export { kasir, owner } from './_shared.js';
import { setupStock } from './_shared.js';

export const options = {
  scenarios: {
    kasir: { executor: 'per-vu-iterations', exec: 'kasir', vus: 1, iterations: 3 },
    owner: { executor: 'per-vu-iterations', exec: 'owner', vus: 1, iterations: 1 },
  },
  thresholds: {
    checks: ['rate==1'],
    http_req_failed: ['rate==0'],
    http_req_duration: ['p(95)<1000'],
    checkout_success: ['rate==1'],
  },
  summaryTrendStats,
  // Simpan cookie sesi antar putaran: kasir tidak login ulang untuk tiap pelanggan.
  noCookiesReset: true,
};

// Perkiraan kebutuhan stok (PCS) untuk seluruh skenario ini, dengan cadangan.
export const setup = setupStock(100);
export const handleSummary = buildSummary('smoke');
