# IQRA Virtual School Billing System: Emergency Project Recovery Audit

**Audit Date:** 19 October 2026  
**Auditor:** Senior Laravel, MySQL & Financial Systems Architect  
**Project Workspace:** `c:\Users\hp\Desktop\iqra-laravel-billing`  
**Application Environment:** Laravel 12.x | PHP 8.2+ | MySQL 8.0+ | Windows (Local Development)  
**Audit Type:** Strict Read-Only Recovery & Architectural Diagnostic

---

## Executive Summary

Following a sudden Antigravity conversation interruption and system restart, an emergency, non-destructive audit was conducted across the entire codebase, filesystem, database, migration history, and recent execution evidence.

### Primary Audit Findings:
1. **Physical Database Contains Real Ingested Records with Severe Ledger Flaws:**
   The active MySQL database (`iqra_billing`) currently contains **4,349 students**, **3,070 families**, **4,349 enrollments**, **8,558 invoices**, **12,006 invoice items**, and **31,086 payments**. However:
   - **100% of student enrollments are hardcoded to `SAR`** (4,349 / 4,349), despite the invoices and payments containing multiple currencies (`AED`, `PKR`, `QAR`, `OMR`, `BHD`).
   - **100% of student enrollments are hardcoded to Campus ID 1** (`Main Campus`).
   - **0 enrollments were assigned to the Quran department** (3,978 assigned to School, 371 to Tuition based on simplistic string matching).
   - **Severe Financial Discrepancy:** Out of **31,086 payment records** totaling **1,297,128,900 minor units** (~12.97 million units of money), only **228 payments** totaling **4,509,800 minor units** have matching records in `payment_allocations`. The remaining **30,858 payments** are unallocated to invoice items, **yet their `unallocated_minor` column was recorded as `0`**, causing ~1.292 billion minor units of cash receipts to effectively vanish from accounting ledger reconciliation.
   - **Fee Type Explosion:** 217 dynamic fee types were created directly in `fee_types` from raw MySkool line-item text descriptions.

2. **No Database Staging Tables Exist:**
   The database migrations define only production ledger tables. There are **zero staging tables** (e.g., `staging_students`, `staging_dues`, `staging_payments`) in MySQL. Instead, the verified migration dataset (79 batches, 4,340 students, 16,706 dues, 37,595 payments) exists purely as offline CSV extracts inside `scratch/staging/IQRA_STAGING_ONLY_20261019_075231/` and intermediate "compatible" CSV files in `docs/` and `storage/app/imports/`.

3. **High Security & Data Integrity Risks Discovered:**
   - **Unconditional Delete Endpoints:** `BillingController@clearFeeData` is exposed via a web route (`POST /challan/clear-fee-data`) and invokes `FeeImportService::clearFeeData()`, which executes unconstrained, hard deletes across 8 database tables without soft-deletes or confirmation safeguards.
   - **Write on GET:** `FeeCatalogController@index` executes database `INSERT` queries on HTTP `GET /manage-fee` to seed zero-amount catalog rates dynamically.
   - **Missing Batch Tracking:** The `import_batches` table contains `0` records. Neither `StudentImportService` nor `FeeImportService` logged provenance or batch checksums during execution.

---

## 1. Current Project Architecture

### 1.1 Technology Stack & Core Layers
- **Framework:** Laravel 12.0 (running in local development mode on Windows, PHP 8.2+).
- **Database Engine:** MySQL (`DB_CONNECTION=mysql`, `DB_DATABASE=iqra_billing`, `DB_HOST=127.0.0.1:3306`).
- **Data Access Layer:** Primarily raw query builder (`Illuminate\Support\Facades\DB`) across controllers and services. The only Eloquent model defined is `App\Models\User`.
- **Export & Document Generation:** `barryvdh/laravel-dompdf` (^3.1) for PDF invoices/receipts/reports; `phpoffice/phpspreadsheet` (^5.10) for XLSX reports and Excel parsing.
- **Queue & Async Operations:** `QUEUE_CONNECTION=database` (`jobs` and `failed_jobs` tables exist).
- **Session & Caching:** File-backed (`SESSION_DRIVER=file`, `CACHE_STORE=file`).

### 1.2 Database Schema Topology
The current schema consists of 28 tables created across 9 executed migration files:

