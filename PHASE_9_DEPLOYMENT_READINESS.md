# IQRA Virtual School — Phase 9: Production Deployment Readiness Report

**Application Version / Commit:** `c2ebea5`  
**Test Suite Status:** 205 Tests Passed (1,257 Assertions) — 100% Green  
**Canonical Database:** `iqra_billing` (MariaDB / MySQL)  
**Database Backup File:** `backups/iqra_billing_production_ready_20261024.sql`  
**Backup Size:** 21,705,966 bytes (20.7 MB)  
**Backup SHA-256 Checksum:** `264d99d9da23bfb298d52c38f82022339bee3409d378ba47592c47edcc548d21`  
**Imported Baseline:** 4,358 approved students, 38,068 historical collections, 11,351 opening balance items (100% lineage)  

---

## 1. Deployment Readiness Audit

A thorough audit of the code repository, dependencies, runtime assumptions, and filesystem interactions was conducted:

| Category | Finding / Requirement | Operational Impact / Recommendation |
| :--- | :--- | :--- |
| **PHP Version** | PHP `>= 8.2` (Specifically PHP 8.2 or 8.3) | Production host must run PHP 8.2 or 8.3 CLI and FPM. |
| **Required PHP Extensions** | `pdo_mysql`, `mbstring`, `gd`, `zip`, `xml`, `dom`, `curl`, `fileinfo`, `ctype`, `tokenizer`, `session`, `bcmath`, `opcache` | `ext-gd` and `ext-zip` are mandatory for Dompdf and PhpSpreadsheet. `ext-mbstring` is mandatory for Arabic student names. `opcache` is critical for production performance. |
| **Composer Dependencies** | `laravel/framework: ^12.0`, `barryvdh/laravel-dompdf: ^3.1`, `phpoffice/phpspreadsheet: ^5.10` | Run `composer install --no-dev --optimize-autoloader` on production. No development packages will be installed. |
| **Frontend Build** | **Zero npm / Node.js build steps required.** | All styling is implemented in plain vanilla CSS and static SVG/PNG assets located in `public/assets/`. Node.js and npm are **NOT** required on the production server. |
| **PDF Rendering** | Dompdf (`barryvdh/laravel-dompdf`) | Server requires `memory_limit >= 256M` (512M recommended) and `max_execution_time >= 60s` to render multi-student family statements without timeout. |
| **Queue Workers & Jobs** | Database queue (`jobs` and `failed_jobs` tables) | Outbound webhook notification job `DispatchInvoiceToN8n` runs on queue. Run queue worker via systemd: `php artisan queue:work database --sleep=3 --tries=3`. |
| **Scheduled Tasks** | Single entry point (`php artisan schedule:run`) | Single crontab entry required: `* * * * * cd /var/www/iqra-billing && php artisan schedule:run >> /dev/null 2>&1`. |
| **Hardcoded Paths / URLs** | **Zero hardcoded Windows paths or localhost URLs in core runtime.** | All paths use standard Laravel helpers (`public_path()`, `storage_path()`, `base_path()`). Migration review dashboard host display was updated to use dynamic configuration (`config('database.connections.mysql.host')`). |

---

## 2. Deployment Blockers Audit

| Potential Issue | Audit Result | Status / Resolution |
| :--- | :--- | :--- |
| **Database Identity Lock** | `DatabaseSafetyGuard` previously hardcoded `iqra_billing`. | **RESOLVED:** Updated `DatabaseSafetyGuard::getApprovedTargetDatabase()` to support `APPROVED_DATABASE_NAME` while strictly failing closed against system schemas, retired schemas, and unexpected databases. |
| **Local Test Records in Ledger** | 1 manual student (GR: 01), 3 manual invoices, and 2 manual payments exist from functional verification. | **CATALOGED:** Identified with complete IDs and dates. Provided explicit decision script (`scripts/manual_test_records_removal_choice.sql`). No data deleted automatically. |
| **Re-posting Opening Balances** | Accidental re-posting would corrupt ledger. | **LOCKED:** `ALLOW_OPENING_BALANCE_POSTING=false` is enforced in `config/imports.php` and `.env.production.example`. |
| **Development Files in Production** | Raw workbooks, scratch scripts, local backups, and `.git` must not be exposed. | **ISOLATED:** Production virtual host points strictly to `public/`. Deploy archive excludes scratch, backups, workbooks, and tests. |

