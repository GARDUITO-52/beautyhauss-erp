-- beautyhauss ERP — Schema v1.0
-- Database: u253288084_beautyhauss_er
-- Execute in phpMyAdmin

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- Suppliers
-- ----------------------------
CREATE TABLE IF NOT EXISTS suppliers (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(150) NOT NULL,
  contact_name  VARCHAR(100),
  email         VARCHAR(150),
  country       VARCHAR(80) DEFAULT 'USA',
  notes         TEXT,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Purchase Batches (lotes de compra)
-- ----------------------------
CREATE TABLE IF NOT EXISTS purchase_batches (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id   INT UNSIGNED NOT NULL,
  reference_no  VARCHAR(100) NOT NULL,
  batch_date    DATE NOT NULL,
  total_usd     DECIMAL(10,2) NOT NULL,
  fx_rate       DECIMAL(8,4) NOT NULL COMMENT 'MXN per USD at time of purchase',
  investor      ENUM('JACK','ARTURO','COMPARTIDO') NOT NULL DEFAULT 'ARTURO',
  status        ENUM('PENDING','RECEIVED','PARTIAL') NOT NULL DEFAULT 'PENDING',
  notes         TEXT,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Purchase Batch Items
-- ----------------------------
CREATE TABLE IF NOT EXISTS purchase_batch_items (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch_id            INT UNSIGNED NOT NULL,
  supplier_product_id VARCHAR(50),
  brand               VARCHAR(100),
  description         VARCHAR(255) NOT NULL,
  upc                 VARCHAR(50),
  packaging_type      VARCHAR(50),
  color               VARCHAR(80),
  size                VARCHAR(30),
  qty                 INT UNSIGNED NOT NULL DEFAULT 0,
  unit_cost_usd       DECIMAL(8,4) NOT NULL,
  FOREIGN KEY (batch_id) REFERENCES purchase_batches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Products (auto-generated from batch items)
-- ----------------------------
CREATE TABLE IF NOT EXISTS products (
  id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch_item_id        INT UNSIGNED,
  sku_internal         VARCHAR(50),
  brand                VARCHAR(100),
  description          VARCHAR(255) NOT NULL,
  upc                  VARCHAR(50),
  color                VARCHAR(80),
  size                 VARCHAR(30),
  packaging_type       VARCHAR(50),
  weight_grams         INT UNSIGNED DEFAULT 0,
  cost_usd             DECIMAL(8,4) NOT NULL,
  fx_rate_at_purchase  DECIMAL(8,4) NOT NULL,
  cost_mxn             DECIMAL(10,2) NOT NULL DEFAULT 0,
  rescue_price_mxn     DECIMAL(10,2) NOT NULL DEFAULT 0,
  stock_qty            INT NOT NULL DEFAULT 0,
  created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (batch_item_id) REFERENCES purchase_batch_items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Hosts / Streamers
-- ----------------------------
CREATE TABLE IF NOT EXISTS hosts (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(100) NOT NULL,
  hourly_rate_usd DECIMAL(6,2) NOT NULL DEFAULT 22.00,
  commission_pct  DECIMAL(5,2) NOT NULL DEFAULT 3.00,
  contact         VARCHAR(150),
  notes           TEXT,
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Shows
-- ----------------------------
CREATE TABLE IF NOT EXISTS shows (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  scheduled_at          DATETIME NOT NULL,
  estimated_duration_hrs DECIMAL(4,2) DEFAULT 2.00,
  host_id               INT UNSIGNED,
  title                 VARCHAR(150) NOT NULL,
  status                ENUM('SCHEDULED','LIVE','COMPLETED','CANCELLED') NOT NULL DEFAULT 'SCHEDULED',
  notes                 TEXT,
  created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Show Products (lineup)
-- ----------------------------
CREATE TABLE IF NOT EXISTS show_products (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  show_id         INT UNSIGNED NOT NULL,
  product_id      INT UNSIGNED NOT NULL,
  starting_bid_usd DECIMAL(8,2) NOT NULL DEFAULT 1.00,
  qty_listed      INT UNSIGNED NOT NULL DEFAULT 0,
  FOREIGN KEY (show_id) REFERENCES shows(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Orders (imported from Whatnot CSV)
-- ----------------------------
CREATE TABLE IF NOT EXISTS orders (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  whatnot_order_id  VARCHAR(100) NOT NULL UNIQUE,
  show_id           INT UNSIGNED,
  buyer_username    VARCHAR(100),
  sale_amount_usd   DECIMAL(10,2) NOT NULL DEFAULT 0,
  fee_commission    DECIMAL(8,2) NOT NULL DEFAULT 0,
  fee_processing    DECIMAL(8,2) NOT NULL DEFAULT 0,
  fee_per_tx        DECIMAL(6,2) NOT NULL DEFAULT 0.30,
  net_earnings_usd  DECIMAL(10,2) NOT NULL DEFAULT 0,
  cogs_usd          DECIMAL(10,2) NOT NULL DEFAULT 0,
  order_date        DATE NOT NULL,
  status            ENUM('FULFILLED','UNFULFILLED','RETURNED','DISPUTE') NOT NULL DEFAULT 'FULFILLED',
  imported_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (show_id) REFERENCES shows(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Order Items
-- ----------------------------
CREATE TABLE IF NOT EXISTS order_items (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id      INT UNSIGNED NOT NULL,
  product_id    INT UNSIGNED,
  qty           INT UNSIGNED NOT NULL DEFAULT 1,
  sale_price_usd DECIMAL(8,2) NOT NULL DEFAULT 0,
  cogs_usd      DECIMAL(8,4) NOT NULL DEFAULT 0,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Expenses
-- ----------------------------
CREATE TABLE IF NOT EXISTS expenses (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  show_id      INT UNSIGNED,
  category     ENUM('PACKAGING','HOST_SALARY','EQUIPMENT','MARKETING','PROMOTE_TOOLS','WAREHOUSE_SHIPPING','OTRO') NOT NULL,
  amount_usd   DECIMAL(10,2) NOT NULL,
  expense_date DATE NOT NULL,
  notes        VARCHAR(255),
  created_by   VARCHAR(80),
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (show_id) REFERENCES shows(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- System Config
-- ----------------------------
CREATE TABLE IF NOT EXISTS system_config (
  config_key   VARCHAR(50) PRIMARY KEY,
  config_value VARCHAR(255) NOT NULL,
  updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO system_config (config_key, config_value) VALUES
  ('fx_rate',          '20.50'),
  ('goal_amount_usd',  '60000'),
  ('goal_start_date',  '2026-04-20'),
  ('goal_end_date',    '2026-05-20'),
  ('app_name',         'beautyhauss ERP'),
  ('admin_password',   '$2y$10$OkRedFNxsbxcHPrxQnTKO.46dYZzIkKW2DiUwfZ677k5SDTagulLa')
ON DUPLICATE KEY UPDATE config_value = VALUES(config_value);

SET FOREIGN_KEY_CHECKS = 1;
