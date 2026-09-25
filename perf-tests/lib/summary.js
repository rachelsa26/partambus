// handleSummary: dipanggil k6 sekali di akhir test. Menghasilkan:
//   1. ringkasan singkat di terminal (bahasa Indonesia)
//   2. reports/<nama>-summary.json   -> data mentah semua metrik
//   3. allure-results/*.json         -> tiap threshold jadi satu "test" di
//      dashboard Allure gabungan (epic "Performance"), bersebelahan dengan UI/API/DB
import { md5 } from 'k6/crypto';

const fmt = (metric, value) => {
  if (value === undefined || value === null) return '-';
  if (metric.type === 'rate') return `${(value * 100).toFixed(2)}%`;
  if (metric.contains === 'time') return `${value.toFixed(0)} ms`;
  return Number.isInteger(value) ? String(value) : value.toFixed(2);
};

// "p(95)<800" -> "p(95)"; "rate<0.01" -> "rate"
const statOf = (expression) => expression.split(/[<>=!]/)[0].trim();

function thresholdResults(data) {
  const results = [];
  for (const [name, metric] of Object.entries(data.metrics)) {
    for (const [expression, outcome] of Object.entries(metric.thresholds || {})) {
      const stat = statOf(expression);
      // k6 menganggap threshold "lulus" kalau metriknya tidak punya data sama sekali
      // (misalnya test berhenti di setup). Itu harus dilaporkan, bukan disembunyikan.
      const v = metric.values;
      const empty =
        metric.type === 'rate' ? v.passes + v.fails === 0 : metric.type === 'trend' ? v.max === 0 && v.min === 0 : false;
      results.push({
        name,
        expression,
        ok: outcome.ok && !empty,
        empty,
        actual: empty ? 'tidak ada data' : fmt(metric, v[stat]),
        metric,
      });
    }
  }
  return results.sort((a, b) => a.name.localeCompare(b.name));
}

function textReport(title, data, results) {
  const m = data.metrics;
  const line = (label, value) => `  ${label.padEnd(30)} ${value}\n`;
  let out = `\n===== PARTAMBUS - tes performa: ${title} =====\n`;
  out += line('Total request', m.http_reqs ? `${m.http_reqs.values.count} (${m.http_reqs.values.rate.toFixed(1)}/detik)` : '-');
  out += line('Request gagal', m.http_req_failed ? fmt(m.http_req_failed, m.http_req_failed.values.rate) : '-');
  out += line(
    'Waktu respons median / p95',
    m.http_req_duration
      ? `${fmt(m.http_req_duration, m.http_req_duration.values.med)} / ${fmt(m.http_req_duration, m.http_req_duration.values['p(95)'])}`
      : '-',
  );
  if (m.sales_created) out += line('Transaksi berhasil dibuat', m.sales_created.values.count);
  if (m.checks) out += line('Check lulus', fmt(m.checks, m.checks.values.rate));
  if (m.vus_max) out += line('Pengguna virtual (maks)', m.vus_max.values.max);
  const failedChecks = [];
  const walk = (group) => {
    for (const c of group.checks || [])
      if (c.fails > 0) failedChecks.push(`${c.name}: ${c.fails} gagal dari ${c.passes + c.fails}`);
    for (const g of group.groups || []) walk(g);
  };
  walk(data.root_group);
  if (failedChecks.length) out += `\n  Check yang gagal:\n${failedChecks.map((c) => `    - ${c}\n`).join('')}`;
  out += '\n  Threshold (target):\n';
  for (const r of results) {
    out += `  ${r.empty ? 'KOSONG' : r.ok ? 'LULUS' : 'GAGAL'}  ${r.name}  ${r.expression}  (hasil: ${r.actual})\n`;
  }
  const failed = results.filter((r) => !r.ok).length;
  out += `\n  ${failed === 0 ? 'Semua target tercapai.' : `${failed} target TIDAK tercapai.`}\n\n`;
  return out;
}

function allureFiles(title, data, results, started, stopped) {
  const files = {};
  const summarySource = `${md5(`${title}-${stopped}-summary`, 'hex')}-attachment.json`;
  files[`allure-results/${summarySource}`] = JSON.stringify(data.metrics, null, 2);

  for (const r of results) {
    const fullName = `perf-tests:${title}:${r.name}:${r.expression}`;
    const uuid = md5(`${fullName}-${stopped}`, 'hex');
    files[`allure-results/${uuid}-result.json`] = JSON.stringify({
      uuid,
      historyId: md5(fullName, 'hex'),
      name: `${r.name} ${r.expression}`,
      fullName,
      status: r.empty ? 'broken' : r.ok ? 'passed' : 'failed',
      statusDetails: { message: `Target: ${r.expression}. Hasil: ${r.actual}.` },
      stage: 'finished',
      description: `Threshold k6 pada metrik "${r.name}" untuk skenario ${title}.`,
      labels: [
        { name: 'epic', value: 'Performance' },
        { name: 'suite', value: title },
        { name: 'subSuite', value: r.name.includes('{') ? 'Per endpoint' : 'Keseluruhan' },
        { name: 'framework', value: 'k6' },
        {
          name: 'severity',
          value: r.name.startsWith('http_req_failed') || r.name === 'checkout_success' ? 'critical' : 'normal',
        },
      ],
      attachments: [{ name: 'Semua metrik k6 (JSON)', source: summarySource, type: 'application/json' }],
      start: started,
      stop: stopped,
    });
  }
  return files;
}

export function buildSummary(title) {
  return function handleSummary(data) {
    const stopped = Date.now();
    const started = stopped - Math.round(data.state.testRunDurationMs);
    const results = thresholdResults(data);
    return {
      stdout: textReport(title, data, results),
      [`reports/${title}-summary.json`]: JSON.stringify(data, null, 2),
      ...allureFiles(title, data, results, started, stopped),
    };
  };
}

// Statistik yang dihitung untuk setiap metrik waktu (dipakai threshold p(99)).
export const summaryTrendStats = ['avg', 'min', 'med', 'max', 'p(90)', 'p(95)', 'p(99)'];