---

## 3. Recommended Hosting Architecture

### Architecture Comparison

| Evaluation Metric | Option A: Dedicated Linux VPS (Recommended) | Option B: cPanel / Shared Hosting |
| :--- | :--- | :--- |
| **Web Server Root Isolation** | **STRICT (Nginx/Apache):** Document root points exclusively to `/var/www/iqra-billing/public`. Codebase, `.env`, `.git`, and `storage/` are completely inaccessible from the web. | **RISKY:** Shared hosting default is `public_html/`. Requires manual document root override or `.htaccess` redirects, creating information disclosure risks. |
| **Long-Running Queue Worker** | **NATIVE:** Managed via Linux `systemd` daemon (`iqra-worker.service`). Automatically restarts on reboot or failure. | **LIMITED / UNSUPPORTED:** Shared hosting kills long-running CLI processes. Requires cron polling workarounds. |
| **Database Identity & Grants** | **CANONICAL:** Native MySQL 8.0/MariaDB instance where database is named `iqra_billing` directly with dedicated restricted user. | **RESTRICTED:** cPanel forces database prefixes (e.g. `cpaneluser_iqra_billing`), requiring `APPROVED_DATABASE_NAME` configuration. |
| **Memory & Execution Limits** | **CUSTOMIZABLE:** FPM `memory_limit = 512M`, `max_execution_time = 120s`. Prevents PDF timeout on large challans. | **RESTRICTED:** Shared hosts frequently cap memory at 128M and execution time at 30s. |
| **SSL / HTTPS Management** | **AUTOMATED:** Let's Encrypt Certbot via Nginx plugin with automatic renewal and TLS 1.3. | **MANAGED:** AutoSSL / cPanel SSL certificate manager. |

### Architecture Recommendation: **Option A (Linux VPS with Nginx)**
- **Operating System:** Ubuntu 24.04 LTS or 22.04 LTS (x86_64)
- **Web Server:** Nginx 1.24+ (Reverse proxy with FastCGI to PHP-FPM)
- **PHP Version:** PHP 8.2-FPM or 8.3-FPM with Zend OPcache enabled
- **Database Server:** MySQL 8.0+ or MariaDB 10.11+
- **Process Manager:** `systemd` for `php artisan queue:work`
- **Sizing:** Minimum 2 vCPU, 4 GB RAM, 40 GB SSD (8 GB RAM recommended for multi-user peak billing seasons)

---

## 4. Production Database Preparation

### 4.1. Verified Production Baseline Dump
- **File:** `backups/iqra_billing_production_ready_20261024.sql`
- **Size:** 21,705,966 bytes (20.7 MB)
- **SHA-256:** `264d99d9da23bfb298d52c38f82022339bee3409d378ba47592c47edcc548d21`
- **Character Set:** UTF-8 (`utf8mb4_unicode_ci`)
- **Contents:** 45 tables including 4,358 imported students, 8,098 opening balance invoices, 11,351 invoice items, 11,351 lineage tracking records, and 38,068 staged historical collections.

### 4.2. Post-Posting Manual Test Records (For Explicit Decision)
During Phase 8 and Phase 8.1 functional verification, test transactions were generated against Family `3063` (Student GR: `0000001` and Student GR: `01`). In accordance with strict safety procedures, **these records have not been altered or deleted**.

The school administration should review and decide whether to **KEEP** or **REMOVE** these test items before public launch:

