/**
 * Satu perintah untuk seluruh QA lokal:  npm run qa
 *
 * 1. Hapus hasil & dashboard lama (supaya tidak tercampur run sebelumnya)
 * 2. Jalankan test API (Newman), test UI (Playwright), smoke + race test k6,
 *    lalu test database (SQL) yang memeriksa semua data hasil test-test tadi.
 *    Smoke k6 dilewati kalau k6 belum terpasang. Load/stress test TIDAK ikut
 *    (butuh 5-7 menit); jalankan manual dari folder perf-tests.
 * 3. Buat dashboard Allure gabungan, lalu buka di browser
 *
 * Dashboard tetap dibuat walau ada test yang gagal, karena justru saat
 * itulah dashboard paling dibutuhkan. Butuh aplikasi test menyala di
 * http://localhost:8080 (docker compose up -d --wait dari folder utama).
 */
import { spawnSync } from 'node:child_process';
import { rmSync } from 'node:fs';
import { env, exit, stdout } from 'node:process';

const run = (title, command, cwd = '.') => {
  stdout.write(`\n=== ${title} ===\n`);
  // PLAYWRIGHT_HTML_OPEN=never: jangan buka HTML report Playwright otomatis,
  // karena itu akan menahan proses sampai Ctrl+C.
  const result = spawnSync(command, { cwd, stdio: 'inherit', shell: true, env: { ...env, PLAYWRIGHT_HTML_OPEN: 'never' } });
  return result.status ?? 1;
};

for (const dir of [
  'allure-results',
  'allure-report',
  '../api-tests/allure-results',
  '../db-tests/allure-results',
  '../perf-tests/allure-results',
]) {
  rmSync(dir, { recursive: true, force: true });
}

const api = run('Test API (Newman)', 'npm test', '../api-tests');
const ui = run('Test UI (Playwright)', 'npx playwright test');
const hasK6 = spawnSync('k6 version', { shell: true, stdio: 'ignore' }).status === 0;
let perf = 0;
if (hasK6) {
  const smoke = run('Smoke test performa (k6)', 'npm run smoke', '../perf-tests');
  const race = run('Race condition: checkout serentak (k6)', 'npm run race', '../perf-tests');
  perf = smoke || race;
} else {
  stdout.write('\n=== Tes performa (k6) ===\nk6 belum terpasang, dilewati. Pasang dengan: winget install k6 --source winget\n');
}
const db = run('Test database (SQL)', 'npm test', '../db-tests');
const report = run('Membuat dashboard Allure', 'npm run allure:report');

const label = (code) => (code === 0 ? 'LULUS' : 'ADA YANG GAGAL');
const perfLabel = hasK6 ? label(perf) : 'DILEWATI';
stdout.write(`\nRingkasan -> API: ${label(api)} | UI: ${label(ui)} | Performa: ${perfLabel} | DB: ${label(db)}\n`);

if (report !== 0) exit(report);
run('Membuka dashboard (tekan Ctrl+C untuk menutup)', 'npx allure open allure-report');
exit(api || ui || perf || db);
