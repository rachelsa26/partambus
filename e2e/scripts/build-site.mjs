/**
 * Menyusun situs report yang diterbitkan ke GitHub Pages:
 *
 *   site/
 *   ├── index.html      halaman depan: ringkasan UI / API / DB / Performance + tombol ke semua report
 *   ├── allure/         dashboard Allure gabungan
 *   ├── playwright/     HTML report Playwright (trace, video, screenshot)
 *   ├── api/            report Newman (htmlextra)
 *   └── perf/           ringkasan JSON k6 (smoke, race)
 *
 * Dipakai di job "report" GitHub Actions. Semua lokasi bisa diganti lewat env:
 *   SITE_RESULTS     folder hasil Allure mentah (boleh beberapa, pisahkan dengan koma)
 *   SITE_ALLURE      folder dashboard Allure yang sudah dibuat
 *   SITE_PLAYWRIGHT  folder HTML report Playwright
 *   SITE_API         folder yang berisi api-report.html
 *   SITE_PERF        folder yang berisi *-summary.json dari k6
 *   SITE_BASELINE    file hasil load test resmi (perf-tests/load-baseline.json)
 *   SITE_OUT         folder tujuan
 */
import { cpSync, existsSync, mkdirSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { env, stdout } from 'node:process';

const cfg = {
  results: (env.SITE_RESULTS || 'all-allure-results').split(','),
  allure: env.SITE_ALLURE || 'allure-report',
  playwright: env.SITE_PLAYWRIGHT || 'playwright-report',
  api: env.SITE_API || 'api-report',
  perf: env.SITE_PERF || 'perf-report',
  baseline: env.SITE_BASELINE || '../perf-tests/load-baseline.json',
  out: env.SITE_OUT || 'site',
};

const readJson = (file) => {
  try {
    return JSON.parse(readFileSync(file, 'utf8'));
  } catch {
    return null;
  }
};

const esc = (value) =>
  String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

// ---------- 1. Hitung hasil per lapisan dari hasil Allure mentah ----------
// Test yang di-retry punya beberapa file dengan historyId sama: ambil yang terakhir.
function layerStats() {
  const latest = new Map();
  for (const dir of cfg.results) {
    if (!existsSync(dir)) continue;
    for (const file of readdirSync(dir).filter((f) => f.endsWith('-result.json'))) {
      const result = readJson(join(dir, file));
      if (!result) continue;
      const key = result.historyId || result.fullName || result.uuid;
      const previous = latest.get(key);
      if (!previous || (result.stop || 0) > (previous.stop || 0)) latest.set(key, result);
    }
  }
  const layers = {};
  for (const result of latest.values()) {
    const epic = result.labels?.find((l) => l.name === 'epic')?.value || 'Lainnya';
    layers[epic] ??= { total: 0, passed: 0, failed: 0, broken: 0, skipped: 0 };
    const bucket = ['passed', 'failed', 'broken', 'skipped'].includes(result.status) ? result.status : 'broken';
    layers[epic].total += 1;
    layers[epic][bucket] += 1;
  }
  return layers;
}

// ---------- 2. Salin report masing-masing tool ----------
function copyReports() {
  rmSync(cfg.out, { recursive: true, force: true });
  mkdirSync(cfg.out, { recursive: true });
  const copied = {};
  const copy = (from, to) => {
    if (!existsSync(from)) return false;
    cpSync(from, join(cfg.out, to), { recursive: true });
    return true;
  };
  copied.allure = copy(cfg.allure, 'allure');
  copied.playwright = copy(cfg.playwright, 'playwright');
  if (existsSync(join(cfg.api, 'api-report.html'))) {
    mkdirSync(join(cfg.out, 'api'), { recursive: true });
    cpSync(join(cfg.api, 'api-report.html'), join(cfg.out, 'api', 'index.html'));
    copied.api = true;
  }
  if (existsSync(cfg.perf)) {
    mkdirSync(join(cfg.out, 'perf'), { recursive: true });
    for (const file of readdirSync(cfg.perf).filter((f) => f.endsWith('-summary.json'))) {
      cpSync(join(cfg.perf, file), join(cfg.out, 'perf', file));
    }
    copied.perf = true;
  }
  return copied;
}

// ---------- 3. Angka performa dari ringkasan k6 ----------
function perfNumbers() {
  const metric = (summary, name, stat) => summary?.metrics?.[name]?.values?.[stat];
  const smoke = readJson(join(cfg.perf, 'smoke-summary.json'));
  const race = readJson(join(cfg.perf, 'race-summary.json'));
  return {
    smoke: smoke && {
      p95: metric(smoke, 'http_req_duration', 'p(95)'),
      failed: metric(smoke, 'http_req_failed', 'rate'),
      sales: metric(smoke, 'sales_created', 'count') ?? 0,
    },
    race: race && {
      sold: metric(race, 'race_sold', 'count') ?? 0,
      rejected: metric(race, 'race_rejected_out_of_stock', 'count') ?? 0,
      other: metric(race, 'race_other_errors', 'count') ?? 0,
      finalStock: metric(race, 'race_final_stock', 'value'),
      buyers: metric(race, 'vus_max', 'max'),
    },
    baseline: readJson(cfg.baseline),
  };
}

// ---------- 4. Halaman depan ----------
const LAYERS = [
  { epic: 'UI', title: 'UI end-to-end', tool: 'Playwright + TypeScript', link: 'playwright/', linkText: 'Playwright report' },
  { epic: 'API', title: 'API / HTTP', tool: 'Postman + Newman', link: 'api/', linkText: 'Newman report' },
  { epic: 'DB', title: 'Database', tool: 'SQL (MariaDB) + Node', link: 'allure/', linkText: 'Lihat di Allure' },
  { epic: 'Performance', title: 'Performa', tool: 'k6', link: 'allure/', linkText: 'Lihat di Allure' },
];

function layerCard(layer, stats, copied) {
  const s = stats[layer.epic] || { total: 0, passed: 0, failed: 0, broken: 0, skipped: 0 };
  const bad = s.failed + s.broken;
  const ran = s.total - s.skipped;
  const pct = ran > 0 ? Math.round((s.passed / ran) * 100) : 0;
  const state = s.total === 0 ? 'empty' : bad > 0 ? 'bad' : 'ok';
  const label = s.total === 0 ? 'Tidak ada data' : bad > 0 ? `${bad} gagal` : 'Semua lulus';
  const hasLink = layer.link === 'allure/' ? copied.allure : layer.link === 'api/' ? copied.api : copied.playwright;
  return `
      <article class="layer ${state}">
        <header>
          <h3>${esc(layer.title)}</h3>
          <span class="pill ${state}">${esc(label)}</span>
        </header>
        <p class="tool">${esc(layer.tool)}</p>
        <p class="count"><strong>${s.passed}</strong><span> / ${ran} lulus</span></p>
        <div class="bar" role="img" aria-label="${pct}% lulus"><span style="width:${pct}%"></span></div>
        <p class="meta">${s.skipped ? `${s.skipped} dilewati sengaja` : '&nbsp;'}</p>
        ${hasLink ? `<a class="more" href="${layer.link}">${esc(layer.linkText)} &rarr;</a>` : ''}
      </article>`;
}

function perfSection(perf) {
  const blocks = [];
  if (perf.race) {
    const r = perf.race;
    const ok = r.other === 0 && r.finalStock === 0;
    blocks.push(`
      <article class="panel">
        <h3>Race condition: checkout serentak</h3>
        <p class="lead">${esc(r.buyers ?? r.sold + r.rejected)} kasir menekan "Bayar" di detik yang sama untuk stok yang tinggal ${esc(r.sold)}.</p>
        <div class="stats">
          <div><strong>${esc(r.sold)}</strong><span>terjual</span></div>
          <div><strong>${esc(r.rejected)}</strong><span>ditolak (stok habis)</span></div>
          <div><strong>${esc(r.finalStock ?? '-')}</strong><span>stok akhir</span></div>
          <div><strong>${esc(r.other)}</strong><span>error lain</span></div>
        </div>
        <p class="verdict ${ok ? 'ok' : 'bad'}">${ok ? 'Tidak ada overselling: stok tidak pernah minus.' : 'Periksa: ada hasil yang tidak sesuai harapan.'}</p>
      </article>`);
  }
  const b = perf.baseline;
  if (b?.endpoints) {
    const rows = b.endpoints
      .map(
        (e) =>
          `<tr><td>${esc(e.name)}</td><td>${esc(e.median)} ms</td><td><strong>${esc(e.p95)} ms</strong></td><td>&lt; ${esc(e.target)} ms</td></tr>`,
      )
      .join('');
    blocks.push(`
      <article class="panel">
        <h3>Load test: ${esc(b.peakUsers)}, ${esc(b.duration)}</h3>
        <p class="lead">${esc(b.requests.toLocaleString('id-ID'))} request, ${esc((b.errorRate * 100).toFixed(0))}% error, ${esc(b.salesCreated)} transaksi tersimpan. ${esc(b.thresholdsPassed)} dari ${esc(b.thresholdsTotal)} target tercapai.</p>
        <div class="table-wrap"><table>
          <thead><tr><th>Endpoint</th><th>Median</th><th>p95</th><th>Target p95</th></tr></thead>
          <tbody>${rows}</tbody>
        </table></div>
        <p class="note">${esc(b.environment)}, ${esc(b.date)}. Load test tidak dijalankan di CI karena mesin GitHub dipakai bersama sehingga angkanya tidak konsisten.</p>
      </article>`);
  }
  return blocks.join('');
}

function page(stats, copied, perf) {
  const all = Object.values(stats).reduce(
    (a, s) => ({ total: a.total + s.total, passed: a.passed + s.passed, bad: a.bad + s.failed + s.broken }),
    { total: 0, passed: 0, bad: 0 },
  );
  const repo = env.GITHUB_REPOSITORY || 'rachelsa26/partambus';
  const server = env.GITHUB_SERVER_URL || 'https://github.com';
  const sha = env.GITHUB_SHA ? env.GITHUB_SHA.slice(0, 7) : null;
  const runUrl = env.GITHUB_RUN_ID ? `${server}/${repo}/actions/runs/${env.GITHUB_RUN_ID}` : null;
  const when = new Date().toLocaleString('id-ID', { timeZone: 'Asia/Jakarta', dateStyle: 'long', timeStyle: 'short' });
  const overall = all.total === 0 ? 'empty' : all.bad > 0 ? 'bad' : 'ok';
  const overallText = all.total === 0 ? 'Belum ada hasil' : all.bad > 0 ? `${all.bad} test gagal` : `${all.passed} test lulus`;

  return `<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PARTAMBUS QA Report</title>
<meta name="description" content="Hasil pengujian otomatis PARTAMBUS: UI, API, database, dan performa.">
<style>
  :root {
    --bg: #f6f7f9; --surface: #ffffff; --text: #16181d; --muted: #5d6470; --line: #e3e6eb;
    --accent: #3b5bdb; --accent-text: #ffffff; --ok: #1f8a4c; --ok-soft: #e3f4ea; --bad: #c92a2a; --bad-soft: #fdeaea;
    --empty: #8a919c; --empty-soft: #eef0f3; --bar: #e8ebef;
  }
  @media (prefers-color-scheme: dark) {
    :root {
      --bg: #111317; --surface: #1a1d23; --text: #e9ebef; --muted: #a0a7b3; --line: #2b3038;
      --accent: #7d95f5; --accent-text: #0d1020; --ok: #5ccf8a; --ok-soft: #1c3226; --bad: #ff8787; --bad-soft: #3a1f22;
      --empty: #8a919c; --empty-soft: #262a31; --bar: #2b3038;
    }
  }
  * { box-sizing: border-box; }
  body { margin: 0; background: var(--bg); color: var(--text); font: 16px/1.55 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
  main { max-width: 1040px; margin: 0 auto; padding: 40px 16px 56px; }
  a { color: var(--accent); }
  h1 { font-size: clamp(28px, 5vw, 40px); line-height: 1.15; margin: 0 0 8px; letter-spacing: -0.02em; }
  h2 { font-size: 20px; margin: 44px 0 14px; }
  h3 { font-size: 17px; margin: 0; }
  .intro { color: var(--muted); max-width: 680px; margin: 0 0 18px; }
  .status { display: flex; flex-wrap: wrap; gap: 8px 16px; align-items: center; color: var(--muted); font-size: 14px; }
  .pill { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 13px; font-weight: 600; white-space: nowrap; }
  .pill.ok { background: var(--ok-soft); color: var(--ok); }
  .pill.bad { background: var(--bad-soft); color: var(--bad); }
  .pill.empty { background: var(--empty-soft); color: var(--empty); }
  .status .pill { font-size: 14px; padding: 4px 12px; }
  .actions { display: flex; flex-wrap: wrap; gap: 10px; margin: 26px 0 0; }
  .btn { display: inline-block; padding: 10px 16px; border-radius: 8px; border: 1px solid var(--line); background: var(--surface); color: var(--text); text-decoration: none; font-weight: 600; font-size: 15px; }
  .btn.primary { background: var(--accent); border-color: var(--accent); color: var(--accent-text); }
  .btn:hover { filter: brightness(0.96); }
  .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; }
  .layer, .panel { background: var(--surface); border: 1px solid var(--line); border-radius: 12px; padding: 18px; }
  .layer header { display: flex; flex-direction: column; align-items: flex-start; gap: 6px; }
  .layer .tool { color: var(--muted); font-size: 14px; margin: 2px 0 14px; }
  .layer .count { margin: 0 0 8px; }
  .layer .count strong { font-size: 30px; font-variant-numeric: tabular-nums; }
  .layer .count span { color: var(--muted); }
  .bar { height: 6px; background: var(--bar); border-radius: 999px; overflow: hidden; }
  .bar span { display: block; height: 100%; background: var(--ok); }
  .layer.bad .bar span { background: var(--bad); }
  .layer .meta { color: var(--muted); font-size: 13px; margin: 8px 0 6px; }
  .more { font-size: 14px; font-weight: 600; text-decoration: none; }
  .perf { display: grid; grid-template-columns: minmax(0, 1fr); gap: 14px; }
  .panel, .layer { min-width: 0; }
  .lead { color: var(--muted); margin: 6px 0 14px; }
  .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 10px; }
  .stats div { background: var(--bg); border-radius: 8px; padding: 10px 12px; }
  .stats strong { display: block; font-size: 24px; font-variant-numeric: tabular-nums; }
  .stats span { color: var(--muted); font-size: 13px; }
  .verdict { font-weight: 600; margin: 14px 0 0; }
  .verdict.ok { color: var(--ok); } .verdict.bad { color: var(--bad); }
  .table-wrap { overflow-x: auto; }
  table { width: 100%; border-collapse: collapse; font-size: 14px; font-variant-numeric: tabular-nums; }
  th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid var(--line); white-space: nowrap; }
  th { color: var(--muted); font-weight: 600; }
  .note { color: var(--muted); font-size: 13px; margin: 12px 0 0; }
  footer { margin-top: 48px; padding-top: 18px; border-top: 1px solid var(--line); color: var(--muted); font-size: 14px; display: flex; flex-wrap: wrap; gap: 6px 18px; }
</style>
</head>
<body>
<main>
  <h1>PARTAMBUS QA Report</h1>
  <p class="intro">Hasil pengujian otomatis aplikasi kasir (POS) PARTAMBUS: tampilan, API, database, dan performa. Semua test dijalankan ulang oleh GitHub Actions di setiap push dan setiap malam, pada aplikasi yang dibangun dari nol di Docker.</p>
  <div class="status">
    <span class="pill ${overall}">${esc(overallText)}</span>
    <span>Run terakhir: ${esc(when)} WIB</span>
    ${sha ? `<span>Commit <a href="${esc(`${server}/${repo}/commit/${env.GITHUB_SHA}`)}">${esc(sha)}</a></span>` : ''}
    ${runUrl ? `<a href="${esc(runUrl)}">Log GitHub Actions</a>` : ''}
  </div>
  <div class="actions">
    ${copied.allure ? '<a class="btn primary" href="allure/">Buka dashboard Allure</a>' : ''}
    ${copied.playwright ? '<a class="btn" href="playwright/">Playwright report</a>' : ''}
    ${copied.api ? '<a class="btn" href="api/">Newman report</a>' : ''}
    <a class="btn" href="${esc(`${server}/${repo}`)}">Kode di GitHub</a>
  </div>

  <h2>Hasil per lapisan</h2>
  <section class="grid">${LAYERS.map((l) => layerCard(l, stats, copied)).join('')}
  </section>

  <h2>Performa</h2>
  <section class="perf">${perfSection(perf)}
  </section>

  <footer>
    <span>Elsa Rachel Dementieva</span>
    <a href="https://github.com/rachelsa26">github.com/rachelsa26</a>
  </footer>
</main>
</body>
</html>
`;
}

const stats = layerStats();
const copied = copyReports();
writeFileSync(join(cfg.out, 'index.html'), page(stats, copied, perfNumbers()));
stdout.write(`Situs report dibuat di ${cfg.out}/ -> ${JSON.stringify(stats)}\n`);