| Record Type | IDs / Numbers | Description | Date Created | Recommendation |
| :--- | :--- | :--- | :--- | :--- |
| **Manual Student** | ID `4359` (GR: `01`, "test") | Manually created student under family 3063. *(Note: Student 4307 / GR 0000001 is a real imported student from MySkool and is preserved).* | 2026-10-24 05:55:48 | **Remove** if pure test student, or **Keep** if family 3063 requested this record. |
| **Manual Invoices** | ID `8099` (`SCH-2026-0008099`)<br>ID `8100` (`SCH-2026-0008100`)<br>ID `8103` (`SCH-2026-0008103`) | September 2026 (Paid, 250 SAR)<br>October 2026 (Partial, 580 SAR)<br>November 2026 (Generated, 580 SAR) | 2026-10-24 05:56:20<br>2026-10-24 05:56:20<br>2026-10-24 06:01:19 | If removing test payments, remove these invoices so family 3063 starts clean with only opening balance. |
| **Manual Payments** | ID `1` (`RCP-2026-0000001`)<br>ID `2` (`RCP-2026-0000002`) | 400.00 SAR allocated to invoices 8099 & 8100<br>400.00 SAR unallocated test receipt | 2026-10-24 05:56:34<br>2026-10-24 06:02:17 | **Remove** before production so receipt numbers start sequentially with real collections. |

> [!TIP]
> A complete, transaction-safe removal script has been prepared in [`scripts/manual_test_records_removal_choice.sql`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/scripts/manual_test_records_removal_choice.sql). If removal is chosen, run:
> ```bash
> mysql -u iqra_billing_user -p iqra_billing < scripts/manual_test_records_removal_choice.sql
> ```

---

## 5. Security & Configuration Template

### 5.1. Production Environment Template ([`.env.production.example`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/.env.production.example))
```ini
APP_NAME="IQRA Virtual School - Billing Portal"
APP_ENV=production
# Preserve existing APP_KEY from current deployment. Do NOT run key:generate!
APP_KEY=base64:COPIED_FROM_SOURCE_ENV_EXACTLY
APP_DEBUG=false
APP_URL=https://billing.iqraschool.edu.sa
APP_TIMEZONE=Asia/Karachi
APP_LOCALE=en

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=iqra_billing
APPROVED_DATABASE_NAME=iqra_billing
DB_USERNAME=iqra_billing_user
DB_PASSWORD="STRONG_RANDOMLY_GENERATED_PASSWORD"

SESSION_DRIVER=file
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax

CACHE_STORE=file
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local

LOG_CHANNEL=daily
LOG_LEVEL=warning
LOG_DAILY_DAYS=14

ALLOW_OPENING_BALANCE_POSTING=false
ACTIVE_IMPORT_VERSION=v3_final_myskool_20261023
ACTIVE_IMPORT_BATCH_ID=BATCH-20261023-FINAL-MYSKOOL
```

### 5.2. Restricted Database User Creation
In MySQL, create a dedicated limited-privilege database user rather than using `root`:
```sql
CREATE DATABASE IF NOT EXISTS `iqra_billing` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'iqra_billing_user'@'localhost' IDENTIFIED BY 'STRONG_RANDOMLY_GENERATED_PASSWORD';

-- Grant required operational privileges
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX, ALTER, LOCK TABLES, CREATE TEMPORARY TABLES 
ON `iqra_billing`.* TO 'iqra_billing_user'@'localhost';

-- Do NOT grant SUPER, SHUTDOWN, PROCESS, or global RELOAD privileges
FLUSH PRIVILEGES;
```

---

## 6. Exact 12-Step Production Deployment Procedure

### Step 1: Server Provisioning & OS Setup
1. Deploy an Ubuntu 24.04 LTS VPS.
2. Update packages and configure standard firewall (`ufw`):
   ```bash
   sudo apt update && sudo apt upgrade -y
   sudo apt install -y ufw fail2ban
   sudo ufw allow OpenSSH
   sudo ufw allow 'Nginx Full'
   sudo ufw enable
   ```
