-- SQLite version of the schema, used only by the automated tests (tests/run_tests.php).
-- The production database is MySQL: database/mpesa_analyzer_db.sql
PRAGMA foreign_keys = ON;
CREATE TABLE users (user_id INTEGER PRIMARY KEY AUTOINCREMENT, full_name TEXT NOT NULL, email TEXT NOT NULL UNIQUE,
  phone TEXT, password_hash TEXT NOT NULL, role TEXT NOT NULL DEFAULT 'User' CHECK (role IN ('User','Admin')),
  status TEXT NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Inactive')), created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE statements (statement_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
  file_name TEXT, file_hash TEXT NOT NULL, source_type TEXT NOT NULL, period_start TEXT, period_end TEXT, total_records INTEGER DEFAULT 0,
  uploaded_at TEXT DEFAULT CURRENT_TIMESTAMP, UNIQUE (user_id, file_hash));
CREATE TABLE categories (category_id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE, keyword_pattern TEXT NOT NULL);
CREATE TABLE transactions (transaction_id INTEGER PRIMARY KEY AUTOINCREMENT, statement_id INTEGER NOT NULL REFERENCES statements(statement_id) ON DELETE CASCADE,
  category_id INTEGER REFERENCES categories(category_id) ON DELETE SET NULL, receipt_no TEXT NOT NULL, completion_time TEXT NOT NULL, details TEXT,
  counterparty TEXT, direction TEXT NOT NULL, amount REAL NOT NULL, balance REAL, risk_score INTEGER DEFAULT 0, risk_level TEXT DEFAULT 'Low');
CREATE TABLE fraud_rules (rule_id INTEGER PRIMARY KEY AUTOINCREMENT, rule_code TEXT NOT NULL UNIQUE, rule_name TEXT NOT NULL, threshold REAL,
  weight INTEGER NOT NULL, is_active INTEGER NOT NULL DEFAULT 1);
CREATE TABLE flagged_transactions (flag_id INTEGER PRIMARY KEY AUTOINCREMENT, transaction_id INTEGER NOT NULL REFERENCES transactions(transaction_id) ON DELETE CASCADE,
  rule_id INTEGER NOT NULL REFERENCES fraud_rules(rule_id), score INTEGER NOT NULL, review_status TEXT NOT NULL DEFAULT 'Pending',
  reviewed_by INTEGER REFERENCES users(user_id) ON DELETE SET NULL, reviewed_at TEXT);
CREATE TABLE blacklist (blacklist_id INTEGER PRIMARY KEY AUTOINCREMENT, identifier TEXT NOT NULL UNIQUE, entity_type TEXT NOT NULL, reason TEXT NOT NULL,
  added_by INTEGER REFERENCES users(user_id) ON DELETE SET NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE activity_log (log_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER REFERENCES users(user_id) ON DELETE SET NULL, action TEXT NOT NULL,
  ip_address TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
