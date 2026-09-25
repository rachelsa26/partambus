import mysql, { type Pool, type RowDataPacket } from 'mysql2/promise';
import { env } from '../config/env';

/**
 * Akses database langsung, khusus untuk:
 *  1. Assertion "di balik layar" (stok berkurang, ledger tercatat, dll.)
 *  2. Mengatur kondisi global yang tidak bisa diisolasi per test
 *     (contoh: hanya boleh ada satu sesi kas terbuka).
 *
 * Test TIDAK boleh memakai ini untuk melewati alur yang sedang diuji.
 */
export class Database {
  private constructor(private readonly pool: Pool) {}

  static connect(): Database {
    return new Database(mysql.createPool({ ...env.db, connectionLimit: 2, timezone: '+07:00' }));
  }

  async close(): Promise<void> {
    await this.pool.end();
  }

  private async one<T>(sql: string, params: unknown[] = []): Promise<T | undefined> {
    const [rows] = await this.pool.query<RowDataPacket[]>(sql, params);
    return rows[0] as T | undefined;
  }

  async productStock(code: string): Promise<number> {
    const row = await this.one<{ current_stock_base: number }>(
      'SELECT current_stock_base FROM products WHERE code_normalized = UPPER(?)',
      [code],
    );
    if (!row) throw new Error(`Produk ${code} tidak ada di database`);
    return Number(row.current_stock_base);
  }

  async productExists(code: string): Promise<boolean> {
    const row = await this.one('SELECT id FROM products WHERE code_normalized = UPPER(?)', [code]);
    return row !== undefined;
  }

  async sale(saleNumber: string) {
    return this.one<{ id: number; status: string; total: string; cashier_id: number }>(
      'SELECT id, status, total, cashier_id FROM sales WHERE sale_number = ?',
      [saleNumber],
    );
  }

  async payment(saleNumber: string) {
    return this.one<{ method: string; amount: string; cash_received: string | null; change_amount: string | null }>(
      `SELECT p.method, p.amount, p.cash_received, p.change_amount
         FROM payments p JOIN sales s ON s.id = p.sale_id
        WHERE s.sale_number = ?`,
      [saleNumber],
    );
  }

  /** Ledger harus mencatat pengurangan stok untuk penjualan ini (append-only). */
  async saleStockMovements(saleNumber: string) {
    const [rows] = await this.pool.query<RowDataPacket[]>(
      `SELECT sm.qty_delta_base, sm.balance_after_base, p.code
         FROM stock_movements sm
         JOIN sales s ON s.id = sm.reference_id AND sm.reference_type = 'sale'
         JOIN products p ON p.id = sm.product_id
        WHERE s.sale_number = ?`,
      [saleNumber],
    );
    return rows as { qty_delta_base: number; balance_after_base: number; code: string }[];
  }

  /** Audit log pembuatan produk milik kode tertentu (bukan sekadar audit terakhir). */
  async productCreatedAudit(code: string) {
    return this.one<{ entity_id: number; after_value: string }>(
      `SELECT entity_id, after_value FROM audit_logs
        WHERE action = 'product_created' AND JSON_VALUE(after_value, '$.code') = ?
        ORDER BY id DESC LIMIT 1`,
      [code],
    );
  }

  async supplierExists(name: string): Promise<boolean> {
    const row = await this.one('SELECT id FROM suppliers WHERE name = ?', [name]);
    return row !== undefined;
  }

  /**
   * Tutup paksa semua sesi kas yang masih terbuka. Aplikasi hanya
   * mengizinkan satu sesi aktif, jadi test kas butuh titik awal yang pasti.
   * Kas yang diharapkan dihitung sama seperti aplikasi (kas awal + semua
   * pergerakan kas), supaya data tetap lolos test integritas database.
   */
  async closeAllCashSessions(): Promise<void> {
    await this.pool.query(
      `UPDATE cash_sessions cs
          SET cs.status = 'closed', cs.closed_at = NOW(), cs.closed_by = cs.opened_by,
              cs.expected_cash = cs.opening_cash
                + (SELECT COALESCE(SUM(cm.amount), 0) FROM cash_movements cm WHERE cm.cash_session_id = cs.id),
              cs.actual_cash = cs.expected_cash, cs.difference = 0,
              cs.note = 'Ditutup otomatis oleh test setup'
        WHERE cs.status = 'open'`,
    );
  }

  async openCashSession() {
    return this.one<{ id: number; opening_cash: string }>(
      "SELECT id, opening_cash FROM cash_sessions WHERE status = 'open' ORDER BY id DESC LIMIT 1",
    );
  }

  async lastClosedCashSession() {
    return this.one<{ expected_cash: string; actual_cash: string; difference: string; note: string | null }>(
      "SELECT expected_cash, actual_cash, difference, note FROM cash_sessions WHERE status = 'closed' ORDER BY id DESC LIMIT 1",
    );
  }
}