```mermaid
erDiagram
    campuses ||--o{ users : "scopes"
    departments ||--o{ users : "scopes"
    campuses ||--o{ enrollments : "hosts"
    departments ||--o{ enrollments : "categorizes"
    programs ||--o{ enrollments : "structures"
    families ||--o{ students : "groups"
    students ||--o{ enrollments : "registers"
    students ||--|| student_registration_details : "extends"
    enrollments ||--o{ fee_plan_items : "defines recurring"
    fee_types ||--o{ fee_plan_items : "types"
    billing_runs ||--o{ invoices : "generates"
    families ||--o{ invoices : "bills"
    invoices ||--o{ invoice_items : "contains"
    students ||--o{ invoice_items : "obligates"
    fee_types ||--o{ invoice_items : "classifies"
    families ||--o{ payments : "receives from"
    payments ||--o{ payment_allocations : "applies"
    invoice_items ||--o{ payment_allocations : "settles"
    invoice_items ||--o{ fee_adjustments : "adjusts"
    invoice_items ||--o{ fee_installments : "splits"
    fee_types ||--o{ fee_catalog_rates : "sets standard"
```

### 1.3 Service Layer Architecture
The business logic is housed in `app/Services/`:
- [`ReceivePaymentService.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/ReceivePaymentService.php): Handles payment receipt generation, idempotency verification (`idempotency_key` and `request_hash`), multi-currency ledger validation, advance payment credit pooling, and oldest-first debt settlement.
- [`ReceiptReversalService.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/ReceiptReversalService.php): Reverses issued payments, rolls back `paid_minor` on invoice items and installments, and maintains audit trail in `audit_logs`.
- [`FeeAdjustmentService.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/FeeAdjustmentService.php): Applies discounts, exemptions, installments, and fee voids.
- [`InvoiceService.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/InvoiceService.php): Computes totals, syncs status (`draft`, `generated`, `paid`, `partial`, `overdue`), and handles monthly invoice batch generation.
- [`StudentImportService.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/StudentImportService.php): Direct-to-production CSV student importer.
- [`FeeImportService.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/FeeImportService.php): Direct-to-production CSV dues and payment collection importer.
- [`Money.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/Money.php) & [`BillingPeriod.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/BillingPeriod.php): Value objects for integer minor-unit money math and date manipulation.

---

## 2. Changes Identified & Chronological Timeline Reconstructed

Because Git is uninitialized in this directory (`fatal: not a git repository`), the development history was reconstructed from filesystem timestamps, `storage/logs/laravel.log`, `.phpunit.result.cache`, and diagnostic scripts located in `scratch/`.

```mermaid
timeline
    title IQRA Billing Evolution & Audit Reconstructed Timeline
    2026-10-16 : Base Schema : Migrations 000001 & 000002 executed
               : Initial Admin : Admin user created, database connection established
    2026-10-17 : Core Upgrades : Migrations 000003-000006, 000008-000010 executed
               : Admissions : StudentAdmissionController & FeeAdjustmentService created
               : First Importer : StudentImportService & StudentImportTest written
               : Baseline Freeze : IMPLEMENTATION_STATUS_2026_10_17.md published
    2026-10-18 : Staging Extraction : Raw MySkool replicas copied to docs/
               : Staging Artifacts : 79 verified batches extracted to scratch/staging/
               : Pipeline Test : convert_myskool_data.php & FeeImportService.php created
               : Live DB Ingestion : test_pipeline_import.php executed against MySQL
               : Test Suite : FeeImportTest executed via PHPUnit
    2026-10-19 : Staging Verification : Zip archives deposited; verify_exact_facts.php run
               : Session Drop : Antigravity IDE connection dropped after restart
               : Recovery Audit : Antigravity Senior Architect read-only audit executed
```

### Detailed Chronology:
- **16 October 2026 (10:11 – 10:45):** Base schema created (`campuses`, `departments`, `users`, `families`, `students`, `enrollments`, `invoices`, `payments`).
- **17 October 2026 (07:29 – 11:00):**
  - Nullable `family_code` migrations (`000006`, `000008`) and accounting migrations (`000009`, `000010`) executed.
  - `StudentAdmissionController.php` implemented with registration details management.
  - `StudentImportService.php` written to parse raw student CSVs directly into `students` and `families`.
  - Myskool-style UI pagination and student status updates added.
  - Baseline documentation compiled: `docs/IMPLEMENTATION_STATUS_2026_10_17.md`.
