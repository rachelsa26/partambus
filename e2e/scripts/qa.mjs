/**
 * Satu perintah untuk seluruh QA lokal:  npm run qa
 *
 * 1. Hapus hasil & dashboard lama (supaya tidak tercampur run sebelumnya)
 * 2. Jalankan test API (Newman) dan test UI (Playwright)
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

for (const dir of ['allure-results', 'allure-report', '../api-tests/allure-results']) {
  rmSync(dir, { recursive: true, force: true });
}

const api = run('Test API (Newman)', 'npm test', '../api-tests');
const ui = run('Test UI (Playwright)', 'npx playwright test');
const report = run(
  'Membuat dashboard Allure',
  'npx allure generate allure-results ../api-tests/allure-results --output allure-report',
);

const label = (code) => (code === 0 ? 'LULUS' : 'ADA YANG GAGAL');
stdout.write(`\nRingkasan -> API: ${label(api)} | UI: ${label(ui)}\n`);

if (report !== 0) exit(report);
run('Membuka dashboard (tekan Ctrl+C untuk menutup)', 'npx allure open allure-report');
exit(api || ui);
