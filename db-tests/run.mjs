/**
 * Runner test database PARTAMBUS.
 *
 *   integrity/*.sql   Query yang MENCARI data rusak. 0 baris = lulus.
 *   constraints/*.sql Percobaan menyimpan data salah. Dijalankan di dalam
 *                     transaksi lalu di-ROLLBACK, jadi database tidak berubah.
 *                     expect: reject    -> statement terakhir harus ditolak database
 *                     expect: known-bug -> database (masih) menerima data salah;
 *                                          test lulus selama celah itu ada, dan
 *                                          gagal saat celah ditutup (penjaga regresi)
 *
 * Hasil: ringkasan di terminal + file Allure (epic "DB") di ALLURE_RESULTS_DIR.
 * Exit code 1 jika ada test yang gagal.
 */
import { randomUUID, createHash } from 'node:crypto';
import { mkdirSync, readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { env, exit, stdout } from 'node:process';
import mysql from 'mysql2/promise';

const config = {
  host: env.DB_HOST || '127.0.0.1',
  port: Number(env.DB_PORT || 3307),
  database: env.DB_NAME || 'partambus',
  user: env.DB_USER || 'partambus',
  password: env.DB_PASS || 'partambus',
};
const resultsDir = env.ALLURE_RESULTS_DIR || 'allure-results';
// Keterangan data yang diperiksa, mis. "setelah test API". Membedakan hasil di Allure.
const runLabel = env.DB_RUN_LABEL || 'lokal';

/** Baca header "-- key: value" dan isi SQL (dipecah per statement yang diakhiri ;). */
function parseSqlFile(path) {
  const text = readFileSync(path, 'utf8');
  const meta = {};
  for (const [, key, value] of text.matchAll(/^--\s*(\w+):\s*(.+)$/gm)) meta[key] = value.trim();
  const body = text
    .split('\n')
    .filter((line) => !line.trim().startsWith('--'))
    .join('\n');
  const statements = body
    .split(/;\s*$/m)
    .map((s) => s.trim())
    .filter(Boolean);
  return { meta, statements };
}

async function runIntegrity(conn, statements) {
  const [rows] = await conn.query(statements.join(';\n'));
  return rows.length === 0
    ? { status: 'passed' }
    : { status: 'failed', message: `${rows.length} baris data melanggar aturan`, rows };
}

async function runConstraint(conn, statements, expect) {
  await conn.beginTransaction();
  try {
    for (const [i, sql] of statements.entries()) {
      try {
        await conn.query(sql);
      } catch (error) {
        const isLast = i === statements.length - 1;
        if (!isLast) return { status: 'broken', message: `Persiapan gagal: ${error.message}` };
        return expect === 'reject'
          ? { status: 'passed', detail: `Ditolak: ${error.code} ${error.message}` }
          : { status: 'failed', message: `Celah sudah tertutup (database menolak: ${error.code}). Ubah menjadi "expect: reject".` };
      }
    }
    return expect === 'reject'
      ? { status: 'failed', message: 'Database MENERIMA data yang seharusnya ditolak.' }
      : { status: 'passed', detail: 'Celah masih ada: database menerima data ini (didokumentasikan sebagai known bug).' };
  } finally {
    await conn.rollback();
  }
}

function writeAllure({ name, suite, meta, statements, result, start, stop }) {
  const uuid = randomUUID();
  const fullName = `db-tests:${suite}:${runLabel}:${name}`;
  const attachments = [];
  const attach = (title, content, type = 'text/plain', ext = 'txt') => {
    const source = `${randomUUID()}-attachment.${ext}`;
    writeFileSync(join(resultsDir, source), content);
    attachments.push({ name: title, source, type });
  };
  attach('Query', `${statements.join(';\n\n')};`, 'text/plain', 'sql');
  if (result.rows) attach('Data yang melanggar (maks 50 baris)', JSON.stringify(result.rows.slice(0, 50), null, 2), 'application/json', 'json');

  const labels = [
    { name: 'epic', value: 'DB' },
    { name: 'suite', value: suite },
    { name: 'subSuite', value: runLabel },
    { name: 'severity', value: meta.severity || 'normal' },
    { name: 'framework', value: 'mysql2' },
  ];
  if (meta.expect === 'known-bug') labels.push({ name: 'tag', value: 'known-bug' });

  const test = {
    uuid,
    historyId: createHash('md5').update(fullName).digest('hex'),
    name,
    fullName,
    status: result.status,
    statusDetails: { message: result.message || result.detail || '' },
    stage: 'finished',
    description: meta.rule ? `Aturan: ${meta.rule}` : undefined,
    labels,
    attachments,
    start,
    stop,
  };
  writeFileSync(join(resultsDir, `${uuid}-result.json`), JSON.stringify(test));
}

// DB_SUITES=integrity -> hanya cek integritas (dipakai di CI setelah test UI).
const selected = (env.DB_SUITES || 'integrity,constraints').split(',');
const suites = [
  { dir: 'integrity', suite: 'Integritas data' },
  { dir: 'constraints', suite: 'Constraint database' },
].filter(({ dir }) => selected.includes(dir));

mkdirSync(resultsDir, { recursive: true });
// dateStrings: tampilkan DATETIME apa adanya dari database (tanpa konversi zona waktu).
const conn = await mysql.createConnection({ ...config, dateStrings: true }).catch((error) => {
  stdout.write(`Tidak bisa terhubung ke database ${config.host}:${config.port} (${error.code}).\n`);
  stdout.write('Pastikan aplikasi test menyala: docker compose up -d --wait (dari folder utama proyek).\n');
  exit(2);
});
const summary = { passed: 0, failed: 0, broken: 0 };

stdout.write(`Database: ${config.host}:${config.port}/${config.database} (${runLabel})\n`);
for (const { dir, suite } of suites) {
  stdout.write(`\n${suite}\n`);
  for (const file of readdirSync(dir).filter((f) => f.endsWith('.sql')).sort()) {
    const { meta, statements } = parseSqlFile(join(dir, file));
    const name = meta.title || file;
    const start = Date.now();
    let result;
    try {
      result = dir === 'integrity' ? await runIntegrity(conn, statements) : await runConstraint(conn, statements, meta.expect);
    } catch (error) {
      result = { status: 'broken', message: error.message };
    }
    const stop = Date.now();
    summary[result.status] += 1;
    const icon = { passed: 'PASS', failed: 'FAIL', broken: 'ERROR' }[result.status];
    stdout.write(`  ${icon.padEnd(5)} ${name}\n`);
    if (result.status !== 'passed') {
      stdout.write(`        ${result.message}\n`);
      if (result.rows) stdout.write(`${JSON.stringify(result.rows.slice(0, 5), null, 2).replace(/^/gm, '        ')}\n`);
    }
    writeAllure({ name, suite, meta, statements, result, start, stop });
  }
}
await conn.end();

stdout.write(`\n${summary.passed} lulus, ${summary.failed} gagal, ${summary.broken} error\n`);
exit(summary.failed + summary.broken > 0 ? 1 : 0);