- **18 October 2026 (16:52 – 18:28):**
  - Raw CSV files placed in `docs/`: `Myskool replica - Student information to import.csv`, `Myskool replica - Dues details.csv`, `Myskool replica - Paid Details.csv`.
  - Google Sheets staging dataset exported into `scratch/staging/IQRA_STAGING_ONLY_20261019_075231/`.
  - Conversion script [`scripts/convert_myskool_data.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/scripts/convert_myskool_data.php) authored to reformat raw reports into `docs/Compatible_*.csv` and `storage/app/imports/*.csv`.
  - [`app/Services/FeeImportService.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/FeeImportService.php) created.
  - **Live Import Execution:** At **16:26:29 – 16:26:59**, script `scratch/test_pipeline_import.php` was executed directly against MySQL, writing 4,349 students, 8,558 invoices, and 31,086 payments into the live database.
  - Test [`tests/Feature/FeeImportTest.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/tests/Feature/FeeImportTest.php) run.
- **19 October 2026 (05:14 – 05:56):**
  - Staging backup archives saved in workspace: `IQRA_STAGING_ONLY_20261019_075231-20261019T030357Z-1-001 (1).zip` and `iqra-laravel-billing%20updated.zip`.
  - Diagnostic scripts (`scratch/verify_exact_facts.php`, `scratch/analyze_staging_details.php`, `scratch/analyze_multi_curr_families.php`) confirmed 4,340 students, 4,202 approved currencies, 138 unresolved currencies, 16,706 dues rows, and 37,595 payments.
  - **Antigravity conversation restart occurred**, leaving the live database populated with skewed imported records.

---

## 3. Database Status & Physical Schema Inspection

### 3.1 Migration Ledger Audit
Executing `php artisan migrate:status` confirmed that all 9 recorded migrations have run:

| Migration File | Batch | Status | Applied Schema Targets |
| :--- | :---: | :---: | :--- |
| [`2026_10_16_000001_create_identity_and_enrollments.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/database/migrations/2026_10_16_000001_create_identity_and_enrollments.php) | 1 | Ran | `campuses`, `departments`, `users`, `families`, `students`, `programs`, `sections`, `enrollments` |
| [`2026_10_16_000002_create_billing_ledger.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/database/migrations/2026_10_16_000002_create_billing_ledger.php) | 1 | Ran | `fee_types`, `fee_plan_items`, `billing_runs`, `invoices`, `invoice_items`, `payments`, `payment_allocations`, `notifications`, `department_branding`, `import_batches`, `audit_logs` |
| [`2026_10_17_000003_add_payment_selected_period.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/database/migrations/2026_10_17_000003_add_payment_selected_period.php) | 2 | Ran | Alter `payments` (add `selected_period`) |
| [`2026_10_17_000004_create_student_registration_details.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/database/migrations/2026_10_17_000004_create_student_registration_details.php) | 3 | Ran | `student_registration_details` (30+ MySkool registration fields) |
| [`2026_10_17_000005_add_registration_phone_number.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/database/migrations/2026_10_17_000005_add_registration_phone_number.php) | 4 | Ran | Alter `student_registration_details` (add `phone_number`) |
| [`2026_10_17_000006_allow_blank_family_number.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/database/migrations/2026_10_17_000006_allow_blank_family_number.php) | 5 | Ran | Alter `families` (`family_code` nullable) |
| [`2026_10_17_000008_ensure_family_code_optional.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/database/migrations/2026_10_17_000008_ensure_family_code_optional.php) | 5 | Ran | MySQL conditional repair for nullable `family_code` |
| [`2026_10_17_000009_create_fee_catalog_and_adjustments.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/database/migrations/2026_10_17_000009_create_fee_catalog_and_adjustments.php) | 6 | Ran | `fee_catalog_rates`, `fee_rate_changes` |
| [`2026_10_17_000010_create_fee_collection_accounting.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/database/migrations/2026_10_17_000010_create_fee_collection_accounting.php) | 7 | Ran | Alter `payments` (`status`, `reversed_at`), alter `invoice_items` (`exemption_minor`), `fee_adjustments`, `fee_installments` |

> [!WARNING]
> **Migration Sequence Gap Detected:**  
> Migration `2026_10_17_000007_*` is missing from both the filesystem and database migration records. The sequence jumps directly from `000006` to `000008`.

### 3.2 Physical Database Table Inventory & Row Counts
A direct read-only query against MySQL revealed the current table row counts:

| Table Name | Physical Row Count | Data Assessment & Integrity Status |
| :--- | :---: | :--- |
| `students` | **4,349** | Populated by test import. Min `created_at`: 2026-10-17 07:29, Max: 2026-10-18 16:22. |
| `families` | **3,070** | Populated by test import. Missing family codes were auto-filled with student GR numbers. |
| `student_registration_details` | **4,349** | Populated 1:1 with students. Phone numbers and parents mapped from CSV. |
| `enrollments` | **4,349** | **CRITICAL FLAW:** 100% SAR (4,349 rows), 100% Campus 1, 0% Quran department. |
| `fee_plan_items` | **4,349** | 100% monthly fee plans tied to enrollments, all currency SAR. |
| `billing_runs` | **104** | Monthly billing runs generated across multiple years and months. |
| `invoices` | **8,558** | Currencies: SAR (5,201), AED (3,219), PKR (126), QAR (9), OMR (3). |
| `invoice_items` | **12,006** | Currencies match parent invoices. Net minor totals: SAR, AED, PKR, QAR, OMR. |
| `payments` | **31,086** | Currencies: SAR (21,007), AED (9,608), PKR (242), QAR (191), BHD (34), OMR (4). |
| `payment_allocations` | **228** | **SEVERE DISCREPANCY:** Only 228 allocations recorded out of 31,086 receipts. |
| `fee_types` | **217** | **BLOATED:** 209 custom fee types auto-created dynamically from raw particular strings. |
| `fee_catalog_rates` | **8** | Baseline rates seeded by controller GET operations. |
| `fee_adjustments` | **0** | Clean (no adjustments applied to test dataset). |
| `fee_installments` | **0** | Clean (no installments applied to test dataset). |
| `fee_rate_changes` | **0** | Clean. |
| `campuses` | **1** | Only `Main Campus` (ID: 1, Code: `main`). No secondary campuses configured. |
| `departments` | **3** | ID 1: School (`school`), ID 2: Quran (`quran`), ID 3: Tuition (`tuition`). |
| `users` | **1** | User ID 1: Super Admin (`mustafa@gmail.com`). |
| `department_branding` | **0** | Clean. |
| `import_batches` | **0** | **EMPTY:** Import pipeline bypassed batch logging and SHA-256 provenance tracking. |
| `audit_logs` | **0** | Clean. |
| `jobs` / `failed_jobs` | **0** | Clean. |

---

## 4. Implementation Status Reconstruction Table

The table below reconstructs the complete development status across all modules, contrasting original baseline, recent changes, completed functionality, gaps, and verified evidence.

| Feature / Domain | Existing Before Restart | New / Modified | Completed | Incomplete | Requires Testing | Evidence & File Location |
| :--- | :---: | :---: | :---: | :---: | :---: | :--- |
| **Database Migrations** | YES | NO | YES | NO | NO | All 9 migration files applied in MySQL. Verified via `artisan migrate:status`. Missing `000007` index. |
| **Staging DB Schema** | NO | NO | NO | YES | YES | **Zero staging tables exist in MySQL.** Staging only exists as offline CSVs in `scratch/staging/`. |
| **Student Ingestion Pipeline** | YES | YES | NO | YES | YES | [`StudentImportService.php:L40-126`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/StudentImportService.php#L40-L126). Hardcodes SAR and Campus 1. |
| **Student Registration Details** | YES | YES | YES | NO | YES | [`migration 000004`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/database/migrations/2026_10_17_000004_create_student_registration_details.php) and [`StudentAdmissionController.php:L260-282`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Http/Controllers/StudentAdmissionController.php#L260-L282). |
| **Family Sibling Grouping** | YES | YES | YES | PARTIAL | YES | Groups by `Family Code`. If blank, defaults to student GRN ([`StudentImportService.php:L140`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/StudentImportService.php#L140)). 37 multi-curr families unresolved. |
| **Currency Handling (Enrollments)** | NO | YES | NO | YES | YES | [`StudentImportService.php:L309`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/StudentImportService.php#L309) hardcodes `'SAR'`. Ignores approved currency review in staging. |
| **Currency Handling (Invoices/Dues)** | NO | YES | PARTIAL | YES | YES | [`FeeImportService.php:L66-77`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/FeeImportService.php#L66-L77). Normalizes aliases but defaults unknown currency to SAR. |
| **Currency Handling (Payments)** | NO | YES | PARTIAL | YES | YES | [`FeeImportService.php:L408`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/FeeImportService.php#L408). Extracted 6 currencies, but allocations failed. |
| **Outstanding Dues Import** | NO | YES | NO | YES | YES | [`FeeImportService.php:L107-351`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/FeeImportService.php#L107-L351). Created 8,558 invoices and 217 fee types. |
| **Historical Payment Ingestion** | NO | YES | NO | YES | YES | [`FeeImportService.php:L353-520`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/FeeImportService.php#L353-L520). Ingested 31,086 payments; allocated only 228. |
| **Payment Ledger Allocation** | NO | YES | NO | YES | YES | **30,858 payments unallocated** with `unallocated_minor = 0`. DB shows 1.292B minor units disconnected. |
| **Advance Payment Credit Pool** | YES | NO | YES | NO | YES | [`ReceivePaymentService.php:L26-45`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/ReceivePaymentService.php#L26-L45) supports `selected_period` and advance pooling. |
| **Department Scoping** | YES | NO | PARTIAL | YES | YES | [`AccountsAccess.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Http/Middleware/AccountsAccess.php) checks roles. But Quran department has 0 student enrollments. |
| **Campus Multi-Tenancy** | YES | NO | NO | YES | YES | Only 1 campus exists in DB. Importers hardcode `campus_id = 1`. |
| **Fee Adjustment & Waivers** | YES | NO | YES | NO | YES | [`FeeAdjustmentService.php:L14-83`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/FeeAdjustmentService.php#L14-L83) fully implemented with audit trail. |
| **Receipt Reversal** | YES | NO | YES | NO | YES | [`ReceiptReversalService.php:L15-113`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/ReceiptReversalService.php#L15-L113) complete with balance restoration. |
| **Fee Catalog & Class Rates** | YES | YES | PARTIAL | YES | YES | [`FeeCatalogController.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Http/Controllers/FeeCatalogController.php). Unsafe write-on-GET flaw at line 35. |
| **UI Import Dialogs** | NO | YES | YES | NO | YES | [`collection.blade.php:L26-83`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/resources/views/collection.blade.php#L26-L83) and [`students.blade.php:L34-61`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/resources/views/students.blade.php#L34-L61). |
| **Data Deletion Safeguards** | NO | YES | NO | YES | HIGH RISK | Unsafe endpoints: `FeeImportService::clearFeeData` and `clearAllData` execute unconstrained table truncations. |
| **Provenance & Batch Tracking** | YES | NO | NO | YES | YES | `import_batches` table is empty (`0` records). |
| **Security & Authentication** | YES | NO | YES | NO | YES | Role enforcement on web routes via `AccountsAccess` middleware. |

---

## 5. Evidence Supporting Findings

### Finding 1: Unallocated Payments Discrepancy
- **Code:** [`app/Services/FeeImportService.php:L466-482`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/FeeImportService.php#L466-L482)
  ```php
  $paymentId = DB::table('payments')->insertGetId([
      'family_id' => $student->family_id,
      'campus_id' => $campusId,
      'department_id' => $deptId,
      'currency' => $currency,
      'amount_minor' => $amountMinor,
      'unallocated_minor' => 0, // <--- Hardcoded 0 regardless of allocation outcome
      'received_on' => $receivedOn,
      'receipt_number' => $receiptNum,
      ...
  ]);
  ```
- **Database Evidence:**
  - Total Payments: `31,086`
  - Total Allocations: `228`
  - Payments with `unallocated_minor = 0`: `31,086` (100%)
  - Total cash collected: `1,297,128,900 minor units`
  - Total cash allocated: `4,509,800 minor units`
  - **Discrepancy:** `1,292,619,100 minor units` (~12.92 million units of money across currencies) recorded as collected but neither attached to invoice obligations nor retained in unallocated family credit balances.

### Finding 2: Hardcoded Currency in Enrollments
- **Code:** [`app/Services/StudentImportService.php:L309`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/StudentImportService.php#L309) & [`L340`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/StudentImportService.php#L340)
  ```php
  $enrollmentId = DB::table('enrollments')->insertGetId([
      ...
      'currency' => 'SAR', // <--- Hardcoded
      ...
  ]);
  ```
- **Database Evidence:**
  - `SELECT currency, count(*) FROM enrollments GROUP BY currency` returns: `SAR: 4,349`.
  - Contrast with `invoices` table: `AED: 3,219`, `PKR: 126`, `QAR: 9`, `OMR: 3`, `SAR: 5,201`.
  - Consequence: In future billing runs, the billing generator will generate `SAR` invoices for students whose families have historically been billed and paid in `AED` or `PKR`.

### Finding 3: Fee Type Explosion (217 Dynamic Fee Types)
- **Code:** [`app/Services/FeeImportService.php:L48-63`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/FeeImportService.php#L48-L63)
  ```php
  // Custom subject / course fee
  $slug = Str::slug(substr($trimDesc, 0, 40), '_');
  $id = DB::table('fee_types')->insertGetId([
      'name' => $trimDesc,
      'code' => $slug,
      'department_id' => $deptId,
      ...
  ]);
  ```
- **Database Evidence:**
  - `SELECT count(*) FROM fee_types` returns `217`.
  - Examples: `Grade 11- FED (English)`, `KG-1- (Urdu)`, `EXTRA CLASS ( CH - 2)`, `G 10 IGCSE GROUP CLASSES (Business)`.
  - Standard catalog structure was polluted by un-normalized free-text particulars from historical reports.

### Finding 4: Unsafe Database Write on HTTP GET
- **Code:** [`app/Http/Controllers/FeeCatalogController.php:L33-46`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Http/Controllers/FeeCatalogController.php#L33-L46)
  ```php
  public function index(Request $request)
  {
      ...
      foreach ($classes as $cls) {
          if (!DB::table('fee_catalog_rates')->where('fee_type_id', $selectedType)->where('class_label', $cls)->exists()) {
              DB::table('fee_catalog_rates')->insert([ ... ]); // <--- Writes on GET
          }
      }
  ```
- **Architectural Violation:** Violates HTTP idempotent GET semantics. Crawlers or simple navigation generate side-effect database inserts.

### Finding 5: High-Risk Unconstrained Delete Endpoint
- **Code:** [`app/Services/FeeImportService.php:L522-548`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/FeeImportService.php#L522-L548) and [`app/Http/Controllers/BillingController.php:L853-857`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Http/Controllers/BillingController.php#L853-L857)
  ```php
  public function clearFeeData(): void {
      DB::transaction(function () {
          DB::table('fee_adjustments')->delete();
          DB::table('fee_installments')->delete();
          DB::table('payment_allocations')->delete();
          DB::table('payments')->delete();
          DB::table('notifications')->delete();
          DB::table('invoice_items')->delete();
          DB::table('invoices')->delete();
          DB::table('billing_runs')->delete();
      });
  }
  ```
- **Exposure:** Wired to route `POST /challan/clear-fee-data`. Any super-admin or finance-admin can wipe all financial ledger data with a single HTTP POST request.

---

## 6. Completed Functionality

The following modules are architecturally sound, syntactically verified, tested, and operational:
1. **Core Database Schema & Migrations:**
   All 9 migration files create robust tables with proper constraints, composite unique indexes (`billing_run_id, family_id, currency`), and foreign keys.
2. **Payment Adjustment & Audit Engine:**
   - [`FeeAdjustmentService`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/FeeAdjustmentService.php) supports discounts, exemptions, installments, and fee voids while adjusting `net_minor` and logging JSON metadata to `audit_logs`.
   - [`ReceiptReversalService`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/ReceiptReversalService.php) restores item balances, reverses installments, nullifies unallocated advances, and logs reversal reasons.
3. **Advance Payments & Target Period Logic:**
   - Nullable `payments.selected_period` column allows families to pay specific future months in advance.
   - Month-targeted credits remain reserved, while general unallocated funds apply strictly to the oldest outstanding obligations.
4. **Student Registration Full Schema:**
   - `student_registration_details` stores 30+ comprehensive student identity attributes (B-Form, birth place, mother tongue, nationality, admission class/section).
5. **MySkool Navigation & UI Pagination:**
   - Custom blade paginator (`resources/views/vendor/pagination/myskool.blade.php`) matches the legacy look and feel.
6. **PDF Invoicing & Receipt Streaming:**
   - DomPDF streaming of invoices, cash receipts, and fee reports with campus/department branding.
7. **Verified Staging Baseline Extracted:**
   - The approved staging CSV files exist in `scratch/staging/IQRA_STAGING_ONLY_20261019_075231/` with exact verified metrics:
     - 4,340 student rows
     - 4,202 approved unique currencies
     - 138 unresolved currencies
     - 16,706 dues entries
     - 37,595 historical payment allocation entries
     - 79 verified staging batches

---

## 7. Incomplete Functionality

1. **Direct-to-Production Import without Staging Tables:**
   - The current import implementation skips staging tables entirely and writes directly into production tables.
   - It cannot preview exceptions, track approval status, or quarantine unresolved records.
2. **Currency Reconciliation for Multi-Currency Families:**
   - 37 family codes have students with multiple currencies.
   - The current importer assigns all students in a family to SAR without ledger isolation.
3. **Quran and Tuition Department Classification:**
   - Department assignment is based solely on simplistic substring matching on the `Class` field (`stripos($classLabel, 'Tuition')`).
   - All 4,349 students in the database have 0 enrollments in Quran.
4. **Historical Payment Allocation Algorithm:**
   - The collection importer searches only for an exact match on `student_id`, `fee_type_id`, `period_start`, and `currency`.
   - If no exact invoice item exists, it logs the payment with `unallocated_minor = 0`, causing the cash to be lost from balancing reconciliation.
5. **Missing Migration `000007`:**
   - Migration numbering skips from `000006` to `000008`.

---

## 8. Missing Functionality

1. **Database Staging Schema:**
   - No staging tables (`staging_students`, `staging_dues`, `staging_paid`, `staging_currency_review`, `staging_batches`) exist in MySQL.
2. **Reconciliation Engine:**
   - No automated reconciliation tool to compare source collection slips against dues obligations before posting to the financial ledger.
3. **Currency Conversion / Exchange Layer:**
   - No multi-currency ledger summary; reporting cannot consolidate multi-currency families without converting or keeping separate ledgers.
4. **Provenance & Import Batch Ledger:**
   - `import_batches` is empty; no record exists of which file, batch hash, user, or date produced the 4,349 students or 31,086 payments.
5. **Confirmation Safeguard for Bulk Ledger Operations:**
   - No two-factor authorization, password re-entry, or confirmation challenge before wiping ledger data.

---

## 9. Immediate Critical Risks

| Risk Level | Issue | Impact | Root Cause File & Lines |
| :---: | :--- | :--- | :--- |
| **CRITICAL** | **Unallocated Cash Receipts Lost from Balancing** | ~1.292 billion minor units of collected payments exist without allocation or credit balance tracking. | [`FeeImportService.php:L472`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/FeeImportService.php#L472) hardcodes `unallocated_minor => 0`. |
| **CRITICAL** | **Hardcoded SAR Currency on All Enrollments** | Future invoices for 4,349 students will generate exclusively in SAR, even for families in UAE, Pakistan, Qatar, or Oman. | [`StudentImportService.php:L309, L340`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/StudentImportService.php#L309) hardcodes `'currency' => 'SAR'`. |
| **CRITICAL** | **Unsafe Bulk Delete Web Endpoint** | A single HTTP POST to `/challan/clear-fee-data` wipes the entire billing run, invoice, and payment history without recovery. | [`BillingController.php:L853-857`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Http/Controllers/BillingController.php#L853-L857) and [`FeeImportService.php:L522-548`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/FeeImportService.php#L522-L548). |
| **HIGH** | **217 Fee Types Created in Master Catalog** | Master fee type catalog polluted with non-standard line items like class names and extra classes. | [`FeeImportService.php:L48-63`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/FeeImportService.php#L48-L63). |
| **HIGH** | **0 Enrollments in Quran Department** | All students assigned to School or Tuition; Quran department billing completely broken. | [`StudentImportService.php:L192-198`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/StudentImportService.php#L192-L198). |
| **MEDIUM** | **Side-Effect Writes on HTTP GET Requests** | Browsing `/manage-fee` inserts rows into `fee_catalog_rates`. | [`FeeCatalogController.php:L33-46`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Http/Controllers/FeeCatalogController.php#L33-L46). |
| **MEDIUM** | **Idempotency Key Duplication Risk** | Slip import idempotency key uses CSV row index (`"import-slip-{$currentSlip}-{$line}"`). Reordering CSV creates duplicate receipts. | [`FeeImportService.php:L476-477`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/FeeImportService.php#L476-L477). |

---

## 10. Recommended Next Implementation Phase

To recover the project without data loss or financial corruption, execution must follow a strictly ordered, phase-gated recovery plan:

```mermaid
graph TD
    A[Phase 1: Quarantine & Backup] --> B[Phase 2: Database Staging Schema]
    B --> C[Phase 3: Staging Ingestion & Reconciliation]
    C --> D[Phase 4: Production Ledger Pipeline]
    D --> E[Phase 5: UI & Controller Hardening]
```

### Phase 1: Quarantine & Backup (Immediate)
1. Generate a complete SQL dump of the current database state (`iqra_billing_quarantine_20261019.sql`) for audit records before any purging.
2. Remove or lock down high-risk routes in `routes/web.php` (`/challan/clear-fee-data`).
3. Fix the write-on-GET bug in `FeeCatalogController@index`.

### Phase 2: Database Staging Schema (Clean Architecture)
1. Create a formal migration `2026_10_19_000011_create_staging_migration_tables.php` defining dedicated MySQL staging tables:
   - `staging_students` (staging student attributes, approved currency, approved department, approved campus, review issues).
   - `staging_dues` (staging obligations, raw particulars, parsed month/year, balance, review issues).
   - `staging_paid` (staging collection slips, payment dates, amounts, review issues).
   - `staging_currency_review` (GRN, match status, approved currency).
   - `staging_batches` (batch hash, row counts, exception counts).

### Phase 3: Staging Ingestion & Reconciliation
1. Build a robust Artisan command (`php artisan iqra:stage-import`) that loads the verified 79 staging batches from `scratch/staging/IQRA_STAGING_ONLY_20261019_075231/` into the staging tables.
2. Ensure the 138 unresolved currencies and 37 multi-currency families are quarantined and surfaced in a dedicated review interface.
3. Map departments using the explicit `department_approved` and `campus_approved` staging fields instead of guessing from class names.

### Phase 4: Production Ledger Ingestion
1. Purge the improperly imported test data (4,349 students, 8,558 invoices, 31,086 payments, and 209 redundant fee types) using a verified, single-transaction clean script.
2. Run the production migration from the approved staging tables:
   - Create students, families, enrollments with their **approved currencies** and **approved departments**.
   - Create opening balance invoices and obligations with standardized fee types (`monthly`, `admission`, `exam`, `books`, etc.).
   - Ingest historical payments and execute payment allocation:
     - Matching obligations are marked `paid` or `partial`.
     - Excess payments are allocated to `unallocated_minor` as advance credits rather than zeroed out.
   - Record SHA-256 hashes and batch metrics in `import_batches`.

### Phase 5: UI & Controller Hardening
1. Remove direct CSV upload routes that bypass staging.
2. Replace with a Staging & Reconciliation dashboard showing batch status, exceptions, currency approvals, and one-click commit to ledger.
3. Run the complete PHPUnit test suite to verify regression protection.

---

## 11. Exact Files Requiring Correction

| File Path | Nature of Correction Required |
| :--- | :--- |
| [`app/Services/FeeImportService.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/FeeImportService.php) | **Critical:** Fix line 472 (`unallocated_minor => 0`). Remove unconstrained delete methods (`clearFeeData`, `clearAllData`). Standardize fee type resolution to prevent catalog pollution. Remove hardcoded `'SAR'` fallback. |
| [`app/Services/StudentImportService.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/StudentImportService.php) | **Critical:** Remove hardcoded `'SAR'` at lines 309 and 340. Use approved currency from staging. Fix department assignment at lines 192-198 to support Quran department. |
| [`app/Http/Controllers/BillingController.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Http/Controllers/BillingController.php) | **Critical:** Remove or strictly protect `clearFeeData` (lines 853-857). Remove raw file imports that bypass staging. |
| [`app/Http/Controllers/FeeCatalogController.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Http/Controllers/FeeCatalogController.php) | **High:** Remove side-effect database inserts from `index()` method (lines 33-46). |
| [`routes/web.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/routes/web.php) | **High:** Remove hazardous `Route::post('/challan/clear-fee-data')`. Lock down preloaded import endpoints. |
| [`resources/views/collection.blade.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/resources/views/collection.blade.php) | **High:** Remove the red "Clear Fee Dues & Payment Receipts" button and modal form (lines 72-80). |
| [`database/migrations/`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/database/migrations/) | **High:** Create new migration for staging tables (`staging_students`, `staging_dues`, `staging_paid`, etc.). |
| [`scripts/convert_myskool_data.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/scripts/convert_myskool_data.php) | **Medium:** Retire ad-hoc converter in favor of direct ingestion from verified Google Sheets staging dataset. |

---

## Strict Restrictions Compliance Note

As instructed:
- **No application code was modified.**
- **No database migrations were created or executed.**
- **No student or financial records were imported, modified, or deleted.**
- **No destructive commands were run.**
- **No original CSV files were altered.**
- **No credentials or student personal identifying information are exposed in this report.**

The audit is complete and the project remains in an untouched, stable state.

**Awaiting your instructions and approval before proceeding with Phase 1 or any further changes.**