3. Install PHP 8.2, PHP-FPM, MySQL Server, and required extensions:
   ```bash
   sudo apt install -y php8.2 php8.2-fpm php8.2-mysql php8.2-mbstring php8.2-xml \
       php8.2-gd php8.2-curl php8.2-zip php8.2-bcmath php8.2-intl php8.2-opcache \
       mysql-server nginx composer unzip
   ```

### Step 2: Code Release
1. Create application release directory:
   ```bash
   sudo mkdir -p /var/www/iqra-billing
   sudo chown -R $USER:www-data /var/www/iqra-billing
   ```
2. Clone repository or extract clean release archive (excluding local test files, `.git`, and private workbooks):
   ```bash
   git clone <REPO_URL> /var/www/iqra-billing
   cd /var/www/iqra-billing
   git checkout c2ebea5
   ```

### Step 3: Composer Production Installation
Run non-development dependency installation with optimized classmap:
```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
```

### Step 4: Frontend Static Assets
Verify static assets in `public/assets/`. No build step or Node.js runtime is needed:
```bash
ls -la /var/www/iqra-billing/public/assets
```

### Step 5: Environment Configuration
1. Copy template and configure production values:
   ```bash
   cp .env.production.example .env
   chmod 600 .env
   ```
2. **Preserve Application Key:** Copy the existing `APP_KEY` from the approved source `.env` file. **Do not run `php artisan key:generate`**.
3. Fill in the production database credentials and `APP_URL`. Ensure `APP_DEBUG=false` and `ALLOW_OPENING_BALANCE_POSTING=false`.

### Step 6: Database Restoration & Checksum Validation
1. Securely transfer `iqra_billing_production_ready_20261024.sql` to `/tmp/` on the server via SFTP or SCP.
2. Verify checksum on the production server:
   ```bash
   sha256sum /tmp/iqra_billing_production_ready_20261024.sql
   # MUST MATCH: 264d99d9da23bfb298d52c38f82022339bee3409d378ba47592c47edcc548d21
   ```
3. Import into MySQL:
   ```bash
   mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS iqra_billing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   mysql -u root -p iqra_billing < /tmp/iqra_billing_production_ready_20261024.sql
   rm -f /tmp/iqra_billing_production_ready_20261024.sql
   ```
4. Confirm table row counts match canonical counts:
   ```bash
   mysql -u iqra_billing_user -p iqra_billing -e "SELECT COUNT(*) FROM students; SELECT COUNT(*) FROM invoices; SELECT COUNT(*) FROM qa_opening_balance_lineage;"
   # Expected: students >= 4358, invoices >= 8098, lineage = 11351
   ```

### Step 7: Database Safety Guard Validation
Run the automated safety guard test against the production database:
```bash
php artisan test tests/Feature/DatabaseBootSafetyGuardTest.php
```

### Step 8: Permissions & Storage Linking
1. Set strict ownership and permissions:
   ```bash
   sudo chown -R www-data:www-data /var/www/iqra-billing/storage /var/www/iqra-billing/bootstrap/cache
   sudo chmod -R 775 /var/www/iqra-billing/storage /var/www/iqra-billing/bootstrap/cache
   sudo chmod 600 /var/www/iqra-billing/.env
   ```
2. Create public storage symlink:
   ```bash
   php artisan storage:link
   ```

