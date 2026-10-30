# PesaGuard – M-Pesa Statement Analyzer and Fraud Detection System

A web application that reads an M-Pesa statement (password-protected PDF or CSV), categorises every
transaction, shows spending and cash-flow analytics on a dashboard, and scores each outgoing transaction
for fraud risk with a configurable rule engine.

**Final-year project, Bachelor of Business Information Technology, Mount Kenya University**  
Student: Abdirahim Ibrahim (BBIT/2024/36390) · Supervisor: Christine Kiboori

---

## Features

| Module | What it does |
|---|---|
| User management | Register, log in and log out. Passwords are hashed with bcrypt. The account locks for 15 minutes after 5 failed logins. Access is role-based (User / Admin). |
| Statement upload | Accepts a PDF with its SMS password (decrypted with qpdf, read with Smalot PdfParser) or a CSV export. Files are checked by extension, MIME type and size, and duplicates are rejected by SHA-256 file hash. The uploaded file is deleted after processing. |
| Analysis | Sorts transactions into 10 editable categories. Dashboard shows money in/out, charges, closing balance, monthly cash flow, spending by category, top recipients and activity by hour. |
| Fraud detection | 8 weighted rules (below) give each outgoing transaction a risk score from 0 to 100: Low < 30, Medium 30–59, High ≥ 60. |
| Flagged transactions | Lists every transaction scoring 30 or more with the rules it triggered. The user clicks **Confirm** (fraud) or **Dismiss** (false alarm). |
| Reports | Monthly spending summary, flagged transactions, top recipients, charges per transaction type and the full transaction list, for any period, each exportable as PDF (Dompdf) or CSV. |
| Administration | Manage users, fraud rules (thresholds, weights, on/off), the blacklist and categories. Re-score all statements, view rule precision and the activity log. |

### Fraud detection rules

| Code | Rule | Default threshold | Weight |
|---|---|---|---|
| R1 | Unusually large amount: more than 3 standard deviations above the user's average outgoing amount | 3 (z-score) | 30 |
| R2 | Odd-hour activity: outgoing transaction before 05:00 | 5 (hour) | 15 |
| R3 | Rapid successive transfers: 3 or more outgoing transactions within 10 minutes | 3 | 25 |
| R4 | New recipient, high value: first payment to a number or till above the threshold | KES 10,000 | 20 |
| R5 | Blacklisted counterparty | – | 40 |
| R6 | Reversal-scam pattern: "Funds received", then a similar amount (±5%) sent out within the threshold | 60 min | 35 |
| R7 | Balance draining: balance falls by more than the threshold within one hour | 80 % | 25 |
| R8 | Loan-then-withdraw: Fuliza / M-Shwari / KCB M-PESA loan followed by an outflow of ≥ 90% of it within the threshold | 30 min | 20 |

Transaction charges are not scored and do not count towards rule R3.

## Technology

PHP 8.1+ (PDO), MySQL 8 (InnoDB), HTML5, CSS3, Bootstrap 5.3, Bootstrap Icons, JavaScript and Chart.js 4,
Smalot PdfParser 2.12, Dompdf 3.0 and qpdf 11. It is developed on **Laragon**.  
The PHP libraries were installed with Composer (`composer.json`) and are included in `vendor/`, together with
`assets/vendor/` and `tools/qpdf/`, so the system also runs without an internet connection. Running
`composer install` is optional.

## Installation on Laragon (Windows)

1. Copy or clone this folder into `C:\laragon\www\`. For example:
   ```
   cd C:\laragon\www
   git clone https://github.com/ibraanwar7565-ai/MPESASTATEMENTANALIZER.git mpesa-analyzer
   ```
2. Open Laragon and click **Start All** (Apache and MySQL). Then click **Menu › Apache › Reload** (or restart
   Laragon) so the automatic virtual host is created.
3. Create the database. Use **either** method:
   - Open **http://mpesa-analyzer.test/install.php** and click **Install now**, **or**
   - Open HeidiSQL (Laragon › Database) and run `database/mpesa_analyzer_db.sql`.
4. Open **http://mpesa-analyzer.test** and log in:

   | Role | E-mail | Password |
   |---|---|---|
   | Administrator | `admin@pesaguard.test` | `Admin@123` |
   | User | `brian@example.com` | `Demo@123` |

The virtual host name follows the folder name (folder `mpesa-analyzer` → `mpesa-analyzer.test`). You can
also open it as `http://localhost/mpesa-analyzer/`. The database settings are in `config/config.php`
(Laragon default: user `root`, empty password). qpdf is detected automatically from `tools/qpdf` or
`C:\laragon\bin\qpdf`; to use another copy, set `QPDF_PATH` in `config/config.php`.

