-- =====================================================================
-- PesaGuard - M-Pesa Statement Analyzer and Fraud Detection System
-- Database: mpesa_analyzer_db (MySQL 8 / InnoDB)
-- Import with HeidiSQL (Laragon > Database) or run install.php
-- =====================================================================
CREATE DATABASE IF NOT EXISTS mpesa_analyzer_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE mpesa_analyzer_db;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS activity_log, blacklist, flagged_transactions, fraud_rules, transactions, categories, statements, users;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE users (
  user_id       INT AUTO_INCREMENT PRIMARY KEY,
  full_name     VARCHAR(100) NOT NULL,
  email         VARCHAR(100) NOT NULL UNIQUE,
  phone         VARCHAR(15),
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('User','Admin') NOT NULL DEFAULT 'User',
  status        ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE statements (
  statement_id  INT AUTO_INCREMENT PRIMARY KEY,
  user_id       INT NOT NULL,
  file_name     VARCHAR(255),
  file_hash     CHAR(64) NOT NULL UNIQUE,
  source_type   ENUM('PDF','CSV') NOT NULL,
  period_start  DATE,
  period_end    DATE,
  total_records INT DEFAULT 0,
  uploaded_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE categories (
  category_id     INT AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(50) NOT NULL UNIQUE,
  keyword_pattern VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE transactions (
  transaction_id  INT AUTO_INCREMENT PRIMARY KEY,
  statement_id    INT NOT NULL,
  category_id     INT NULL,
  receipt_no      VARCHAR(12) NOT NULL,
  completion_time DATETIME NOT NULL,
  details         VARCHAR(255),
  counterparty    VARCHAR(60),
  direction       ENUM('IN','OUT') NOT NULL,
  amount          DECIMAL(12,2) NOT NULL,
  balance         DECIMAL(12,2),
  risk_score      TINYINT UNSIGNED DEFAULT 0,
  risk_level      ENUM('Low','Medium','High') DEFAULT 'Low',
  INDEX idx_time (completion_time),
  INDEX idx_receipt (receipt_no),
  FOREIGN KEY (statement_id) REFERENCES statements(statement_id) ON DELETE CASCADE,
  FOREIGN KEY (category_id) REFERENCES categories(category_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE fraud_rules (
  rule_id    INT AUTO_INCREMENT PRIMARY KEY,
  rule_code  VARCHAR(5) NOT NULL UNIQUE,
  rule_name  VARCHAR(100) NOT NULL,
  threshold  DECIMAL(12,2),
  weight     TINYINT UNSIGNED NOT NULL,
  is_active  TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE flagged_transactions (
  flag_id        INT AUTO_INCREMENT PRIMARY KEY,
  transaction_id INT NOT NULL,
  rule_id        INT NOT NULL,
  score          TINYINT UNSIGNED NOT NULL,
  review_status  ENUM('Pending','Confirmed','Dismissed') NOT NULL DEFAULT 'Pending',
  reviewed_by    INT NULL,
  reviewed_at    DATETIME NULL,
  FOREIGN KEY (transaction_id) REFERENCES transactions(transaction_id) ON DELETE CASCADE,
  FOREIGN KEY (rule_id) REFERENCES fraud_rules(rule_id),
  FOREIGN KEY (reviewed_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE blacklist (
  blacklist_id INT AUTO_INCREMENT PRIMARY KEY,
  identifier   VARCHAR(20) NOT NULL UNIQUE,
  entity_type  ENUM('Phone','Till','Paybill') NOT NULL,
  reason       VARCHAR(255) NOT NULL,
  added_by     INT NULL,
  created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (added_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE activity_log (
  log_id     INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT NULL,
  action     VARCHAR(255) NOT NULL,
  ip_address VARCHAR(45),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_log_time (created_at),
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Seed data
-- Default logins:  admin@pesaguard.test / Admin@123   (Administrator)
--                  brian@example.com    / Demo@123    (M-Pesa user)
-- ---------------------------------------------------------------------
INSERT INTO users (full_name, email, phone, password_hash, role) VALUES
 ('System Administrator', 'admin@pesaguard.test', '0700000000', '$2y$10$yrFY8.9qxLmX7u6tLNrwN.dTyEBHilySATkoUiXWRXyZQEQITk/Li', 'Admin'),
 ('Brian Otieno',         'brian@example.com',    '0712345678', '$2y$10$k7QEdvZz2R1bDS.0GuRb0uToZsSTAQAwW10fX3OSia1MUFAfMJcXW', 'User');

-- Checked in this order; the first matching category wins
INSERT INTO categories (name, keyword_pattern) VALUES
 ('Charges',          'Charge'),
 ('Loans & Fuliza',   'OverDraft|Fuliza|M-Shwari|KCB M-PESA|Loan'),
 ('Airtime & Bundles','Airtime|Bundle'),
 ('Deposits',         'Deposit'),
 ('Withdrawals',      'Withdrawal|Agent Till|ATM'),
 ('Pay Bill',         'Pay Bill|Paybill'),
 ('Buy Goods',        'Merchant Payment|Buy Goods|Till'),
 ('Send Money',       'Customer Transfer|Send Money'),
 ('Money Received',   'Funds received|Received from'),
 ('Salary & Business','Business Payment|Salary|B2C');

INSERT INTO fraud_rules (rule_code, rule_name, threshold, weight) VALUES
 ('R1', 'Unusually large amount (z-score)',       3,     30),
 ('R2', 'Odd-hour activity (before 05:00)',       5,     15),
 ('R3', 'Rapid successive transfers (10 min)',    3,     25),
 ('R4', 'New recipient, high value (KES)',        10000, 20),
 ('R5', 'Blacklisted counterparty',               0,     40),
 ('R6', 'Reversal-scam pattern (minutes)',        60,    35),
 ('R7', 'Balance draining (% in 1 hour)',         80,    25),
 ('R8', 'Loan-then-withdraw (minutes)',           30,    20);

INSERT INTO blacklist (identifier, entity_type, reason, added_by) VALUES
 ('0790***066', 'Phone', 'Reported by several users for fake "wrong transaction" reversal requests', 1);

INSERT INTO activity_log (user_id, action, ip_address) VALUES (1, 'System installed', '127.0.0.1');
