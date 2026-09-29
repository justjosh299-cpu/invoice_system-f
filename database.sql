CREATE DATABASE IF NOT EXISTS omg_diagnostics
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE omg_diagnostics;

-- Base tables (safe on re-run)
CREATE TABLE IF NOT EXISTS users(id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(80) UNIQUE NOT NULL, password_hash VARCHAR(255) NOT NULL, full_name VARCHAR(120) NOT NULL, role VARCHAR(30) DEFAULT 'technician', active TINYINT DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE IF NOT EXISTS clients(id INT AUTO_INCREMENT PRIMARY KEY, client_name VARCHAR(180) NOT NULL, address VARCHAR(255), phone VARCHAR(60), alternate_phone VARCHAR(60), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE IF NOT EXISTS settings(setting_key VARCHAR(60) PRIMARY KEY, setting_value TEXT);

/* ---------- PARTS INVENTORY ---------- */
CREATE TABLE IF NOT EXISTS parts(
  id INT AUTO_INCREMENT PRIMARY KEY,
  part_code VARCHAR(60) UNIQUE,
  part_name VARCHAR(180) NOT NULL,
  category VARCHAR(80),
  quantity INT DEFAULT 0,
  reorder_level INT DEFAULT 2,
  unit_cost DECIMAL(14,2) DEFAULT 0,
  supplier VARCHAR(180),
  notes TEXT,
  active TINYINT DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS part_movements(
  id INT AUTO_INCREMENT PRIMARY KEY,
  part_id INT NOT NULL,
  delta INT NOT NULL,
  reason VARCHAR(60) DEFAULT 'adjust',
  reference_type VARCHAR(40),
  reference_id INT,
  note VARCHAR(255),
  user_id INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_part (part_id),
  INDEX idx_created (created_at)
);

/* ---------- RECURRING INVOICES ---------- */
CREATE TABLE IF NOT EXISTS recurring_invoices(
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(180) NOT NULL,
  client_id INT NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  currency VARCHAR(10) DEFAULT 'UGX',
  tax_rate DECIMAL(6,2) DEFAULT 0,
  description VARCHAR(255) DEFAULT 'Recurring service',
  frequency VARCHAR(20) DEFAULT 'monthly',
  next_run DATE NOT NULL,
  last_run DATE NULL,
  active TINYINT DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_next (next_run),
  INDEX idx_client (client_id)
);

/* ---------- QUOTATIONS ---------- */
CREATE TABLE IF NOT EXISTS quotations(
  id INT AUTO_INCREMENT PRIMARY KEY,
  quote_no VARCHAR(40) UNIQUE NOT NULL,
  client_id INT NULL,
  report_id INT NULL,
  issue_date DATE NOT NULL,
  valid_until DATE NULL,
  status VARCHAR(30) DEFAULT 'DRAFT',
  currency VARCHAR(10) DEFAULT 'UGX',
  subtotal DECIMAL(14,2) DEFAULT 0,
  discount_amount DECIMAL(14,2) DEFAULT 0,
  tax_rate DECIMAL(6,2) DEFAULT 0,
  tax_amount DECIMAL(14,2) DEFAULT 0,
  total DECIMAL(14,2) DEFAULT 0,
  notes TEXT,
  terms TEXT,
  accepted_at DATETIME NULL,
  invoice_id INT NULL,
  created_by INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_client (client_id),
  INDEX idx_status (status)
);

CREATE TABLE IF NOT EXISTS quotation_items(
  id INT AUTO_INCREMENT PRIMARY KEY,
  quote_id INT NOT NULL,
  description VARCHAR(255) NOT NULL,
  quantity DECIMAL(12,2) DEFAULT 1,
  unit_price DECIMAL(14,2) DEFAULT 0,
  line_total DECIMAL(14,2) DEFAULT 0,
  FOREIGN KEY(quote_id) REFERENCES quotations(id) ON DELETE CASCADE
);