## Using the system

1. **Upload a statement:** open *Upload Statement*, choose your M-Pesa PDF, type the password Safaricom sent
   by SMS and click **Upload & Analyse** (or choose a CSV file). To demo the system, click **Load sample PDF
   (password 123456)** or **Load sample CSV**.
2. **Dashboard:** view totals, charts, alerts and top recipients. Use the drop-down to choose one statement.
3. **Transactions:** filter by statement, category, type, risk, date or text. Change a transaction's
   category from the drop-down.
4. **Flagged:** read the rules triggered for each transaction. Click **Confirm** if it was fraud or **Dismiss** if you recognise it.
5. **Reports:** choose the report and the period, then click **PDF** or **CSV**.
6. **My profile:** update your details, change your password or delete all your statement data.
7. **Admin:** manage users, rules, the blacklist and categories. After changing rules, click **Re-score all
   statements**.

### Sample statement

`sample/sample_statement.csv` and `sample/sample_statement.pdf` (password `123456`) contain 39 transactions
from October 2026 with these planted fraud patterns:

| Receipt | Pattern | Expected result |
|---|---|---|
| TJ296YKJBA | KES 48,000 transfer (very large) | R1 → 30 Medium |
| TJ30G9J3NP, TJ31BSPU8R, TJ32WS2JDY | Three transfers at 02:10–02:16 | R2 → 15 Low, R2 → 15 Low, R2+R3 → 40 Medium |
| TJ335928JK | Payment to blacklisted 0790\*\*\*066 | R5 → 40 Medium |
| TJ35KMK6HD | "Funds received" KES 3,200, then KES 3,150 sent back after 20 min | R6 → 35 Medium |
| TJ398NT486 | Fuliza KES 5,000, then withdraws all of it 12 min later | R7+R8 → 45 Medium |

## Automated tests

```
php tests/run_tests.php
```
This runs 36 unit and integration tests for the parser (CSV, PDF and wrapped text), the categoriser, every
fraud rule (Appendix V test statement), MIME and duplicate checks, the import pipeline, re-scoring, reports
and access control. Test names use the IDs from Chapter Six (UT-07 to UT-18, IT-01 to IT-03). The tests use a temporary SQLite
database, so enable `pdo_sqlite` (Laragon › Menu › PHP › Extensions) before running them.

## Project structure

```
admin/          administrator pages (users, rules, blacklist, categories, logs, overview)
assets/         CSS, JavaScript, images and local copies of Bootstrap, Bootstrap Icons and Chart.js
auth/           login, registration and logout
classes/        StatementParser, Categorizer, FraudEngine, StatementImporter, ReportService
config/         application and database settings
database/       MySQL schema and seed data (mpesa_analyzer_db.sql)
includes/       shared functions, page header and footer
reports/        report preview and PDF / CSV export
sample/         sample statement (CSV and password-protected PDF)
statements/     upload and statement list
storage/tmp/    temporary folder for uploads (emptied after each upload, not web-accessible)
tests/          automated tests
tools/          bundled qpdf for Windows and the script that builds the sample PDF
vendor/         PHP libraries (Smalot PdfParser, Dompdf)
dashboard.php, transactions.php, flagged.php, profile.php
```

## Security

- Passwords are hashed with bcrypt. Login regenerates the session ID, and session cookies are HttpOnly and SameSite.
- Every SQL query uses a PDO prepared statement.
- All output is escaped with `htmlspecialchars`.
- Every form has a CSRF token.
- The account locks after 5 failed logins in 15 minutes.
- Each user sees only their own data, and admin pages check the role.
- Uploads are checked by extension, size and PDF signature. They are renamed at random and deleted after parsing.
- The statement password is never stored.
- `.htaccess` blocks web access to `storage/`, `classes/`, `config/`, `database/`, `tests/`, `tools/` and `vendor/`.

## Licence of bundled tools

- qpdf 11.9.1: Apache License 2.0 (https://qpdf.sourceforge.io)
- Dompdf: LGPL-2.1
- Smalot PdfParser: LGPL-3.0
- Bootstrap, Bootstrap Icons and Chart.js: MIT