### Step 9: Laravel Production Caching & Optimization
Cache config, routes, views, and events for maximum throughput:
```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

### Step 10: HTTPS & Web Server Setup
1. Copy Nginx virtual host configuration:
   ```bash
   sudo cp deployment/nginx/iqra_billing.conf /etc/nginx/sites-available/iqra_billing
   sudo ln -s /etc/nginx/sites-available/iqra_billing /etc/nginx/sites-enabled/
   sudo rm -f /etc/nginx/sites-enabled/default
   sudo nginx -t
   sudo systemctl reload nginx
   ```
2. Issue Let's Encrypt SSL certificate:
   ```bash
   sudo certbot --nginx -d billing.iqraschool.edu.sa
   ```
3. Enable and start queue worker:
   ```bash
   sudo cp deployment/systemd/iqra-worker.service /etc/systemd/system/
   sudo systemctl daemon-reload
   sudo systemctl enable --now iqra-worker
   sudo systemctl status iqra-worker
   ```
4. Setup cron scheduler:
   ```bash
   (crontab -l 2>/dev/null; echo "* * * * * cd /var/www/iqra-billing && php artisan schedule:run >> /dev/null 2>&1") | crontab -
   ```

### Step 11: Production Smoke Testing
Conduct non-destructive verification:
1. Load `https://billing.iqraschool.edu.sa/login` (Verify HTTPS lock and security headers).
2. Log in with the approved administrator account.
3. Search student GR `4348` or family `68` in Fee Collection.
4. Click **Print Challan (PDF)** and verify clean multi-currency challan rendering.
5. Submit a duplicate Create Fee test and confirm user-friendly warning is returned without SQL errors.

### Step 12: Rollback Plan
If an unexpected issue occurs during cutover:
1. Revert DNS A-record to previous server (TTL should be set to 300s prior to deployment).
2. Restore database from pre-deployment snapshot:
   ```bash
   mysql -u root -p iqra_billing < backups/iqra_billing_production_ready_20261024.sql
   ```
3. Clear cached configuration:
   ```bash
   php artisan optimize:clear
   ```

---

## 7. Security and Backup Checklist

- [x] **Zero Web Exposure:** Web server document root strictly points to `/public`. Access to `.env`, `.git`, `storage/`, `composer.json`, and SQL dumps is blocked.
- [x] **SSL / TLS 1.3:** HSTS enabled (`max-age=31536000`), secure cookies enabled (`SESSION_SECURE_COOKIE=true`), session encrypted (`SESSION_ENCRYPT=true`).
- [x] **Restricted DB Privileges:** Database user has zero `SUPER` or `GRANT` privileges and is restricted to `localhost`.
- [x] **Fail-Closed Safety Guard:** `DatabaseSafetyGuard` validates active database against `APPROVED_DATABASE_NAME` and rejects quarantine or system schemas.
- [x] **Financial Protection:** `ALLOW_OPENING_BALANCE_POSTING=false` prevents accidental re-posting.
- [x] **Encryption Key Integrity:** Existing `APP_KEY` is preserved without re-generating to prevent data loss on encrypted attributes.
- [x] **Automated Encrypted Backups:** Daily backup script configured in [`deployment/scripts/backup.sh`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/deployment/scripts/backup.sh) with AES-256 encryption and 30-day rotation.
- [x] **Password Protection:** No plaintext passwords, API keys, or student personal data stored in repository or deployment scripts.

---

## 8. Summary of Created Deployment Assets

1. [`PHASE_9_DEPLOYMENT_READINESS.md`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/PHASE_9_DEPLOYMENT_READINESS.md) — Comprehensive deployment reference.
2. [`.env.production.example`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/.env.production.example) — Hardened production environment template.
3. [`backups/iqra_billing_production_ready_20261024.sql`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/backups/iqra_billing_production_ready_20261024.sql) — Verified production baseline SQL dump (SHA-256: `264d99d9da23bfb298d52c38f82022339bee3409d378ba47592c47edcc548d21`).
4. [`scripts/manual_test_records_removal_choice.sql`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/scripts/manual_test_records_removal_choice.sql) — Decision script for post-posting test records.
5. [`deployment/nginx/iqra_billing.conf`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/deployment/nginx/iqra_billing.conf) — Production Nginx virtual host with SSL, security headers, and public root isolation.
6. [`deployment/apache/iqra_billing.conf`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/deployment/apache/iqra_billing.conf) — Production Apache virtual host alternative.
7. [`deployment/systemd/iqra-worker.service`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/deployment/systemd/iqra-worker.service) — Production background queue worker daemon.
8. [`deployment/scripts/backup.sh`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/deployment/scripts/backup.sh) — Production automated daily AES-256 encrypted database backup script.
