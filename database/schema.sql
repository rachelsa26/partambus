-- =====================================================================
-- PARTAMBUS - Local Offline POS & Retail Management
-- Database schema (Phase 0 deliverable, PRD v1.0)
-- Conventions:
--   - All money/cost columns are DECIMAL (fixed-point), never FLOAT.
--   - Stock quantities are always integer base units (BR-011).
--   - Completed business events (sales, confirmed purchases) are never
--     UPDATEd by the application after completion; corrections happen
--     through new rows (void, reversal, adjustment).
-- =====================================================================

CREATE DATABASE IF NOT EXISTS partambus
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE partambus;

-- ---------------------------------------------------------------------
-- users
-- ---------------------------------------------------------------------
CREATE TABLE users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(50)  NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  full_name     VARCHAR(100) NOT NULL,
  role          ENUM('owner','cashier') NOT NULL,
  active        TINYINT(1) NOT NULL DEFAULT 1,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- products  (BR-001..BR-010)
-- ---------------------------------------------------------------------
CREATE TABLE products (
  id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code                     VARCHAR(50)  NOT NULL,
  code_normalized          VARCHAR(50)  NOT NULL, -- UPPER(TRIM(code)), enforces case-insensitive uniqueness
  barcode                  VARCHAR(64)  NULL,
  name                     VARCHAR(150) NOT NULL,
  base_unit_name           VARCHAR(30)  NOT NULL,
  low_stock_threshold_base INT NOT NULL DEFAULT 0,
  current_stock_base       INT NOT NULL DEFAULT 0,     -- source of truth balance, base units (BR-011)
  current_wac              DECIMAL(15,4) NULL,         -- NULL = unknown cost (OQ-C01)
  active                   TINYINT(1) NOT NULL DEFAULT 1,
  created_by               INT UNSIGNED NULL,
  updated_by               INT UNSIGNED NULL,
  created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_products_code_normalized (code_normalized),
  UNIQUE KEY uq_products_barcode (barcode),
  CONSTRAINT fk_products_created_by FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_products_updated_by FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- product_units  (F-UNIT-001, BR-005..BR-010)
-- ---------------------------------------------------------------------
CREATE TABLE product_units (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id         INT UNSIGNED NOT NULL,
  unit_name          VARCHAR(30) NOT NULL,
  conversion_factor  INT NOT NULL,           -- base unit = 1 (BR-006); others positive int (BR-007)
  is_base            TINYINT(1) NOT NULL DEFAULT 0,
  can_sell           TINYINT(1) NOT NULL DEFAULT 0,
  can_purchase       TINYINT(1) NOT NULL DEFAULT 0,
  selling_price      DECIMAL(15,2) NULL,     -- required when can_sell = 1 (BR-009/BR-010)
  active             TINYINT(1) NOT NULL DEFAULT 1,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_product_units_name (product_id, unit_name),
  CONSTRAINT fk_product_units_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT chk_product_units_conversion_positive CHECK (conversion_factor > 0)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- units  (reference list for the unit-name dropdown; NOT a foreign key
-- target for products/product_units.unit_name, which stay free-text so
-- legacy/imported data is never blocked — this table only powers the
-- dropdown UI and import matching, and grows via the "Lainnya" flow)
-- ---------------------------------------------------------------------
CREATE TABLE units (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code             VARCHAR(30) NOT NULL,
  code_normalized  VARCHAR(30) NOT NULL, -- UPPER(TRIM(code))
  description      VARCHAR(100) NULL,
  sort_order       INT NOT NULL DEFAULT 0,
  active           TINYINT(1) NOT NULL DEFAULT 1,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_units_code_normalized (code_normalized)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- suppliers  (F-SUP-001)
-- ---------------------------------------------------------------------
CREATE TABLE suppliers (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(150) NOT NULL,
  contact_person  VARCHAR(100) NULL,
  phone           VARCHAR(30)  NULL,
  note            TEXT NULL,
  active          TINYINT(1) NOT NULL DEFAULT 1,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- purchases / purchase_items  (F-PUR-001, Phase 3)
-- ---------------------------------------------------------------------
CREATE TABLE purchases (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purchase_number  VARCHAR(30) NOT NULL,
  supplier_id      INT UNSIGNED NOT NULL,
  status           ENUM('draft','confirmed','cancelled_draft') NOT NULL DEFAULT 'draft',
  payment_source   ENUM('cash_drawer','external') NULL, -- chosen at confirm time (OQ-C07)
  total_amount     DECIMAL(15,2) NOT NULL DEFAULT 0,
  notes            TEXT NULL,
  created_by       INT UNSIGNED NOT NULL,
  confirmed_by     INT UNSIGNED NULL,
  confirmed_at     DATETIME NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_purchases_number (purchase_number),
  CONSTRAINT fk_purchases_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
  CONSTRAINT fk_purchases_created_by FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_purchases_confirmed_by FOREIGN KEY (confirmed_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE purchase_items (
  id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purchase_id                 INT UNSIGNED NOT NULL,
  product_id                  INT UNSIGNED NOT NULL,
  unit_id                     INT UNSIGNED NOT NULL,
  unit_name_snapshot          VARCHAR(30) NOT NULL,
  conversion_factor_snapshot  INT NOT NULL,
  qty_unit                    INT NOT NULL,
  qty_base                    INT NOT NULL,
  unit_cost                   DECIMAL(15,2) NOT NULL,  -- cost per purchase unit
  cost_per_base                DECIMAL(15,4) NOT NULL, -- unit_cost / conversion_factor_snapshot
  subtotal                    DECIMAL(15,2) NOT NULL,
  created_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_purchase_items_purchase FOREIGN KEY (purchase_id) REFERENCES purchases(id),
  CONSTRAINT fk_purchase_items_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_purchase_items_unit FOREIGN KEY (unit_id) REFERENCES product_units(id)
) ENGINE=InnoDB;

CREATE TABLE purchase_number_counters (
  counter_date   DATE NOT NULL PRIMARY KEY,
  last_sequence  INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- sales / sale_items / payments  (F-SALE-001, F-PAY-001, Phase 4)
-- ---------------------------------------------------------------------
CREATE TABLE sales (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sale_number      VARCHAR(30) NOT NULL,       -- SL-YYYYMMDD-0001 (OQ-C06)
  request_token    VARCHAR(64) NOT NULL,       -- idempotency key (BR-024, AC-SALE-004)
  status           ENUM('completed','void') NOT NULL DEFAULT 'completed',
  cashier_id       INT UNSIGNED NOT NULL,
  cash_session_id  INT UNSIGNED NULL,          -- required only for cash sales (OQ-C05)
  subtotal         DECIMAL(15,2) NOT NULL,
  discount_total   DECIMAL(15,2) NOT NULL DEFAULT 0,
  total            DECIMAL(15,2) NOT NULL,
  void_reason      TEXT NULL,
  voided_by        INT UNSIGNED NULL,
  voided_at        DATETIME NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sales_number (sale_number),
  UNIQUE KEY uq_sales_request_token (request_token),
  KEY idx_sales_status_created_at (status, created_at),
  CONSTRAINT fk_sales_cashier FOREIGN KEY (cashier_id) REFERENCES users(id),
  CONSTRAINT fk_sales_voided_by FOREIGN KEY (voided_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE sale_number_counters (
  counter_date   DATE NOT NULL PRIMARY KEY,
  last_sequence  INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE sale_items (
  id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sale_id                     INT UNSIGNED NOT NULL,
  product_id                  INT UNSIGNED NOT NULL,
  product_code_snapshot       VARCHAR(50)  NOT NULL,
  product_name_snapshot       VARCHAR(150) NOT NULL,
  unit_id                     INT UNSIGNED NOT NULL,
  unit_name_snapshot          VARCHAR(30) NOT NULL,
  conversion_factor_snapshot  INT NOT NULL,
  qty_unit                    INT NOT NULL,
  qty_base                    INT NOT NULL,
  unit_price                  DECIMAL(15,2) NOT NULL,  -- price snapshot (BR-027)
  discount_amount             DECIMAL(15,2) NOT NULL DEFAULT 0,
  line_total                  DECIMAL(15,2) NOT NULL,
  unit_cost_snapshot          DECIMAL(15,4) NULL,       -- WAC snapshot at completion, NULL if cost unknown
  cogs_total                  DECIMAL(15,2) NULL,
  created_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sale_items_sale FOREIGN KEY (sale_id) REFERENCES sales(id),
  CONSTRAINT fk_sale_items_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_sale_items_unit FOREIGN KEY (unit_id) REFERENCES product_units(id)
) ENGINE=InnoDB;

CREATE TABLE payments (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sale_id        INT UNSIGNED NOT NULL,
  method         ENUM('cash','qris','dana','ovo','gopay','transfer','debit','other') NOT NULL,
  amount         DECIMAL(15,2) NOT NULL,
  cash_received  DECIMAL(15,2) NULL,
  change_amount  DECIMAL(15,2) NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_payments_sale FOREIGN KEY (sale_id) REFERENCES sales(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- stock_movements  (F-INV-001, Phase 2) - append-only ledger
-- ---------------------------------------------------------------------
CREATE TABLE stock_movements (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id          INT UNSIGNED NOT NULL,
  movement_type       ENUM('initial_stock','purchase','sale','sale_void','adjustment_in','adjustment_out') NOT NULL,
  qty_delta_base      INT NOT NULL,           -- signed
  balance_after_base  INT NOT NULL,
  cost_per_base       DECIMAL(15,4) NULL,     -- cost basis of this movement, if known (initial_stock/purchase)
  reference_type      VARCHAR(30) NULL,       -- e.g. 'sale', 'purchase'
  reference_id        INT UNSIGNED NULL,
  actor_user_id       INT UNSIGNED NOT NULL,
  reason              TEXT NULL,              -- required for manual adjustments (BR-016)
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_stock_movements_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_stock_movements_actor FOREIGN KEY (actor_user_id) REFERENCES users(id),
  KEY idx_stock_movements_product (product_id, created_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- cash_sessions / cash_movements  (F-CASH-001, F-SHIFT-001, Phase 5)
-- ---------------------------------------------------------------------
CREATE TABLE cash_sessions (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  opened_by      INT UNSIGNED NOT NULL,
  opening_cash   DECIMAL(15,2) NOT NULL,
  opened_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_by      INT UNSIGNED NULL,
  expected_cash  DECIMAL(15,2) NULL,
  actual_cash    DECIMAL(15,2) NULL,
  difference     DECIMAL(15,2) NULL,
  note           TEXT NULL,
  closed_at      DATETIME NULL,
  status         ENUM('open','closed') NOT NULL DEFAULT 'open',
  CONSTRAINT fk_cash_sessions_opened_by FOREIGN KEY (opened_by) REFERENCES users(id),
  CONSTRAINT fk_cash_sessions_closed_by FOREIGN KEY (closed_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE cash_movements (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cash_session_id   INT UNSIGNED NOT NULL,
  movement_type     ENUM('cash_sale','cash_sale_void','purchase_payment','cash_in','cash_out') NOT NULL,
  amount            DECIMAL(15,2) NOT NULL,  -- signed relative to expected cash
  reference_type    VARCHAR(30) NULL,
  reference_id      INT UNSIGNED NULL,
  actor_user_id     INT UNSIGNED NOT NULL,
  reason            TEXT NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cash_movements_session FOREIGN KEY (cash_session_id) REFERENCES cash_sessions(id),
  CONSTRAINT fk_cash_movements_actor FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- audit_logs  (F-AUD-001)
-- ---------------------------------------------------------------------
CREATE TABLE audit_logs (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_user_id  INT UNSIGNED NULL,
  action         VARCHAR(50) NOT NULL,
  entity_type    VARCHAR(50) NOT NULL,
  entity_id      INT UNSIGNED NULL,
  before_value   TEXT NULL,  -- JSON
  after_value    TEXT NULL,  -- JSON
  reason         TEXT NULL,
  batch_id       CHAR(32) NULL,  -- groups rows from one bulk operation (e.g. bulk import) under its summary row
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_logs_actor FOREIGN KEY (actor_user_id) REFERENCES users(id),
  KEY idx_audit_logs_batch (batch_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- app_settings  (business policy settings, keyed)
-- ---------------------------------------------------------------------
CREATE TABLE app_settings (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  setting_key    VARCHAR(100) NOT NULL,
  setting_value  TEXT NULL,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_app_settings_key (setting_key)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- backup_history  (F-BACK-001)
-- ---------------------------------------------------------------------
CREATE TABLE backup_history (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  file_name   VARCHAR(255) NOT NULL,
  created_by  INT UNSIGNED NOT NULL,
  status      ENUM('success','failed') NOT NULL,
  note        TEXT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_backup_history_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- =====================================================================
-- Seed data
-- =====================================================================

-- Default owner account. Username: owner / Password: Ganti123!
-- MUST be changed after first login (Users > edit > reset password).
INSERT INTO users (username, password_hash, full_name, role, active) VALUES
  ('owner', '$2y$10$sw.cssmHuwH2Wu08uOQlS.eDERw1gV/umq/7IGdkwigP86tJbJYYa', 'Pemilik Toko', 'owner', 1);

-- Business policy settings, reflecting the decisions confirmed for PARTAMBUS v1:
INSERT INTO app_settings (setting_key, setting_value) VALUES
  ('store_name', 'Toko Partambus'),
  ('store_address', ''),                            -- shown on receipts when filled in via Pengaturan
  ('store_phone', ''),
  ('timezone', 'Asia/Jakarta'),
  ('store_day_start', '00:00'),                    -- OQ-C08: no overnight operation
  ('sale_number_prefix', 'SL'),                     -- OQ-C06
  ('purchase_number_prefix', 'PU'),
  ('initial_cost_required', '0'),                   -- OQ-C01: unknown cost allowed with warning
  ('allow_below_cost_owner', '1'),                  -- OQ-C02
  ('allow_below_cost_cashier', '0'),                -- OQ-C02
  ('cashier_max_discount_percent', '0'),            -- OQ-C03
  ('require_cash_session_for_cash_sale', '1'),      -- OQ-C05: only cash sales require a session
  ('purchase_payment_source_mode', 'ask_each_time'),-- OQ-C07
  ('low_stock_default_threshold', '5'),
  ('backup_reminder_hours', '24');

-- Standard unit list, ordered by real-world frequency of use (informs dropdown order).
INSERT INTO units (code, code_normalized, description, sort_order) VALUES
  ('PCS',   'PCS',   'Pieces / satuan biji', 10),
  ('DUS',   'DUS',   'Dus / karton', 20),
  ('PACK',  'PACK',  'Pak / bungkus besar', 30),
  ('GTG',   'GTG',   'Gantungan (barang digantung di rak)', 40),
  ('BAL',   'BAL',   'Bal (karton besar, isi banyak slop)', 50),
  ('BTL',   'BTL',   'Botol', 60),
  ('SLOP',  'SLOP',  'Slop (isi 10 bungkus)', 70),
  ('BOX',   'BOX',   'Kotak', 80),
  ('LSN',   'LSN',   'Lusin (isi 12)', 90),
  ('TPLS',  'TPLS',  'Toples', 100),
  ('IKT',   'IKT',   'Ikat', 110),
  ('SAK',   'SAK',   'Sak / karung', 120),
  ('JRGN',  'JRGN',  'Jerigen', 130),
  ('STRIP', 'STRIP', 'Strip (biasa obat-obatan)', 140),
  ('ROLL',  'ROLL',  'Rol / gulungan', 150),
  ('MTR',   'MTR',   'Meter', 160),
  ('LTR',   'LTR',   'Liter', 170),
  ('KG',    'KG',    'Kilogram', 180);
