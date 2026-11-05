# IQRA Billing — Phase 5: Controlled Hostinger Release and Live Financial Verification Report

**Date:** 2026-10-28  
**Project Workspace:** `C:\Users\hp\Desktop\iqra-laravel-billing`  
**Pinned Release Commit:** `94ebf25964925474c876210229f9dde0d5435b6c` (`94ebf25` on `master`, 31 verified files)  
**Local Canonical Database:** `iqra_billing` (`10.4.32-MariaDB`, **0 financial writes executed**)  
**Target Production Environment:** Hostinger VPS `srv1572606` (`200.234.41.189`, root web directory `/var/www/billing`)  
**Remote Execution Status:** **LOCAL PREPARATION & RELEASE COMMIT COMPLETE / REMOTE SSH BLOCKED BY NON-INTERACTIVE AUTHENTICATION (`Permission denied (publickey,password)`) — STOPPED PER RULE 9**

---

## Executive Summary

1. **Task 1 Discrepancies Resolved & Verified in Code (`100% Complete`):**
   - **`GR 4814` (`Monthly Fee - May-2026`, `AED 210.00 + AED 250.00`):** Fixed [`MySkoolReconciliationAuditService.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/Migration/MySkoolReconciliationAuditService.php) so that multi-line opening-balance groups with zero post-cutoff collections whose combined opening-balance lines plus pre-cutoff payments do not exceed the student's enrollment monthly fee (`AED 210 + AED 250 + AED 40 paid 2026-07-03 = AED 500.00` monthly fee) are classified as **`PRE_CUTOFF_RESIDUAL_BALANCE` (`guard_class = KEEP_GENUINE_RESIDUAL`)** instead of `DUPLICATE_SOURCE_HOLD`.
   - **`GR 6562` (`Monthly Fee - Oct-2026`, `AED 200.00 + AED 200.00`):** Fixed [`MySkoolReconciliationAuditService.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/Migration/MySkoolReconciliationAuditService.php) so that suspected duplicate opening-balance obligations where multiple items for the same `(student_gr, fee_type, period_key, subject)` exceed the student's monthly fee (`AED 200 + AED 200 = AED 400.00` vs `AED 200.00` monthly fee) are surfaced and held under **`MANUAL_REVIEW_AMBIGUOUS_MATCH` (`guard_class = DUPLICATE_SOURCE_HOLD`)** even when zero historical collection rows exist for that month.
   - **Identity & Batch Validation:** Hardened collection matching in [`MySkoolReconciliationAuditService.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/Migration/MySkoolReconciliationAuditService.php) to enforce `student_gr`, `fee_type` (`fee_types.code` + normalized description), `period_key`, `subject`, `currency`, and `batch_id` lineage scoping.
   - **Automated Test Verification:** Updated [`tests/Feature/HistoricalPaymentReconciliationTest.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/tests/Feature/HistoricalPaymentReconciliationTest.php) and [`tests/Feature/MySkoolReconciliationAuditTest.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/tests/Feature/MySkoolReconciliationAuditTest.php) and executed the reconciliation, restoration, and accounting suites (**77 tests, 645 assertions — 100% PASS**).
2. **Task 4 Release Preparation & Pinned Commit (`100% Complete`):**
   - Verified zero debug statements (`dd`, `dump`, `var_dump`, `print_r`, `ray`) and zero unrelated Library or Shop modifications across all modified and untracked files.
   - Created pinned local Git commit **`94ebf25964925474c876210229f9dde0d5435b6c`** (`94ebf25`) containing the exact **31 verified application, configuration, migration, route, view, and test files**. No automatic `git push` or deployment was executed.
3. **Hostinger SSH Connectivity & Remote Execution Status (`Stopped per Rule 9`):**
   - Following explicit owner permission to test read-only SSH connectivity, non-interactive SSH checks (`ssh -o BatchMode=yes`) were tested against both the legacy shared-hosting endpoint (`u393409297@145.14.146.15:65002`) and the owner-provided Hostinger VPS endpoint (`root@200.234.41.189` / `srv1572606`, `/var/www/billing`).
   - Both endpoints returned **`Permission denied (publickey,password)`** because no authorized SSH public key or active `ssh-agent` identity is loaded in the local non-interactive shell, and **Rule 2 strictly prohibits requesting or printing passwords or private keys in chat**.
   - In strict compliance with **Rule 9** (*"If SSH access fails, stop remote work and report the access problem. Continue only with safe local preparation"*) and **Task 10** (*"If release authorization was not received, report the completed preparation and pending deployment instead of claiming deployment succeeded"*), all remote operations were halted immediately.

---

## 1. Exact Production Environment Verified & SSH Access Report (Task 2)

### 1.1 Production Server & Path Resolution
During Phase 5 verification, the project owner clarified that the active Hostinger production environment for IQRA Billing is hosted on the **Hostinger VPS** rather than the legacy shared-hosting path referenced in older Phase 4/9 notes:

| Environment Attribute | Legacy Shared Hosting Reference (Superseded) | Owner-Confirmed Active Hostinger VPS Target | Verification Status |
| :--- | :--- | :--- | :--- |
| **Server Hostname / Type** | Shared Hosting (`145.14.146.15`, port `65002`) | **`srv1572606` (Hostinger VPS)** | **Owner-Confirmed Target** |
| **Server IP & SSH Port** | `145.14.146.15` (Port `65002`, user `u393409297`) | **`200.234.41.189` (Port `22`, user `root`)** | **Reachable over TCP; SSH Auth Blocked** |
| **Root Web Directory** | `/home/u393409297/domains/iqra.edu.pk/public_html/billing` | **`/var/www/billing`** | **Pending Live Read-Only Check After SSH Key Setup** |
| **Active Portal Domain** | Previously cited as `billing.iqra.edu.pk` / `iqra.edu.pk` | Must be confirmed from `/etc/nginx/sites-enabled/*` or `/etc/apache2/sites-enabled/*` and `/var/www/billing/.env` (`APP_URL`) | **Pending Live Read-Only Check** |
| **Live Database Identity** | Previously cited as `u393409297_iqra_billing` | Must be confirmed via `grep -E '^(DB_DATABASE|APPROVED_DATABASE_NAME|RECONCILIATION_ALLOW_APPLY)=' /var/www/billing/.env` | **Pending Live Read-Only Check** |
| **PHP / MariaDB / Git State** | Local: PHP `8.2.12`, MariaDB `10.4.32`, Git `94ebf25` | Must be confirmed on `srv1572606` (`/var/www/billing`) via read-only commands in Section 1.2 | **Pending Live Read-Only Check** |

### 1.2 SSH Connectivity Diagnostic & Rule 9 Stop
1. `ssh -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o ConnectTimeout=10 -p 65002 u393409297@145.14.146.15 "whoami && hostname"`
   - Result: `u393409297@145.14.146.15: Permission denied (publickey,password).`
2. `ssh -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o ConnectTimeout=10 root@200.234.41.189 "whoami && hostname && ls -la /var/www/billing"`
   - Result: `root@200.234.41.189: Permission denied (publickey,password).`

Because the agent shell runs non-interactively (`BatchMode=yes`) and **Rule 2** prohibits requesting or printing passwords or private keys in chat, the owner can enable passwordless SSH access from their own terminal outside chat (`ssh-copy-id root@200.234.41.189`) or run the exact read-only verification commands below directly on `srv1572606` (`200.234.41.189`):

```bash
# Read-only environment verification on Hostinger VPS (srv1572606 / 200.234.41.189)
cd /var/www/billing
hostname && pwd
git rev-parse HEAD
git status -u
php -v
php artisan migrate:status

# Confirm active portal domain and non-secret database/guard settings (never prints passwords)
grep -E '^(APP_NAME|APP_ENV|APP_URL|DB_CONNECTION|DB_HOST|DB_PORT|DB_DATABASE|APPROVED_DATABASE_NAME|RECONCILIATION_ALLOW_APPLY)=' /var/www/billing/.env
grep -rn "server_name\|root " /etc/nginx/sites-enabled/ /etc/apache2/sites-enabled/ 2>/dev/null
```

---

## 2. Backup Locations & Checksum Verification Procedure for Hostinger VPS (Task 3)

Before any code pull or migration on `srv1572606` (`200.234.41.189`, `/var/www/billing`), execute the following read-only backup and verification block on the VPS (and optionally `scp` the encrypted/compressed archive to the local workstation):

```bash
# 1. Create isolated backup directory outside /var/www/billing
export RELEASE_TS=$(date -u +"%Y%m%dT%H%M%SZ")
export BACKUP_DIR="/root/backups/iqra_billing_phase5_${RELEASE_TS}"
export APP_DIR="/var/www/billing"
mkdir -p "${BACKUP_DIR}"
chmod 700 "${BACKUP_DIR}"

# 2. Record pre-deployment Git revision, status, migration state, and runtime versions
cd "${APP_DIR}"
git rev-parse HEAD > "${BACKUP_DIR}/git_commit_before.txt"
git status -u > "${BACKUP_DIR}/git_status_before.txt"
php artisan migrate:status > "${BACKUP_DIR}/migrate_status_before.txt"
php -v > "${BACKUP_DIR}/php_version.txt"

# 3. Preserve production .env securely (permissions 600, never exposed in logs)
cp -p "${APP_DIR}/.env" "${BACKUP_DIR}/dotenv.backup"
chmod 600 "${BACKUP_DIR}/dotenv.backup"

# 4. Consistent InnoDB hot backup (--single-transaction does not lock active cashiers)
DB_HOST_VAL=$(grep -E '^DB_HOST=' .env | cut -d '=' -f2- | tr -d '"\r')
DB_PORT_VAL=$(grep -E '^DB_PORT=' .env | cut -d '=' -f2- | tr -d '"\r')
DB_NAME_VAL=$(grep -E '^DB_DATABASE=' .env | cut -d '=' -f2- | tr -d '"\r')
DB_USER_VAL=$(grep -E '^DB_USERNAME=' .env | cut -d '=' -f2- | tr -d '"\r')
DB_PASS_VAL=$(grep -E '^DB_PASSWORD=' .env | cut -d '=' -f2- | tr -d '"\r')

MYSQL_PWD="${DB_PASS_VAL}" mysqldump \
  --host="${DB_HOST_VAL:-127.0.0.1}" \
  --port="${DB_PORT_VAL:-3306}" \
  --user="${DB_USER_VAL}" \
  --single-transaction \
  --routines \
  --triggers \
  --events \
  --hex-blob \
  --default-character-set=utf8mb4 \
  "${DB_NAME_VAL}" > "${BACKUP_DIR}/${DB_NAME_VAL}_${RELEASE_TS}.sql"

gzip -9 -k "${BACKUP_DIR}/${DB_NAME_VAL}_${RELEASE_TS}.sql"

# 5. Archive deployed application files and storage/uploaded documents
tar -czf "${BACKUP_DIR}/app_files_${RELEASE_TS}.tar.gz" \
  --exclude='vendor' \
  --exclude='node_modules' \
  --exclude='storage/logs/*.log' \
  -C "${APP_DIR}" .

tar -czf "${BACKUP_DIR}/storage_${RELEASE_TS}.tar.gz" -C "${APP_DIR}" storage

# 6. Generate and verify SHA-256 checksums and SQL dump completion footer
cd "${BACKUP_DIR}"
sha256sum "${DB_NAME_VAL}_${RELEASE_TS}.sql" \
          "${DB_NAME_VAL}_${RELEASE_TS}.sql.gz" \
          "app_files_${RELEASE_TS}.tar.gz" \
          "storage_${RELEASE_TS}.tar.gz" > "${BACKUP_DIR}/checksums.sha256"

sha256sum -c "${BACKUP_DIR}/checksums.sha256"
tail -n 5 "${BACKUP_DIR}/${DB_NAME_VAL}_${RELEASE_TS}.sql"
# Must show: "-- Dump completed on 2026-..."
```

### Isolated Backup Restore Verification (Non-Destructive)
On the VPS (where `root` database access is available), verify the SQL dump can be restored cleanly into a temporary isolated schema (**never** into the live database):
```bash
mysql -e "CREATE DATABASE iqra_backup_verify_tmp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql iqra_backup_verify_tmp < "${BACKUP_DIR}/${DB_NAME_VAL}_${RELEASE_TS}.sql"
mysql -e "SELECT COUNT(*) AS invoices_cnt FROM iqra_backup_verify_tmp.invoices; DROP DATABASE iqra_backup_verify_tmp;"
```

---

## 3. Final Git Release Commit & Manifest (Task 4)

- **Branch:** `master`
- **Previous `HEAD` Commit:** `98a9b38` (`style(fee-structure): fix checkbox sizing and align multi-grade selector layout`)
- **Pinned Phase 5 Release Commit:** **`94ebf25964925474c876210229f9dde0d5435b6c`** (`94ebf25`)
- **Commit Message:** `feat(billing): historical payment reconciliation, voided fee restoration, and fee eligibility guards`
- **Remote Push Status:** **NOT PUSHED** (held locally until explicit owner authorization to push and deploy).

### Complete 31-File Release Manifest (`94ebf25`)
| # | Status | File Path | Role in Release |
| :-: | :--- | :--- | :--- |
| 1 | `A` | [`config/reconciliation.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/config/reconciliation.php) | Fail-closed reconciliation configuration (`allow_apply = false` default, `2026-10-22` cutoff). |
| 2 | `A` | [`database/migrations/2026_10_28_000020_create_qa_historical_settlements_table.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/database/migrations/2026_10_28_000020_create_qa_historical_settlements_table.php) | Additive migration creating `qa_historical_settlements` and `qa_historical_settlement_collections` with `ON DELETE RESTRICT` FKs and unique active locks. |
| 3 | `M` | [`database/seeders/DatabaseSeeder.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/database/seeders/DatabaseSeeder.php) | Non-destructive super-admin seeder. |
| 4 | `A` | [`app/Console/Commands/AuditMySkoolReconciliationCommand.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Console/Commands/AuditMySkoolReconciliationCommand.php) | Read-only CLI audit command `billing:audit-myskool-reconciliation`. |
| 5 | `A` | [`app/Console/Commands/ReconcileMySkoolHistoricalPaymentsCommand.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Console/Commands/ReconcileMySkoolHistoricalPaymentsCommand.php) | Guarded CLI dry-run, apply, and rollback command `reconciliation:myskool-historical`. |
| 6 | `A` | [`app/Console/Commands/RestoreVoidedFeeCommand.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Console/Commands/RestoreVoidedFeeCommand.php) | Forensic CLI inspection and guarded restoration command `billing:restore-voided-fee`. |
| 7 | `M` | [`app/Console/Commands/QaResetTestLedgerCommand.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Console/Commands/QaResetTestLedgerCommand.php) | Local QA safety guards. |
| 8 | `M` | [`app/Http/Controllers/BillingController.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Http/Controllers/BillingController.php) | Added `previewChallanFee()`, `inspectVoidedFee()`, and `restoreVoidedFee()`; integrated `FeeEligibilityService`. |
| 9 | `M` | [`app/Services/BatchDispatchLock.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/BatchDispatchLock.php) | Cross-driver named locks (`GET_LOCK`/`RELEASE_LOCK` on MySQL/MariaDB). |
| 10 | `M` | [`app/Services/FeeAdjustmentService.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/FeeAdjustmentService.php) | Added `recreateVoidedFee()` with explicit actor validation and historical settlement checks. |
| 11 | `A` | [`app/Services/FeeEligibilityService.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/FeeEligibilityService.php) | 7-state fee creation eligibility engine. |
| 12 | `M` | [`app/Services/InvoiceService.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/InvoiceService.php) | Updated `refreshInvoice()` and recurring invoice generation guards. |
| 13 | `A` | [`app/Services/VoidedFeeRestorationService.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/VoidedFeeRestorationService.php) | Forensic inspection and atomic restoration of voided fee items. |
| 14 | `M` | [`app/Services/Migration/HistoricalOpeningBalanceService.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/Migration/HistoricalOpeningBalanceService.php) | Historical obligation map and duplicate billing prevention. |
| 15 | `A` | [`app/Services/Migration/HistoricalPaymentReconciliationService.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/Migration/HistoricalPaymentReconciliationService.php) | Guarded preview, locked atomic settlement apply, and compensating reversal service. |
| 16 | `A` | [`app/Services/Migration/MySkoolReconciliationAuditService.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/Migration/MySkoolReconciliationAuditService.php) | Read-only deterministic audit & classification engine (includes Phase 5 Task 1 fixes for `GR 4814` and `GR 6562`). |
| 17 | `M` | [`app/Services/Migration/QaControlledReimportService.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/Migration/QaControlledReimportService.php) | Staging re-import safety guard. |
| 18 | `M` | [`resources/views/collection.blade.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/resources/views/collection.blade.php) | 7-status Create Fee preview table and Forensic Voided Fee Restoration modal. |
| 19 | `M` | [`resources/views/pdf/consolidated-challan.blade.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/resources/views/pdf/consolidated-challan.blade.php) | Consolidated challan rendering alignment. |
| 20 | `M` | [`resources/views/pdf/receipt.blade.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/resources/views/pdf/receipt.blade.php) | Historical slip display alignment. |
| 21 | `M` | [`routes/web.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/routes/web.php) | Added `/challan/preview-fee`, `/challan/inspect-voided-fee/{itemId}`, and `/challan/restore-voided-fee`. |
| 22–31 | `A/M` | `tests/Feature/*` (10 test files) | Automated feature and regression test suites (including `HistoricalPaymentReconciliationTest`, `MySkoolReconciliationAuditTest`, `VoidedFeeRestorationTest`). |

---

## 4. Controlled Code-Only Deployment Status & Procedure (Task 5)

- **Deployment Execution Status:** **PENDING SSH ACCESS & EXPLICIT TASK 5 OWNER AUTHORIZATION** (No remote changes were executed).
- Once SSH key access to `root@200.234.41.189` (`/var/www/billing`) is active and the Section 2 backup is verified, execute the controlled code-only release using the exact sequence below:

```bash
# 1. Push pinned commit 94ebf25 from local workstation (after owner authorization)
git push origin 94ebf25964925474c876210229f9dde0d5435b6c:master

# 2. On Hostinger VPS (srv1572606 / 200.234.41.189): enter maintenance mode
cd /var/www/billing
php artisan down --render="errors::503" --retry=60

# 3. Verify .env safety guards (do NOT overwrite .env)
# Ensure APPROVED_DATABASE_NAME matches DB_DATABASE and RECONCILIATION_ALLOW_APPLY=false
grep -E '^(DB_DATABASE|APPROVED_DATABASE_NAME|RECONCILIATION_ALLOW_APPLY)=' .env

# 4. Fetch and deploy exact pinned commit 94ebf25
git fetch origin
git merge --ff-only 94ebf25964925474c876210229f9dde0d5435b6c
composer install --no-dev --optimize-autoloader --no-interaction

# 5. Inspect pending migrations BEFORE execution
php artisan migrate:status
php artisan migrate --pretend
# Confirm ONLY 2026_10_28_000020_create_qa_historical_settlements_table is pending

# 6. Run additive migration, rebuild Laravel caches, and bring application up
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan up
```

---

## 5. Migration Results & Schema Verification (Task 5)

1. **Local Verification (`100% PASS`):**
   - Migration [`2026_10_28_000020_create_qa_historical_settlements_table.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/database/migrations/2026_10_28_000020_create_qa_historical_settlements_table.php) is purely additive: it creates `qa_historical_settlements` and `qa_historical_settlement_collections` with `ON DELETE RESTRICT` foreign keys and database-level unique constraints (`uq_qa_hist_settlements_active_item`, `uq_qa_hist_settlements_active_primary_col`, `uq_qa_hist_active_staged_collection`).
   - Verified on both SQLite (`420` tests) and MariaDB `10.4.32` (`77` reconciliation, restoration, and accounting tests, `645` assertions — `100% PASS`).
2. **Hostinger Production Migration Status:**
   - **Pending live execution** on `/var/www/billing` (`srv1572606`) after SSH key authentication and Section 2 backup completion.

---

## 6. Application Smoke Tests (Task 6)

1. **Local Automated Functional & Smoke Verification (`PASS`):**
   - Login, authentication, and role-based access gates (`super_admin`, `finance_admin`, `admin`, `cashier`).
   - Fee Collection (`/collection`), Paid Invoices (`/paid-invoices`), and Fee History (`FeeHistoryService`).
   - Read-only Create Fee preview (`POST /challan/preview-fee`) across all 7 eligibility statuses without saving.
   - Read-only previously voided fee forensic inspection (`GET /challan/inspect-voided-fee/{itemId}` and `php artisan billing:restore-voided-fee <ID>`) without restoration.
   - Consolidated Challan PDF, individual Invoice PDF, and Receipt PDF rendering.
   - Multi-currency family summary isolation (`SAR`, `AED`, `PKR`, `QAR`, `OMR`).
2. **Live Hostinger Post-Deploy Smoke Test Checklist (Pending Deployment):**
   - Verify HTTP `200` on `/login`, `/dashboard`, `/collection`, `/paid-invoices`, and `/reports`.
   - Search Family `5929` on `/collection` and run a non-mutating Create Fee preview and voided-fee inspection.
   - Confirm `storage/logs/laravel.log` shows zero `500` or SQL errors.

---

## 7. Live Database Differences & Corrected Read-Only Audit Queries (Task 7)

### 7.1 Schema Column Correction for Live Hostinger Audit Queries
During local baseline verification against `iqra_billing`, we verified that:
- `qa_staged_dues` stores the minor-unit balance in **`outstanding_amount_minor`** (not `balance_minor`).
- `qa_opening_balance_lineage` stores the minor-unit amount in **`amount_minor`** (and `posted_net_minor`).

Use the corrected read-only SQL script below on Hostinger (`/var/www/billing`) immediately after taking the backup:

```sql
-- 1. Database identity, engine version, and core table counts
SELECT VERSION() AS db_version, DATABASE() AS active_db;
SELECT id, migration, batch FROM migrations ORDER BY id DESC LIMIT 10;

SELECT
  (SELECT COUNT(*) FROM families) AS families_count,
  (SELECT COUNT(*) FROM students) AS students_count,
  (SELECT COUNT(*) FROM enrollments) AS enrollments_count,
  (SELECT COUNT(*) FROM invoices) AS invoices_count,
  (SELECT COUNT(*) FROM invoice_items) AS invoice_items_count,
  (SELECT COUNT(*) FROM payments) AS payments_count,
  (SELECT COUNT(*) FROM payment_allocations) AS payment_allocations_count,
  (SELECT COUNT(*) FROM fee_adjustments) AS fee_adjustments_count,
  (SELECT COUNT(*) FROM qa_staged_dues) AS staged_dues_count,
  (SELECT COUNT(*) FROM qa_staged_collections) AS staged_collections_count,
  (SELECT COUNT(*) FROM qa_opening_balance_lineage) AS ob_lineage_count;

-- 2. Active MySkool batch distribution on Hostinger
SELECT batch_id, COUNT(*) AS dues_rows, SUM(outstanding_amount_minor) AS total_outstanding_minor
FROM qa_staged_dues GROUP BY batch_id;

SELECT batch_id, COUNT(*) AS collection_rows, SUM(amount_minor) AS total_collection_minor
FROM qa_staged_collections GROUP BY batch_id;

-- 3. Per-currency summary of live cashier payments, allocations, and adjustments
SELECT currency, status, COUNT(*) AS payment_count,
       SUM(amount_minor) AS total_paid_minor,
       SUM(unallocated_minor) AS total_unallocated_minor
FROM payments GROUP BY currency, status ORDER BY currency, status;

SELECT p.currency, COUNT(*) AS alloc_count, SUM(pa.amount_minor) AS total_allocated_minor
FROM payment_allocations pa
JOIN payments p ON p.id = pa.payment_id
GROUP BY p.currency ORDER BY p.currency;

SELECT fa.kind, ii.currency, COUNT(*) AS adjustment_count, SUM(fa.amount_minor) AS total_adjusted_minor
FROM fee_adjustments fa
JOIN invoice_items ii ON ii.id = fa.invoice_item_id
GROUP BY fa.kind, ii.currency ORDER BY fa.kind, ii.currency;

-- 4. Previously voided opening-balance items and live portal payment conflicts on OB items
SELECT ii.id AS invoice_item_id, i.invoice_number, s.gr_number, s.name AS student_name,
       ii.currency, ii.description, ii.period_key,
       ii.amount_minor, ii.discount_minor, ii.exemption_minor, ii.net_minor, ii.paid_minor, ii.status AS item_status,
       COALESCE((SELECT SUM(pa.amount_minor) FROM payment_allocations pa WHERE pa.invoice_item_id = ii.id), 0) AS allocated_minor,
       (SELECT COUNT(*) FROM fee_adjustments fa WHERE fa.invoice_item_id = ii.id) AS adjustments_count
FROM invoice_items ii
JOIN invoices i ON i.id = ii.invoice_id
JOIN billing_runs br ON br.id = i.billing_run_id
JOIN students s ON s.id = ii.student_id
WHERE br.frequency = 'opening_balance'
  AND (
    ii.paid_minor > 0
    OR ii.discount_minor > 0
    OR ii.exemption_minor > 0
    OR ii.status IN ('paid', 'partial', 'void', 'voided', 'exempted')
    OR EXISTS (SELECT 1 FROM payment_allocations pa WHERE pa.invoice_item_id = ii.id AND pa.amount_minor > 0)
    OR EXISTS (SELECT 1 FROM fee_adjustments fa WHERE fa.invoice_item_id = ii.id)
  )
ORDER BY ii.currency, s.gr_number, ii.id;
```

---

## 8. Actual Reconciliation Classifications — Verified Baseline After Task 1 Fix (Task 1 & Task 7)

Executing the verified read-only commands against `iqra_billing` (`BATCH-20261025-FINAL-MYSKOOL`, cutoff `2026-10-22`, `10,257` opening-balance items, `34,712` staged collections):
- `php artisan billing:audit-myskool-reconciliation --cutoff=2026-10-22 --batch-id=BATCH-20261025-FINAL-MYSKOOL --export=csv --output=storage/app/reconciliation/phase5_local_reconciliation_audit.csv`
- `php artisan reconciliation:myskool-historical --dry-run --cutoff=2026-10-22 --batch-id=BATCH-20261025-FINAL-MYSKOOL --export-csv=storage/app/reconciliation/phase5_local_reconciliation_preview.csv`

### 8.1 Overall Classification Summary (`10,257` Opening-Balance Items)
| Guarded Classification (`reconciliation:myskool-historical`) | Audit Classification (`billing:audit-myskool-reconciliation`) | Item Count | Original Obligation (Minor) | Verified Settlement (Minor) | Remaining Obligation (Minor) | Phase 5 Disposition |
| :--- | :--- | ---: | ---: | ---: | ---: | :--- |
| **`CANDIDATE_FULL_SETTLEMENT`** | `POST_CUTOFF_FULL_SETTLEMENT` | **`246`** | `9,482,100` | `9,482,100` | `0` | Candidate for Phase 6 full settlement after live Hostinger dry-run & Finance approval |
| **`CANDIDATE_PARTIAL_SETTLEMENT`** | `POST_CUTOFF_PARTIAL_PAYMENT` | **`13`** | `394,900` | `212,700` | `182,200` | Candidate for Phase 6 partial cash settlement (`3` items flagged for discount pattern check) |
| **`KEEP_GENUINE_RESIDUAL`** | `PRE_CUTOFF_RESIDUAL_BALANCE` | **`211`** | `4,598,400` | `0` | `4,598,400` | **Protected genuine unpaid residual** (now includes `GR 4814`'s two split `May-2026` lines: `AED 210 + AED 250 = AED 460`) |
| **`CONCESSION_REVIEW`** | `POST_CUTOFF_SIBLING_CONCESSION` | **`6`** | `166,000` | `128,500` | `37,500` | Held for explicit Finance decision on unpaid discount delta (includes `GR 5930` `QAR 50`) |
| **`CURRENCY_CONFLICT_HOLD`** | `MANUAL_REVIEW_CURRENCY_MISMATCH` | **`2`** | `66,000` | `0` | `66,000` | Hard-blocked (`GR 8620`, `Sep-2026` & `Oct-2026`, `AED 330` OB vs `SAR 330` collection) |
| **`DUPLICATE_SOURCE_HOLD`** | `MANUAL_REVIEW_AMBIGUOUS_MATCH` | **`2`** | `40,000` | `0` | `40,000` | Hard-blocked (`GR 6562`, `Oct-2026`, `AED 200 + AED 200 = AED 400` vs `AED 200` monthly fee) |
| **`LIVE_ACTIVITY_HOLD`** | `CONFLICT_EXISTING_PORTAL_ACTIVITY` | **`0`** *(local)* | `0` | `0` | `0` | Hard-blocked (must be re-evaluated on Hostinger live DB) |
| **Total Evaluated Overlap/Hold Items** | — | **`480`** | **`14,747,400`** | **`9,823,300`** | **`4,924,100`** | **100% Reconciled** |
| **Unmatched Genuine OB Items** | `UNMATCHED_OPENING_BALANCE` | **`9,777`** | `484,170,400` | `0` | `484,170,400` | Untouched genuine unpaid opening balances |
| **Total Opening-Balance Items** | — | **`10,257`** | **`498,917,800`** | **`9,823,300`** | **`489,094,500`** | — |

### 8.2 Separate Per-Currency Breakdown by Classification (Task 7 Requirement)

| Guarded Classification | Currency | Item Count | Original Obligation | Verified Settlement | Remaining Obligation | Unmatched Overpayment |
| :--- | :--- | ---: | ---: | ---: | ---: | ---: |
| **`CANDIDATE_FULL_SETTLEMENT` (`246`)** | `SAR` | `163` | `SAR 42,150.00` (`4215000`) | `SAR 42,150.00` (`4215000`) | `SAR 0.00` (`0`) | `SAR 0.00` (`0`) |
| | `AED` | `80` | `AED 20,391.00` (`2039100`) | `AED 20,391.00` (`2039100`) | `AED 0.00` (`0`) | `AED 0.00` (`0`) |
| | `PKR` | `2` | `PKR 32,000.00` (`3200000`) | `PKR 32,000.00` (`3200000`) | `PKR 0.00` (`0`) | `PKR 0.00` (`0`) |
| | `QAR` | `1` | `QAR 280.00` (`28000`) | `QAR 280.00` (`28000`) | `QAR 0.00` (`0`) | `QAR 0.00` (`0`) |
| **`CANDIDATE_PARTIAL_SETTLEMENT` (`13`)** | `SAR` | `8` | `SAR 2,459.00` (`245900`) | `SAR 1,276.00` (`127600`) | `SAR 1,183.00` (`118300`) | `SAR 0.00` (`0`) |
| | `AED` | `5` | `AED 1,490.00` (`149000`) | `AED 851.00` (`85100`) | `AED 639.00` (`63900`) | `AED 0.00` (`0`) |
| **`KEEP_GENUINE_RESIDUAL` (`211` Overlap)** | `SAR` | `128` | `SAR 14,252.00` (`1425200`) | `SAR 0.00` (`0`) | `SAR 14,252.00` (`1425200`) | `SAR 0.00` (`0`) |
| | `AED` | `80` | `AED 11,572.00` (`1157200`) | `AED 0.00` (`0`) | `AED 11,572.00` (`1157200`) | `AED 0.00` (`0`) |
| | `PKR` | `2` | `PKR 20,000.00` (`2000000`) | `PKR 0.00` (`0`) | `PKR 20,000.00` (`2000000`) | `PKR 0.00` (`0`) |
| | `QAR` | `1` | `QAR 160.00` (`16000`) | `QAR 0.00` (`0`) | `QAR 160.00` (`16000`) | `QAR 0.00` (`0`) |
| **`CONCESSION_REVIEW` (`6`)** | `SAR` | `4` | `SAR 1,160.00` (`116000`) | `SAR 895.00` (`89500`) | `SAR 265.00` (`26500`) | `SAR 0.00` (`0`) |
| | `QAR` | `1` | `QAR 280.00` (`28000`) | `QAR 230.00` (`23000`) | `QAR 50.00` (`5000`) | `QAR 0.00` (`0`) |
| | `AED` | `1` | `AED 220.00` (`22000`) | `AED 160.00` (`16000`) | `AED 60.00` (`6000`) | `AED 0.00` (`0`) |
| **`CURRENCY_CONFLICT_HOLD` (`2`)** | `AED` | `2` | `AED 660.00` (`66000`) | `AED 0.00` (`0`) | `AED 660.00` (`66000`) | `AED 0.00` (`0`) |
| **`DUPLICATE_SOURCE_HOLD` (`2`)** | `AED` | `2` | `AED 400.00` (`40000`) | `AED 0.00` (`0`) | `AED 400.00` (`40000`) | `AED 0.00` (`0`) |
| **`LIVE_ACTIVITY_HOLD` (`0` Local)** | All | `0` | `0.00` (`0`) | `0.00` (`0`) | `0.00` (`0`) | `0.00` (`0`) |

### 8.3Aggregate Per-Currency Reconciliation Summary Across All `480` Evaluated Items
$$\text{Original Obligation} = \text{Verified Settlement} + \text{Remaining Obligation} + \text{Approved Adjustments}$$

| Currency | Items | Original Obligation | Verified Settlement | Remaining Obligation | Approved Adjustments | Unmatched Overpayment | Invariant Check |
| :--- | ---: | ---: | ---: | ---: | ---: | ---: | :--- |
| **`SAR`** | `303` | `SAR 60,021.00` (`6002100`) | `SAR 44,321.00` (`4432100`) | `SAR 15,700.00` (`1570000`) | `SAR 0.00` (`0`) | `SAR 0.00` (`0`) | **MATCH (100%)** |
| **`AED`** | `170` | `AED 34,733.00` (`3473300`) | `AED 21,402.00` (`2140200`) | `AED 13,331.00` (`1333100`) | `AED 0.00` (`0`) | `AED 0.00` (`0`) | **MATCH (100%)** |
| **`PKR`** | `4` | `PKR 52,000.00` (`5200000`) | `PKR 32,000.00` (`3200000`) | `PKR 20,000.00` (`2000000`) | `PKR 0.00` (`0`) | `PKR 0.00` (`0`) | **MATCH (100%)** |
| **`QAR`** | `3` | `QAR 720.00` (`72000`) | `QAR 510.00` (`51000`) | `QAR 210.00` (`21000`) | `QAR 0.00` (`0`) | `QAR 0.00` (`0`) | **MATCH (100%)** |
| **Total** | **`480`** | — | — | — | — | — | **100% Balanced** |

---

## 9. Resolution of Task 1 Discrepancies, Conflicts & Holds (Task 1 & Task 7)

### 9.1 `GR 4814` (`Beenal Seetamonee`, Family `4814`) — Resolved to `KEEP_GENUINE_RESIDUAL`
- **Why `GR 4814` Was Previously Misclassified as `DUPLICATE_SOURCE_HOLD`:**
  - Prior to Phase 5 Task 1, [`MySkoolReconciliationAuditService.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/Migration/MySkoolReconciliationAuditService.php) flagged any student/period with more than one opening-balance item (`$obIdentityCounts[$identityKey] > 1`) as `MANUAL_REVIEW_AMBIGUOUS_MATCH` (`DUPLICATE_SOURCE_HOLD`) **before** checking whether any post-cutoff payments existed or whether the split lines summed to the student's single monthly fee.
- **Verified Ledger Arithmetic for `GR 4814` (`Monthly Fee - May-2026`):**
  - Enrollment monthly fee: **`AED 500.00`** (`50000` minor).
  - Pre-cutoff historical payment: **`AED 40.00`** (`4000` minor) on `2026-07-03` (`Slip 24127`, `qa_staged_collections.id = 67672`).
  - Post-cutoff historical payment (`> 2026-10-22`): **`AED 0.00`**.
  - Opening-balance items (local IDs `#13069` and `#13070` on `SCH-OB-2026-0000081-40`): **`AED 210.00` (`21000`) + `AED 250.00` (`25000`) = `AED 460.00` (`46000` minor)**.
  - Total: $\text{AED } 40.00 + \text{AED } 210.00 + \text{AED } 250.00 = \text{AED } 500.00$.
- **Phase 5 Resolution:**
  - Both lines are now deterministically classified as **`PRE_CUTOFF_RESIDUAL_BALANCE` (`guard_class = KEEP_GENUINE_RESIDUAL`)** with `verified_settlement_minor = 0` and `remaining_obligation_minor = 21000` and `25000` (`AED 460.00` combined). Neither line is waived, voided, or settled.

### 9.2 `GR 6562` (`Maryam Kashif`, Family `6562`) — Held in `DUPLICATE_SOURCE_HOLD`
- **Verified Ledger Arithmetic for `GR 6562` (`Monthly Fee - Oct-2026`):**
  - Enrollment monthly fee: **`AED 200.00`** (`20000` minor).
  - Opening-balance items (local IDs `#15193` and `#15194` on `SCH-OB-2026-0000085-645`, from `qa_staged_dues` source rows `3496` and `3497`): **`AED 200.00` (`20000`) + `AED 200.00` (`20000`) = `AED 400.00` (`40000` minor)** (`200%` of her `AED 200.00` monthly fee).
  - Historical collections for `Oct-2026`: **`0` rows** (her latest paid month in `qa_staged_collections` is `Jul-2026` on `2026-09-24`, `Slip 25323`).
- **Phase 5 Resolution:**
  - Previously, because `GR 6562` had zero `Oct-2026` collection rows, the audit loop skipped her under `unmatched_ob_items`.
  - With the Phase 5 Task 1 fix, [`MySkoolReconciliationAuditService.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/Migration/MySkoolReconciliationAuditService.php) detects that `GR 6562` has `2` opening-balance items for `Monthly Fee - Oct-2026` totaling `AED 400.00` against an `AED 200.00` monthly fee (`40000 > 20000`) and places **both items on `DUPLICATE_SOURCE_HOLD` (`MANUAL_REVIEW_AMBIGUOUS_MATCH`)** with `verified_settlement_minor = 0` and `remaining_obligation_minor = 20000` each. Neither item is automatically voided or waived; the hold is preserved until Finance confirms the duplicate row in Phase 6.

### 9.3 Currency Conflict Hold (`GR 8620`) & Historical Allocation Conflict Guards
- **`CURRENCY_CONFLICT_HOLD` (`2` items — `GR 8620`, `Ibrahim yahya`, Family `8563`):**
  - `Monthly Fee - Sep-2026` (`AED 330.00`) and `Monthly Fee - Oct-2026` (`AED 330.00`) are billed in **`AED`**, whereas `Slip 27256` (`2026-10-23`) recorded **`SAR 330.00`** for each month. Hard-blocked from auto-settlement until Finance aligns the currency.
- **Historical Receipt Allocation Conflict Protection:**
  - `qa_historical_settlement_collections` enforces `UNIQUE (active_staged_collection_id)` at the database level alongside connection-scoped named locks (`BatchDispatchLock`, `hist_col_alloc_{$cid}`) and `SELECT ... FOR UPDATE` on `qa_staged_collections`, preventing any historical receipt line from ever being allocated to more than one invoice item.

---

## 10. Family `5929` (`GR 5929` & `GR 5930`, Slip `27328`) Recovery & Reconciliation Inspection (Task 8)

### 10.1 Verified Baseline Records by Stable Identity (`family_code = '5929'`, `gr_number IN ('5929', '5930')`)
| Field / Verification Dimension | `GR 5929` (`Khadija Feroz`) | `GR 5930` (`Zahra Feroz`) |
| :--- | :--- | :--- |
| **Family Code & Currency** | `family_code = '5929'`, `QAR` | `family_code = '5929'`, `QAR` |
| **Enrollment Monthly Fee** | **`QAR 280.00`** (`28000` minor) | **`QAR 280.00`** (`28000` minor) |
| **Oct-2026 Opening-Balance Invoice** | `SCH-OB-2026-0000085-512` (local `invoices.id = 10236`, `total_minor = 56000`, `paid_minor = 0`) | Shared parent invoice `SCH-OB-2026-0000085-512` |
| **Oct-2026 OB Item & Current Status** | Local `invoice_items.id = 14328` (`Monthly Fee - Oct-2026`, `status = 'active'`, **not voided locally**) | Local `invoice_items.id = 14329` (`Monthly Fee - Oct-2026`, `status = 'active'`, **not voided locally**) |
| **Original Oct-2026 Obligation** | **`QAR 280.00`** (`amount_minor = 28000`, `net_minor = 28000`) | **`QAR 280.00`** (`amount_minor = 28000`, `net_minor = 28000`) |
| **Historical Payment (`Slip 27328`, `2026-10-24`)** | **`QAR 280.00`** (`28000` minor, `source_row = 34702`) | **`QAR 230.00`** (`23000` minor, `source_row = 34703`) |
| **Expected Historical Settlement** | **`QAR 280.00`** (`28000` minor $\rightarrow$ `status = 'paid'`, `remaining = 0`) | **`QAR 230.00`** (`23000` minor $\rightarrow$ `remaining = QAR 50.00` [`5000` minor] subject to concession review) |
| **Reconciliation Classification** | `POST_CUTOFF_FULL_SETTLEMENT` / **`CANDIDATE_FULL_SETTLEMENT`** | `POST_CUTOFF_SIBLING_CONCESSION` / **`CONCESSION_REVIEW`** |
| **Existing Adjustments / Allocations** | `0` payment allocations, `0` fee adjustments locally | `0` payment allocations, `0` fee adjustments locally |
| **Nov-2026 Regular Obligation** | `SCH-2026-0016199` (local `invoice_items.id = 22449`), **`QAR 280.00`**, untouched | `SCH-2026-0016199` (local `invoice_items.id = 22450`), **`QAR 280.00`**, untouched |

### 10.2 Read-Only Inspection Commands for Hostinger Live Database (`/var/www/billing`)
Because local numeric IDs (`14328`, `14329`, `10236`) must **never** be assumed to match Hostinger, run the following read-only commands on `srv1572606` (`/var/www/billing`) once SSH key access is enabled to inspect whether `GR 5929` or `GR 5930` was voided on Hostinger:

```bash
# 1. Find live Hostinger invoice_item_id values and statuses for GR 5929 and GR 5930
php artisan tinker --execute="
print_r(DB::table('students as s')
  ->join('families as f', 'f.id', '=', 's.family_id')
  ->leftJoin('invoice_items as ii', 'ii.student_id', '=', 's.id')
  ->leftJoin('invoices as i', 'i.id', '=', 'ii.invoice_id')
  ->whereIn('s.gr_number', ['5929', '5930'])
  ->select('s.gr_number', 'f.family_code', 'i.invoice_number', 'i.status as inv_status', 'ii.id as item_id', 'ii.description', 'ii.period_key', 'ii.currency', 'ii.amount_minor', 'ii.discount_minor', 'ii.net_minor', 'ii.paid_minor', 'ii.status as item_status')
  ->orderBy('s.gr_number')->orderBy('ii.id')->get()->toArray());
"

# 2. Run read-only reconciliation dry-run preview for GR 5929 and GR 5930
php artisan reconciliation:myskool-historical --dry-run --gr=5929 --cutoff=2026-10-22
php artisan reconciliation:myskool-historical --dry-run --gr=5930 --cutoff=2026-10-22

# 3. If either Oct-2026 item has item_status='voided' on Hostinger, run read-only forensic restoration inspection (NO --apply):
php artisan billing:restore-voided-fee <LIVE_HOSTINGER_ITEM_ID>
```

---

## 11. Financial Before/After Verification (Task 9)

### 11.1 Local Canonical Database (`iqra_billing`) Before/After Verification
Zero financial mutations were executed during Phase 5. The before and after states of `iqra_billing` are **100% identical**:

| Financial Ledger / Staging Metric | Currency / Scope | Before Phase 5 | After Phase 5 | Delta (Mutations) |
| :--- | :--- | ---: | ---: | ---: |
| **Portal Payments (`payments`)** | `SAR` (`status = 'received'`) | `1` row (`28,000` minor = `SAR 280.00`) | `1` row (`28,000` minor = `SAR 280.00`) | **`0`** |
| **Payment Allocations (`payment_allocations`)** | `SAR` | `1` row (`28,000` minor = `SAR 280.00`) | `1` row (`28,000` minor = `SAR 280.00`) | **`0`** |
| **Fee Adjustments (`fee_adjustments`)** | `SAR` (`kind = 'void'`) | `2` rows (`58,000` minor = `SAR 580.00`) | `2` rows (`58,000` minor = `SAR 580.00`) | **`0`** |
| **Invoices (`invoices` — `10,286` rows)** | `SAR` (`6,287` rows) | `total = 239,198,200` \| `paid = 28,000` | `total = 239,198,200` \| `paid = 28,000` | **`0`** |
| | `AED` (`3,848` rows) | `total = 150,743,200` \| `paid = 0` | `total = 150,743,200` \| `paid = 0` | **`0`** |
| | `PKR` (`133` rows) | `total = 247,150,000` \| `paid = 0` | `total = 247,150,000` \| `paid = 0` | **`0`** |
| | `QAR` (`13` rows) | `total = 655,500` \| `paid = 0` | `total = 655,500` \| `paid = 0` | **`0`** |
| | `OMR` (`5` rows) | `total = 28,800` \| `paid = 0` | `total = 28,800` \| `paid = 0` | **`0`** |
| **Invoice Items (`invoice_items` — `14,143` rows)** | All Currencies (`14,141` active, `2` voided) | `net = 637,775,700` \| `paid = 28,000` | `net = 637,775,700` \| `paid = 28,000` | **`0`** |
| **Staged Dues (`qa_staged_dues`)** | `BATCH-20261025-FINAL-MYSKOOL` | `10,329` rows (`500,660,300` minor) | `10,329` rows (`500,660,300` minor) | **`0`** |
| **Staged Collections (`qa_staged_collections`)** | `BATCH-20261025-FINAL-MYSKOOL` | `34,712` rows | `34,712` rows | **`0`** |
| **OB Lineage (`qa_opening_balance_lineage`)** | `BATCH-20261025-FINAL-MYSKOOL` | `10,257` rows (`498,917,800` minor) | `10,257` rows (`498,917,800` minor) | **`0`** |
| **Historical Settlements (`qa_historical_settlements`)** | All | `0` rows | `0` rows | **`0`** |

---

## 12. Remaining Risks (Task 10.12)

1. **Unverified Live Hostinger Cashier Overlap until SSH Audit Runs:**
   - Because SSH publickey authentication to `root@200.234.41.189` (`srv1572606`) was not pre-configured in the non-interactive shell, the live Hostinger database counts and active cashier receipts have not yet been queried. Any opening-balance item on Hostinger that already has a cashier payment, allocation, discount, exemption, or void will automatically be placed in `LIVE_ACTIVITY_HOLD` by [`HistoricalPaymentReconciliationService`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/Migration/HistoricalPaymentReconciliationService.php).
2. **Hostinger `.env` Guard Alignment (`APPROVED_DATABASE_NAME` & `RECONCILIATION_ALLOW_APPLY`):**
   - On `/var/www/billing/.env`, the operator must verify that `APPROVED_DATABASE_NAME` matches `DB_DATABASE` and that `RECONCILIATION_ALLOW_APPLY=false` remains set during code deployment.

---

## 13. Exact Items Requiring Finance Approval Before Phase 6 Execution (Task 10.13)

No financial apply command may be executed in Phase 6 until Finance reviews the live Hostinger dry-run CSV and provides written sign-off on the following five categories:

1. **Snapshot Cutoff Date Sign-Off (`2026-10-22`):**
   - Confirm `2026-10-22` (`Slip 27162`) as the official MySkool outstanding-balance snapshot cutoff date.
2. **Full-Settlement Candidates (`246` items in local baseline; verify live count on Hostinger):**
   - `SAR 42,150.00` (`163` items), `AED 20,391.00` (`80` items), `PKR 32,000.00` (`2` items), `QAR 280.00` (`1` item — `GR 5929` `Oct-2026` on `Slip 27328`).
3. **Partial-Settlement Candidates (`13` items in local baseline: `SAR 1,276.00` settled / `SAR 1,183.00` remaining across `8` items; `AED 851.00` settled / `AED 639.00` remaining across `5` items):**
   - Approve settling the verified Oct 23–24 partial cash payments while keeping the unpaid remainders open, and review whether `GR 6191` (`AED 45` diff), `GR 6769` (`SAR 100` diff), or `GR 7021` (`AED 70` diff) have authorized concessions.
4. **Post-Cutoff Concession-Review Items (`6` items in local baseline, plus `10` pre-cutoff recurring reduced-rate items kept in `KEEP_GENUINE_RESIDUAL`):**
   - **`GR 5930` (`Zahra Feroz`, Family `5929`, `Oct-2026`):** Paid `QAR 230.00` on `Slip 27328` (`2026-10-24`) against `QAR 280.00` obligation. Finance must decide whether the remaining **`QAR 50.00`** is an authorized sibling discount (and whether her ongoing `Nov-2026` fee plan should be updated from `QAR 280` to `QAR 230`) or remains collectible.
   - **`GR 5130` (`Oct-2026`):** Paid `SAR 270.00` vs `SAR 330.00` (`SAR 60.00` diff).
   - **`GR 5168` (`Aug-2026`):** Paid `SAR 100.00` vs `SAR 200.00` (`SAR 100.00` diff).
   - **`GR 5502` (`Sep-2026`):** Paid `SAR 330.00` vs `SAR 400.00` (`SAR 70.00` diff).
   - **`GR 7364` (`Sep-2026`):** Paid `SAR 195.00` vs `SAR 230.00` (`SAR 35.00` diff).
   - **`GR 7266` (`Oct-2026`):** Paid `AED 160.00` vs `AED 220.00` (`AED 60.00` diff).
5. **Anomaly Holds (`GR 8620` Currency Conflict & `GR 6562` Duplicate Source Hold) and Voided-Fee Restorations:**
   - **`GR 8620` (`Sep-2026` & `Oct-2026`):** Confirm whether `GR 8620`'s true billing currency is `AED` or `SAR` (`330.00`/month) before settling `Slip 27256`.
   - **`GR 6562` (`Oct-2026`, `AED 200.00 + AED 200.00 = AED 400.00` vs `AED 200.00` monthly fee):** Confirm that the second `AED 200.00` line (`source_row = 3497`, local `invoice_items.id = 15194`) is an accidental workbook duplicate before voiding/adjusting it in Phase 6.
   - **Voided-Fee Restorations:** Approve the exact live Hostinger `invoice_item_id` values (if any items for Family `5929` or others were voided on Hostinger) after reviewing the read-only `php artisan billing:restore-voided-fee <LIVE_ITEM_ID>` inspection output.

---

## 14. GO or NO-GO Recommendation for Phase 6 (Task 10.14)

- **Code Release (`94ebf25`): GO FOR DEPLOYMENT TO `/var/www/billing` (`srv1572606` / `200.234.41.189`)** as soon as SSH key connectivity is enabled and the Section 2 backup + checksum verification passes.
- **Phase 6 Financial Apply & Live Restoration: CONDITIONAL NO-GO (HOLD) UNTIL:**
  1. Commit `94ebf25` is deployed to `/var/www/billing` on `srv1572606` (`200.234.41.189`) after a verified backup.
  2. The read-only live audit commands (`php artisan billing:audit-myskool-reconciliation` and `php artisan reconciliation:myskool-historical --dry-run`) and Family `5929` inspection are executed against the live Hostinger database.
  3. Finance provides written approval on the exported live Hostinger candidate CSV and the specific items listed in Section 13.
