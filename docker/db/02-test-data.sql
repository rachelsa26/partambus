-- =====================================================================
-- PARTAMBUS - data uji untuk lingkungan Docker / CI
-- Dijalankan otomatis SETELAH 01-schema.sql oleh image MariaDB.
-- JANGAN dipakai di production: password di sini sengaja publik.
--
--   qa_owner   / QaOwner123!   (role owner)
--   qa_kasir   / QaKasir123!   (role cashier)
--   qa_nonaktif/ QaKasir123!   (role cashier, active = 0)
-- =====================================================================

INSERT INTO users (username, password_hash, full_name, role, active) VALUES
  ('qa_owner',    '$2y$12$ozyXo6tWtYfxU6a5o9Cj2eF3qnonccvBS7L1Q8vWeJjpz7DuJ1VjO', 'QA Owner',    'owner',   1),
  ('qa_kasir',    '$2y$12$5a8tan.XYp7F4hHjV3qGfOdn37ibyIMGazH8kM0pguhbdV.YrO/MS', 'QA Kasir',    'cashier', 1),
  ('qa_nonaktif', '$2y$12$5a8tan.XYp7F4hHjV3qGfOdn37ibyIMGazH8kM0pguhbdV.YrO/MS', 'QA Nonaktif', 'cashier', 0);

SET @owner_id := (SELECT id FROM users WHERE username = 'qa_owner');

-- Produk dasar dengan stok & WAC yang sudah diketahui, supaya test POS
-- tidak perlu melewati alur pembelian dulu.
INSERT INTO products (code, code_normalized, barcode, name, base_unit_name, low_stock_threshold_base,
                      current_stock_base, current_wac, active, created_by, updated_by) VALUES
  ('QA-KOPI',  'QA-KOPI',  '8990000000011', 'QA Kopi Sachet',   'PCS', 10, 500, 1500.0000, 1, @owner_id, @owner_id),
  ('QA-ROKOK', 'QA-ROKOK', '8990000000028', 'QA Rokok Filter',  'PCS', 20, 200, 2000.0000, 1, @owner_id, @owner_id),
  ('QA-HABIS', 'QA-HABIS', NULL,            'QA Stok Kosong',   'PCS', 5,  0,   NULL,      1, @owner_id, @owner_id);

SET @kopi  := (SELECT id FROM products WHERE code = 'QA-KOPI');
SET @rokok := (SELECT id FROM products WHERE code = 'QA-ROKOK');
SET @habis := (SELECT id FROM products WHERE code = 'QA-HABIS');

INSERT INTO product_units (product_id, unit_name, conversion_factor, is_base, can_sell, can_purchase, selling_price, active) VALUES
  (@kopi,  'PCS',  1,  1, 1, 1, 2000.00,  1),
  (@rokok, 'PCS',  1,  1, 1, 1, 2500.00,  1),
  (@rokok, 'SLOP', 10, 0, 1, 1, 24000.00, 1),
  (@habis, 'PCS',  1,  1, 1, 1, 5000.00,  1);

-- Saldo stok harus konsisten dengan ledger stock_movements.
INSERT INTO stock_movements (product_id, movement_type, qty_delta_base, balance_after_base, cost_per_base,
                             reference_type, reference_id, actor_user_id, reason) VALUES
  (@kopi,  'initial_stock', 500, 500, 1500.0000, NULL, NULL, @owner_id, 'Seed data uji'),
  (@rokok, 'initial_stock', 200, 200, 2000.0000, NULL, NULL, @owner_id, 'Seed data uji');

INSERT INTO suppliers (name, contact_person, phone, active) VALUES
  ('QA Supplier Utama', 'Budi', '081200000000', 1);

-- Tandai backup "baru saja" dibuat supaya notifikasi backup tidak
-- mengganggu assertion jumlah notifikasi di dashboard.
INSERT INTO backup_history (file_name, created_by, status, note)
VALUES ('seed-placeholder.sql', @owner_id, 'success', 'Seed data uji');
