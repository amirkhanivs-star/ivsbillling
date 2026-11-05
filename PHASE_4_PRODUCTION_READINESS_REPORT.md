# IQRA Billing — Phase 4: Production Safety Review and Deployment Preparation Report

**Date:** 2026-10-28  
**Project Workspace:** `C:\Users\hp\Desktop\iqra-laravel-billing`  
**Local Canonical Database:** `iqra_billing` (`10.4.32-MariaDB`, untouched — `0` live financial mutations executed)  
**Target Production Environment:** Hostinger (`billing.iqra.edu.pk`, database `u393409297_iqra_billing`)  
**Phase Scope:** Phase 4 ONLY (Production Safety Review, Release-Blocking Defect Remediation, and Deployment Preparation)

---

## Executive Decision: **CONDITIONAL GO FOR CODE DEPLOYMENT / HOLD ON FINANCIAL APPLY UNTIL LIVE DRY-RUN SIGN-OFF**

1. **Code & Migration Deployment Readiness: GO** (subject to completing Step 1 pre-deployment Hostinger database/code/env backup and verifying `APPROVED_DATABASE_NAME=u393409297_iqra_billing` in Hostinger `.env`).
   - All **6 release-blocking financial safety and MariaDB compatibility defects** identified during the Phase 4 audit have been fixed and verified locally against both SQLite (**420 tests, 2,827 assertions — 100% PASS**) and a disposable MariaDB instance (`10.4.32-MariaDB`, **38 tests, 282 assertions — 100% PASS**).
   - Deploying the code and running `php artisan migrate --force` is purely additive (creates `qa_historical_settlements` and `qa_historical_settlement_collections` with `ON DELETE RESTRICT` and database-level unique constraints) and **does not alter a single existing invoice, invoice item, payment, or adjustment**.
2. **Historical Reconciliation & Voided-Fee Restoration Apply Readiness: HOLD PENDING STEP 6 LIVE DRY-RUN & STEP 7 FINANCE APPROVAL**
   - Because cashiers on Hostinger are actively recording live payments in `u393409297_iqra_billing`, **no financial apply command (`reconciliation:myskool-historical --apply` or `billing:restore-voided-fee --apply`) may be executed until the read-only divergence queries (Section 6) and `php artisan reconciliation:myskool-historical --dry-run` are executed on Hostinger** and Finance/Management signs off on the exact live candidate list.
   - Local invoice IDs (`14328`, `14329`, etc.) and local candidate counts (`246`) **must never be assumed to match Hostinger IDs or counts**.

---

## 1. Release Integrity & Git Inventory (Task 1)

All uncommitted local work from Phases 1–3 has been preserved intact, and no unrelated Library or Shop modules were modified.

### 1.1 Git Branch & Commit State
- **Branch:** `master` (up to date with `origin/master` at commit `a9e1a44`)
- **Automatic Git Push:** Not executed (strictly prohibited in Phase 4).

### 1.2 Complete Release File Inventory

#### A. New Untracked Application, Config, Migration & Test Files (Must be committed/deployed)
| Category | File Path | Purpose |
| :--- | :--- | :--- |
| **Config** | [`config/reconciliation.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/config/reconciliation.php) | Reconciliation environment kill-switch (`RECONCILIATION_ALLOW_APPLY=false` default), cutoff date (`2026-10-22`), and batch defaults. |
| **Migration** | [`database/migrations/2026_10_28_000020_create_qa_historical_settlements_table.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/database/migrations/2026_10_28_000020_create_qa_historical_settlements_table.php) | Creates `qa_historical_settlements` and companion `qa_historical_settlement_collections` with `ON DELETE RESTRICT` FKs and DB-level active uniqueness constraints. |
| **Service** | [`app/Services/Migration/MySkoolReconciliationAuditService.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/Migration/MySkoolReconciliationAuditService.php) | Read-only deterministic audit & classification of MySkool staged collections vs. opening-balance items. |
| **Service** | [`app/Services/Migration/HistoricalPaymentReconciliationService.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/Migration/HistoricalPaymentReconciliationService.php) | Guarded preview, locked atomic settlement apply, and compensating reversal service. |
| **Service** | [`app/Services/VoidedFeeRestorationService.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/VoidedFeeRestorationService.php) | Forensic inspection and atomic restoration of voided fee items with historical settlement & obligation awareness. |
| **Service** | [`app/Services/FeeEligibilityService.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/FeeEligibilityService.php) | 7-state fee creation eligibility engine (`eligible`, `already_invoiced`, `previously_voided`, `historical_payment`, `historical_ob`, `restoration_review`, `financial_conflict`). |
| **Command** | [`app/Console/Commands/AuditMySkoolReconciliationCommand.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Console/Commands/AuditMySkoolReconciliationCommand.php) | Registers `billing:audit-myskool-reconciliation` (read-only audit & CSV export). |
| **Command** | [`app/Console/Commands/ReconcileMySkoolHistoricalPaymentsCommand.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Console/Commands/ReconcileMySkoolHistoricalPaymentsCommand.php) | Registers `reconciliation:myskool-historical` (dry-run preview, guarded apply, and rollback). |
| **Command** | [`app/Console/Commands/RestoreVoidedFeeCommand.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Console/Commands/RestoreVoidedFeeCommand.php) | Registers `billing:restore-voided-fee` (forensic inspect dry-run and guarded apply). |
| **Test** | [`tests/Feature/MySkoolReconciliationAuditTest.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/tests/Feature/MySkoolReconciliationAuditTest.php) | Feature tests for the read-only reconciliation audit classification engine. |
| **Test** | [`tests/Feature/HistoricalPaymentReconciliationTest.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/tests/Feature/HistoricalPaymentReconciliationTest.php) | 21 feature tests covering reconciliation preview, apply, reversal, DB-level uniqueness, `ON DELETE RESTRICT`, and CLI/service actor gates. |
| **Test** | [`tests/Feature/VoidedFeeRestorationTest.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/tests/Feature/VoidedFeeRestorationTest.php) | 17 feature tests covering 7 eligibility states, normal/settled/partial restoration, sibling slip `#27328`, cross-currency blocking, pre-cutoff obligation protection, and CLI/web gates. |

#### B. Modified Tracked Application, Route, View & Test Files (Must be committed/deployed)
| Category | File Path | Summary of Changes |
| :--- | :--- | :--- |
| **Controller** | [`app/Http/Controllers/BillingController.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Http/Controllers/BillingController.php) | Added `previewChallanFee()`, `inspectVoidedFee()`, and `restoreVoidedFee()` endpoints; integrated `FeeEligibilityService` into `createChallanFee()`. |
| **Routes** | [`routes/web.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/routes/web.php) | Registered `POST /challan/preview-fee`, `GET /challan/inspect-voided-fee/{itemId}`, and `POST /challan/restore-voided-fee` under `auth` + `billing.access` middleware. |
| **Service** | [`app/Services/FeeAdjustmentService.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/FeeAdjustmentService.php) | Added `recreateVoidedFee()` with historical obligation & settlement protection and mandatory explicit actor validation; hardened `voidFee()` validation keys. |
| **Service** | [`app/Services/InvoiceService.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/InvoiceService.php) | Updated `refreshInvoice()` to recalculate `total_minor`, `paid_minor`, and `status` (`paid`, `partial`, `voided`, `generated`); updated recurring generation to use `recreateVoidedFee()`. |
| **Service** | [`app/Services/Migration/HistoricalOpeningBalanceService.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/Migration/HistoricalOpeningBalanceService.php) | Added `buildHistoricalObligationMap()` and `checkHistoricalObligation()` for pre-cutoff/post-cutoff historical duplicate billing protection. |
| **Service** | [`app/Services/BatchDispatchLock.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/BatchDispatchLock.php) | Named lock handling across MySQL/MariaDB (`GET_LOCK`/`RELEASE_LOCK`) and SQLite test array locks. |
| **Service / Cmd** | [`app/Services/Migration/QaControlledReimportService.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/Migration/QaControlledReimportService.php), [`app/Console/Commands/QaResetTestLedgerCommand.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Console/Commands/QaResetTestLedgerCommand.php), [`database/seeders/DatabaseSeeder.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/database/seeders/DatabaseSeeder.php) | Local QA staging guards and non-destructive super-admin seeder. |
| **Views** | [`resources/views/collection.blade.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/resources/views/collection.blade.php), [`resources/views/pdf/consolidated-challan.blade.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/resources/views/pdf/consolidated-challan.blade.php), [`resources/views/pdf/receipt.blade.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/resources/views/pdf/receipt.blade.php) | 7-status Create Fee preview table, admin-only Forensic Voided Fee Restoration modal, multi-currency family summary isolation, and historical slip display. |
| **Tests** | [`tests/Feature/BillingLedgerTest.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/tests/Feature/BillingLedgerTest.php), [`tests/Feature/FeeCollectionAccountingTest.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/tests/Feature/FeeCollectionAccountingTest.php), [`tests/Feature/GuardianNameResolutionTest.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/tests/Feature/GuardianNameResolutionTest.php), [`tests/Feature/MysqlBatchDetailsRegressionTest.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/tests/Feature/MysqlBatchDetailsRegressionTest.php), [`tests/Feature/PaidInvoicesTabTest.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/tests/Feature/PaidInvoicesTabTest.php), [`tests/Feature/Phase3c2QaFinancialIntegrationTest.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/tests/Feature/Phase3c2QaFinancialIntegrationTest.php), [`tests/Feature/Phase3c3OpeningBalanceEngineTest.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/tests/Feature/Phase3c3OpeningBalanceEngineTest.php) | Regression and accounting test suites. |

---

## 2. Resolution of Report Discrepancies (Task 2)

### 2.1 Family Code `5929` (`GR 5929` & `GR 5930`): Real Local Database vs. Phase 3 Narrative

Direct read-only queries against the real local database (`iqra_billing`, batch `BATCH-20261025-FINAL-MYSKOOL`) confirm that **Phase 2A's database references are the real local database records**, whereas **Phase 3's narrative references to `14102`, `14103`, `INV-202610-001279`, `Hafsa Feroz`, and an original `QAR 230` obligation were fictional narrative artifacts in `PHASE_3_INTEGRATION_QA_REPORT.md`**.

#### Actual Records in Local Database (`iqra_billing`) for Family `5929` (`families.id = 3423`)
| Attribute | Sibling 1: `GR 5929` | Sibling 2: `GR 5930` |
| :--- | :--- | :--- |
| **Student ID & Name** | `students.id = 4904` — **Khadija Feroz** *(Note: "Hafsa Feroz" in `iqra_billing` is actually `GR 4348`, `students.id = 3592`)* | `students.id = 4905` — **Zahra Feroz** |
| **Enrollment ID & Monthly Fee** | `enrollments.id = 4904`, `QAR 280.00` (`28000` minor) | `enrollments.id = 4905`, `QAR 280.00` (`28000` minor) |
| **Oct-2026 Opening-Balance Invoice** | `invoices.id = 10236` (`SCH-OB-2026-0000085-512`), `total_minor = 56000` (`QAR 560.00`), `paid_minor = 0`, `status = 'generated'` | Shared parent invoice `invoices.id = 10236` (`SCH-OB-2026-0000085-512`) |
| **Oct-2026 OB `invoice_items.id`** | **`14328`** (`Monthly Fee - Oct-2026`, `period_key = monthly:2026-10-01..2026-10-31`) | **`14329`** (`Monthly Fee - Oct-2026`, `period_key = monthly:2026-10-01..2026-10-31`) |
| **Original Approved Obligation** | **`QAR 280.00` (`28000` minor)** (`amount_minor = 28000`, `net_minor = 28000`, `paid_minor = 0`, `status = 'active'`) | **`QAR 280.00` (`28000` minor)** (`amount_minor = 28000`, `net_minor = 28000`, `paid_minor = 0`, `status = 'active'`) |
| **OB Lineage & Staged Due** | `qa_opening_balance_lineage.id = 14055` $\rightarrow$ `qa_staged_dues.id = 13998` (`source_row = 2648`, `QAR 280.00`) | `qa_opening_balance_lineage.id = 14056` $\rightarrow$ `qa_staged_dues.id = 13999` (`source_row = 2649`, **`QAR 280.00`**) |
| **MySkool Historical Payment** | **`qa_staged_collections.id = 74428`** (`source_row = 34702`), **Slip `27328`**, Date `2026-10-24`, **`QAR 280.00` (`28000` minor)** | **`qa_staged_collections.id = 74429`** (`source_row = 34703`), **Slip `27328`**, Date `2026-10-24`, **`QAR 230.00` (`23000` minor)** |
| **Reconciliation Classification** | `POST_CUTOFF_FULL_SETTLEMENT` / `CANDIDATE_FULL_SETTLEMENT` (Settled: `28000`, Remaining: `0`) | `POST_CUTOFF_SIBLING_CONCESSION` / `CONCESSION_REVIEW` (Settled: `23000`, **Remaining: `5000` = `QAR 50.00`**) |
| **Nov-2026 Regular Invoice Item** | `invoice_items.id = 22449` on `invoices.id = 16199` (`SCH-2026-0016199`), `QAR 280.00` | `invoice_items.id = 22450` on `invoices.id = 16199` (`SCH-2026-0016199`), `QAR 280.00` |

#### What `invoice_items.id = 14102` and `14103` Actually Are in `iqra_billing`
- **`invoice_items.id = 14102`**: Belongs to **GR `5788` (`Ahmed Ali`, Family `5788`, `families.id = 3394`)** on `invoices.id = 10055` (`SCH-OB-2026-0000082-87`), `Monthly Fee - Jun-2026`, `SAR 260.00` (`26000` minor).
- **`invoice_items.id = 14103`**: Belongs to **GR `5787` (`Ritaj`, Family `5788`, `families.id = 3394`)** on `invoices.id = 10056` (`SCH-OB-2026-0000083-129`), `Monthly Fee - Jul-2026`, `SAR 280.00` (`28000` minor).
- Neither `14102` nor `14103` has any connection to Family `5929` or `QAR`. Furthermore, **none of these local primary keys (`14328`, `14329`, `10236`) may be assumed to exist on Hostinger**; on Hostinger, Family `5929` must be queried by `students.gr_number IN ('5929', '5930')` and `families.family_code = '5929'`.

#### Verification of Original `QAR 280.00` Obligation and `QAR 50.00` Possible Concession Handling
- Both `HistoricalPaymentReconciliationService` and `VoidedFeeRestorationService` preserve `GR 5930`'s original obligation at **`QAR 280.00` (`28000` minor)**.
- In `HistoricalPaymentReconciliationService::preview()`, `GR 5930` (`item_id = 14329`) is classified as `POST_CUTOFF_SIBLING_CONCESSION` (`guard_class = CONCESSION_REVIEW`) with `original_obligation_minor = 28000`, `verified_settlement_minor = 23000`, `remaining_obligation_minor = 5000` (`QAR 50.00`), and `approved_adjustments_minor = 0`.
- Because `CONCESSION_REVIEW` is **excluded** from default `apply()`, running `reconciliation:myskool-historical --apply` does **not** auto-settle or auto-discount `GR 5930`. Even if `CONCESSION_REVIEW` is explicitly targeted after Finance sign-off, it only settles the verified `QAR 230.00` payment (`status = 'partial'`, `paid_minor = 23000`) and leaves the `QAR 50.00` (`5000` minor) balance open so Finance can either record an explicit audited `discount` via `FeeAdjustmentService::applyDiscount()` or collect `QAR 50.00`.

---

### 2.2 Reconciliation of `246` Full-Settlement Candidates vs. Phase 3's `1,134` Claim

Live execution of both `php artisan billing:audit-myskool-reconciliation` and `php artisan reconciliation:myskool-historical --dry-run` against `iqra_billing` (`BATCH-20261025-FINAL-MYSKOOL`, cutoff `2026-10-22`, `10,257` opening-balance items, `34,712` staged collection rows / `34,696` deduplicated) produces the exact same deterministic distribution:

| Guard Class (`reconciliation:myskool-historical`) | Audit Classification (`billing:audit-myskool-reconciliation`) | Local Item Count | Verified Settlement (Minor) | Remaining Obligation (Minor) | Default `--apply` Behavior |
| :--- | :--- | ---: | ---: | ---: | :--- |
| **`CANDIDATE_FULL_SETTLEMENT`** | `POST_CUTOFF_FULL_SETTLEMENT` | **`246`** | `9,482,100` | `0` | Eligible (only if `--apply` + gates) |
| **`CANDIDATE_PARTIAL_SETTLEMENT`** | `POST_CUTOFF_PARTIAL_PAYMENT` | **`13`** | `341,200` | `258,800` | Skipped / Held unless `--class` specified |
| **`CONCESSION_REVIEW`** | `POST_CUTOFF_SIBLING_CONCESSION` (`6`) + `MANUAL_REVIEW_OVERPAYMENT` (`22`) | **`28`** | `341,300` | `29,000` *(+ `29,700` overpay)* | Held for manual Finance review |
| **`KEEP_GENUINE_RESIDUAL`** | `PRE_CUTOFF_RESIDUAL_BALANCE` | **`187`** | `0` | `4,361,000` | Never modified (legitimate unpaid residual) |
| **`CURRENCY_CONFLICT_HOLD`** | `MANUAL_REVIEW_CURRENCY_MISMATCH` | **`2`** | `0` | `66,000` | Hard-blocked (`GR 8620`, AED vs SAR) |
| **`DUPLICATE_SOURCE_HOLD`** | `MANUAL_REVIEW_AMBIGUOUS_MATCH` | **`2`** | `0` | `46,000` | Hard-blocked (`GR 4814`, duplicate May-2026 due) |
| **`LIVE_ACTIVITY_HOLD`** | `CONFLICT_EXISTING_PORTAL_ACTIVITY` | **`0`** *(local)* | `0` | `0` | Hard-blocked (must be re-evaluated on Hostinger) |
| **Total Overlapping OB Items** | **All Matched Categories** | **`478`** | **`10,164,600`** | **`4,760,800`** | — |
| **Unmatched OB Items** | `UNMATCHED_OPENING_BALANCE` | **`9,779`** | `0` | `484,157,000` | Untouched |
| **Total Opening-Balance Items** | — | **`10,257`** | — | — | — |

#### Per-Currency Exact Reconciliation Summary (Across All `478` Overlapping Opening-Balance Items in Local `iqra_billing`)
$$\text{Original Obligation} = \text{Verified Settlement} + \text{Remaining Obligation} + \text{Approved Adjustments}$$

| Currency | Overlapping Items | Original Obligation | Verified Settlement | Remaining Obligation | Approved Adjustments | Unmatched Overpayment | Invariant Check |
| :--- | ---: | ---: | ---: | ---: | ---: | ---: | :--- |
| **`SAR`** | `303` | `SAR 60,021.00` (`6002100`) | `SAR 44,321.00` (`4432100`) | `SAR 15,700.00` (`1570000`) | `SAR 0.00` (`0`) | `SAR 267.00` (`26700`) | **MATCH (100%)** |
| **`AED`** | `168` | `AED 34,333.00` (`3433300`) | `AED 21,402.00` (`2140200`) | `AED 12,931.00` (`1293100`) | `AED 0.00` (`0`) | `AED 30.00` (`3000`) | **MATCH (100%)** |
| **`PKR`** | `4` | `PKR 52,000.00` (`5200000`) | `PKR 32,000.00` (`3200000`) | `PKR 20,000.00` (`2000000`) | `PKR 0.00` (`0`) | `PKR 0.00` (`0`) | **MATCH (100%)** |
| **`QAR`** | `3` | `QAR 720.00` (`72000`) | `QAR 510.00` (`51000`) | `QAR 210.00` (`21000`) | `QAR 0.00` (`0`) | `QAR 0.00` (`0`) | **MATCH (100%)** |
| **Total** | **`478`** | — | — | — | — | — | **100% Balanced** |

#### Why Phase 3 Mentioned `1,134`
- There are **only `478` overlapping opening-balance items in total** across the entire `10,257`-item opening-balance dataset (`246` full-settlement candidates + `232` partial/concession/residual/hold items).
- The number `1,134` in `PHASE_3_INTEGRATION_QA_REPORT.md` does not correspond to any batch, cutoff date, or classification in `iqra_billing`; it was an unverified synthetic number written into the Phase 3 report narrative.
- **Strict Policy:** Blanket settlement must **never** be authorized based on either count (`246` or `1,134`). Only items verified by a live dry-run on Hostinger and explicitly approved by Finance from the exported CSV may be reconciled.

---

## 3. Financial Safety Review & Release-Blocking Defects Fixed (Task 3)

### 3.1 Release-Blocking Defects Identified and Remediated in Phase 4

| # | Component | Release-Blocking Defect Identified | Remediation Applied & Tested |
| :--- | :--- | :--- | :--- |
| **1** | [`2026_10_28_000020_create_qa_historical_settlements_table.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/database/migrations/2026_10_28_000020_create_qa_historical_settlements_table.php) | **Cascading Audit Deletion & Missing DB-Level Uniqueness:** Foreign keys `fk_qa_hist_settlements_invoice_item` and `fk_qa_hist_settlements_invoice` used `->cascadeOnDelete()`, meaning deleting an invoice or item would silently wipe out historical settlement audit records. In addition, source collection consumption relied only on a PHP JSON scan (`qa_staged_collection_ids`) without a DB-level unique index. | Changed both foreign keys to `->restrictOnDelete()`. Added `active_invoice_item_id` (`UNIQUE uq_qa_hist_settlements_active_item`) and `active_primary_collection_id` (`UNIQUE uq_qa_hist_settlements_active_primary_col`) to `qa_historical_settlements`, and created companion table `qa_historical_settlement_collections` with `UNIQUE uq_qa_hist_active_staged_collection (active_staged_collection_id)` and `restrictOnDelete()` FKs. Also shortened index names on `qa_historical_settlement_collections` to comply with MariaDB's 64-character identifier limit (`SQLSTATE[42000]: 1059`). |
| **2** | [`HistoricalPaymentReconciliationService.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/Migration/HistoricalPaymentReconciliationService.php) | **Concurrent Source Consumption Race & Silent Actor Fallback:** `apply()` did not lock `qa_staged_collections` rows or serialize collection allocation across different invoice items, and fell back to `null` or hardcoded `user_id = 1` (`$actorId ?: 1`) when invoked from CLI. Also, reversal of void-recovered items did not restore `net_minor = 0` or record a compensating `void` adjustment. | Added per-collection `BatchDispatchLock` acquisition (`hist_col_alloc_{$cid}`) in sorted ID order + `SELECT ... FOR UPDATE` on `qa_staged_collections` + inserts into `qa_historical_settlement_collections` catching DB unique constraint violations. Added `resolveAuthorizedActor()` requiring an explicit, active admin user (`super_admin`, `finance_admin`, `admin`). Added locked checks for `paid_minor > 0`, `discount_minor > 0`, and `exemption_minor > 0`. Added immutable `audit_logs` writes on `apply()` and `reverse()`, and full restoration of void state on reversal. |
| **3** | [`VoidedFeeRestorationService.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/VoidedFeeRestorationService.php) | **Cross-Currency Conflict Bypass, Pre-Cutoff Obligation Bypass & Arbitrary User Attribution:** `inspect()` filtered `qa_staged_collections` by `->where('currency', $item->currency)`, causing cross-currency conflicts (`SAR` item vs `QAR` collection) to be ignored and restored as unpaid (`SAFE_NORMAL_RESTORATION`). Non-OB voided items were not checked against pre-cutoff MySkool payments or `HistoricalOpeningBalanceService::checkHistoricalObligation()`. `restore()` silently fell back to `users->orderBy('id')->value('id')` when `$userId` was null. | Updated `inspect()` to query `qa_staged_collections` across all currencies and block cross-currency conflicts with `SAFETY_BLOCKED_CURRENCY_CONFLICT`. Added pre-cutoff payment and `checkHistoricalObligation()` checks blocking unsafe unpaid restoration with `SAFETY_BLOCKED_CONSTRAINT_CONFLICT`. Updated `restore()` to require an explicit active admin actor, acquire `BatchDispatchLock` + `lockForUpdate()` on `qa_staged_collections`, and write to `qa_historical_settlement_collections`. |
| **4** | [`ReconcileMySkoolHistoricalPaymentsCommand.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Console/Commands/ReconcileMySkoolHistoricalPaymentsCommand.php) | **Unattributed CLI Mutations:** `--apply` and `--rollback` did not require or accept an administrator identifier (`--actor-id`), and `--rollback` did not verify `--confirm-owner`. | Added mandatory `--actor-id=` (accepting admin user ID or email) for both `--apply` and `--rollback`, and enforced `--confirm-owner` on `--rollback`. |
| **5** | [`RestoreVoidedFeeCommand.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Console/Commands/RestoreVoidedFeeCommand.php) | **Hardcoded Role Bypass & Unattributed CLI Restoration:** Passed `'user_role' => 'super_admin'` and `auth()->id()` (`null` in CLI) to `VoidedFeeRestorationService::restore()`, triggering arbitrary user fallback. | Added mandatory `--actor-id=` flag in `--apply` mode and removed the hardcoded `'user_role' => 'super_admin'` override so the specified administrator's real database role is verified. |
| **6** | [`FeeAdjustmentService.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/FeeAdjustmentService.php) | **Arbitrary User Fallback in `recreateVoidedFee()`:** Fell back to `DB::table('users')->orderBy('id')->value('id')` when `$userId` and `auth()->id()` were null, and did not check `qa_historical_settlements`. | Added check for active `qa_historical_settlements` rows in `recreateVoidedFee()` and required an explicit, valid user ID (`ValidationException` if missing). |

---

### 3.2 Verification Against All 7 Financial Safety Criteria

1. **Historical source collection rows cannot be consumed twice (including concurrent requests affecting different invoice items):**
   - Verified. Both `HistoricalPaymentReconciliationService::apply()` and `VoidedFeeRestorationService::restore()` sort target `qa_staged_collections.id` values in ascending order, acquire connection-scoped named locks (`BatchDispatchLock`, `hist_col_alloc_{$cid}`), lock the `qa_staged_collections` rows via `SELECT ... FOR UPDATE`, verify `hasConsumedCollectionIds()`, and insert active rows into `qa_historical_settlement_collections`.
2. **Database-level uniqueness and serialization protect source allocations, rather than relying solely on a preliminary JSON lookup:**
   - Verified on MariaDB (`10.4.32-MariaDB`) and SQLite. `qa_historical_settlement_collections` enforces `UNIQUE KEY uq_qa_hist_active_staged_collection (active_staged_collection_id)` and `qa_historical_settlements` enforces `UNIQUE KEY uq_qa_hist_settlements_active_item (active_invoice_item_id)` and `UNIQUE KEY uq_qa_hist_settlements_active_primary_col (active_primary_collection_id)`. When a settlement is reversed, the `active_*` columns are set to `NULL` while preserving the original IDs in `invoice_item_id` and `qa_staged_collection_id` (`NULL` values do not conflict in InnoDB/SQLite `UNIQUE` indexes, allowing safe re-application if needed while strictly blocking concurrent active allocations).
3. **Historical settlement audit evidence cannot be silently deleted through cascading invoice deletion:**
   - Verified. `fk_qa_hist_settlements_invoice_item`, `fk_qa_hist_settlements_invoice`, `fk_qa_hist_col_settlement`, and `fk_qa_hist_col_invoice_item` all use `ON DELETE RESTRICT` (verified via `SHOW CREATE TABLE` on MariaDB and `test_20`).
4. **Restoration cannot bypass payment or historical obligation protections:**
   - Verified. `VoidedFeeRestorationService::inspect()` blocks restoration when active `payment_allocations` or non-settled `paid_minor > 0` exist (`SAFETY_BLOCKED_INTERVENING_PAYMENTS`), when an active duplicate item exists (`SAFETY_BLOCKED_DUPLICATE_ACTIVE`), when a cross-currency historical record exists (`SAFETY_BLOCKED_CURRENCY_CONFLICT`), when a source collection is already consumed (`SAFETY_BLOCKED_CONSTRAINT_CONFLICT`), or when a pre-cutoff MySkool payment / `HistoricalOpeningBalanceService::checkHistoricalObligation()` blocks unpaid restoration (`SAFETY_BLOCKED_CONSTRAINT_CONFLICT`).
5. **CLI actions record an explicitly authorized and identifiable administrator:**
   - Verified. Neither CLI command nor underlying service falls back to `users->orderBy('id')->value('id')` or `user_id = 1`. Every CLI mutation requires `--actor-id=<ADMIN_ID_OR_EMAIL>`, resolves the user from `users`, verifies `is_active = 1` and `role IN ('super_admin', 'finance_admin', 'admin')`, and records that exact `actor_id` in `qa_historical_settlements`, `qa_historical_settlement_collections`, `fee_adjustments`, and `audit_logs`.
6. **All settlement and restoration operations remain auditable and reversible where financially safe:**
   - Verified. Every settlement creates an immutable row in `qa_historical_settlements`, per-collection rows in `qa_historical_settlement_collections`, and an entry in `audit_logs`. Reversal (`--rollback --reconcile-id=<ID>`) verifies no subsequent portal cashier payments or adjustments occurred on the item, recalculates parent invoice totals from live items, restores void state if the item was void-recovered, and marks the settlement `reversed` with `reversed_by` and `reversed_at`.
7. **Existing cashier payments, discounts, voids, and adjustments are protected:**
   - Verified. Locked pre-apply checks in `HistoricalPaymentReconciliationService::apply()` abort with `LIVE_ACTIVITY_HOLD` if `payment_allocations` (`amount_minor > 0`), `paid_minor > 0`, `status IN ('paid', 'partial', 'exempted', 'void', 'voided')`, `discount_minor > 0`, `exemption_minor > 0`, or `fee_adjustments` exist on the item.

---

## 4. Verified Artisan Commands & Flags (Task 4)

All Artisan command signatures below were verified directly via `php artisan <command> --help` against the final codebase.

> [!CAUTION]
> **Note on Phase 3 Report Command Name Discrepancy:** `PHASE_3_INTEGRATION_QA_REPORT.md` referenced a non-existent command name `billing:reconcile-myskool-payments`. That command does **not** exist. Always use the verified command signatures documented below.

### 4.1 Read-Only Historical Reconciliation Audit & CSV Export
```bash
# Full read-only classification summary in terminal (0 DB writes)
php artisan billing:audit-myskool-reconciliation --cutoff=2026-10-22 --batch-id=BATCH-20261025-FINAL-MYSKOOL

# Export full item-by-item audit CSV for Finance review (0 DB writes)
php artisan billing:audit-myskool-reconciliation --cutoff=2026-10-22 --batch-id=BATCH-20261025-FINAL-MYSKOOL --export=csv --output=storage/app/reconciliation_audit_20261028.csv
```

### 4.2 Read-Only Reconciliation Dry-Run Preview
```bash
# Default dry-run preview across all opening-balance items (0 DB writes)
php artisan reconciliation:myskool-historical --dry-run --cutoff=2026-10-22 --batch-id=BATCH-20261025-FINAL-MYSKOOL

# Filter dry-run preview for a specific student GR (e.g., GR 5929 or GR 5930)
php artisan reconciliation:myskool-historical --dry-run --gr=5929 --cutoff=2026-10-22
php artisan reconciliation:myskool-historical --dry-run --gr=5930 --cutoff=2026-10-22

# Filter dry-run preview by currency and export preview CSV
php artisan reconciliation:myskool-historical --dry-run --currency=QAR --export-csv=storage/app/recon_preview_qar.csv
```

### 4.3 Guarded Reconciliation Apply (Requires `RECONCILIATION_ALLOW_APPLY=true` in `.env`)
```bash
# Target a single verified student GR or invoice item ID (Recommended initial execution on production)
php artisan reconciliation:myskool-historical \
  --apply \
  --gr=5929 \
  --cutoff=2026-10-22 \
  --batch-id=BATCH-20261025-FINAL-MYSKOOL \
  --confirm-cutoff \
  --confirm-owner \
  --actor-id=admin@iqra.edu.pk \
  --force-authorized-live

# Target a specific opening-balance invoice_item_id
php artisan reconciliation:myskool-historical \
  --apply \
  --item-id=<LIVE_INVOICE_ITEM_ID> \
  --cutoff=2026-10-22 \
  --batch-id=BATCH-20261025-FINAL-MYSKOOL \
  --confirm-cutoff \
  --confirm-owner \
  --actor-id=<ADMIN_USER_ID_OR_EMAIL> \
  --force-authorized-live
```

### 4.4 Audited Reconciliation Rollback (Compensating Reversal)
```bash
php artisan reconciliation:myskool-historical \
  --rollback \
  --reconcile-id=<RECONCILIATION_ID> \
  --confirm-owner \
  --actor-id=<ADMIN_USER_ID_OR_EMAIL>
```

### 4.5 Voided Fee Forensic Inspection (Read-Only Dry-Run)
```bash
# Default mode is read-only forensic inspection (0 DB writes)
php artisan billing:restore-voided-fee <LIVE_INVOICE_ITEM_ID>
```

### 4.6 Guarded Voided Fee Restoration Apply
```bash
php artisan billing:restore-voided-fee <LIVE_INVOICE_ITEM_ID> \
  --apply \
  --reason="Approved Finance restoration: <ticket/reference>" \
  --confirm-owner \
  --actor-id=<ADMIN_USER_ID_OR_EMAIL> \
  --force
```

---

## 5. Hostinger Safety Backup & Recovery Procedure (Task 5)

Based on [`live.md`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/live.md) and [`PHASE_9_DEPLOYMENT_READINESS.md`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/PHASE_9_DEPLOYMENT_READINESS.md), the Hostinger production environment uses:
- **SSH Endpoint:** `ssh -p 65002 u393409297@145.14.146.15`
- **Application Root:** `/home/u393409297/domains/iqra.edu.pk/public_html/billing`
- **Production Database:** `u393409297_iqra_billing` (MySQL/MariaDB on `127.0.0.1:3306`, user `u393409297_iqra_user`)

> [!IMPORTANT]
> Because Phase 4 strictly prohibits connecting to or altering production without explicit live authorization, **no live SSH session was opened during Phase 4**. The project owner / operator must execute the exact read-only backup commands below on Hostinger **before** pulling code or running migrations.

### 5.1 Pre-Deployment Hostinger Backup Commands (Run on Hostinger via SSH)

```bash
# 1. Create timestamped backup directory outside public web root
export RELEASE_TS=$(date -u +"%Y%m%dT%H%M%SZ")
export BACKUP_DIR="/home/u393409297/backups/phase4_${RELEASE_TS}"
export APP_DIR="/home/u393409297/domains/iqra.edu.pk/public_html/billing"
mkdir -p "${BACKUP_DIR}"
chmod 700 "${BACKUP_DIR}"

# 2. Record current Git commit, migration status, and PHP/MariaDB versions
cd "${APP_DIR}"
git rev-parse HEAD > "${BACKUP_DIR}/git_commit_before.txt"
git status -u > "${BACKUP_DIR}/git_status_before.txt"
php artisan migrate:status > "${BACKUP_DIR}/migrate_status_before.txt"
php -v > "${BACKUP_DIR}/php_version.txt"

# 3. Backup production .env configuration (permissions 600, never committed to Git)
cp -p "${APP_DIR}/.env" "${BACKUP_DIR}/dotenv.backup"
chmod 600 "${BACKUP_DIR}/dotenv.backup"

# 4. Consistent read-only physical database dump (--single-transaction does not lock InnoDB cashiers)
# Reads credentials directly from .env without echoing secrets to terminal history
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
sha256sum "${BACKUP_DIR}/${DB_NAME_VAL}_${RELEASE_TS}.sql" "${BACKUP_DIR}/${DB_NAME_VAL}_${RELEASE_TS}.sql.gz" > "${BACKUP_DIR}/checksums.sha256"

# 5. Archive application source code and storage/uploaded documents
tar -czf "${BACKUP_DIR}/app_source_${RELEASE_TS}.tar.gz" \
  --exclude='vendor' \
  --exclude='node_modules' \
  --exclude='storage/logs/*.log' \
  -C "${APP_DIR}" .

tar -czf "${BACKUP_DIR}/storage_${RELEASE_TS}.tar.gz" -C "${APP_DIR}" storage
```

### 5.2 Backup Integrity Verification Procedure
Before proceeding to deployment, verify the dump file on Hostinger:
```bash
# Verify non-zero size, completed dump footer, and SHA-256 checksum
ls -lh "${BACKUP_DIR}/${DB_NAME_VAL}_${RELEASE_TS}.sql"
tail -n 5 "${BACKUP_DIR}/${DB_NAME_VAL}_${RELEASE_TS}.sql"
# Expected final line: "-- Dump completed on 2026-..."
sha256sum -c "${BACKUP_DIR}/checksums.sha256"
```
If a disposable staging database is available (or after downloading the `.sql.gz` archive to local workstation), test restoration into a separate disposable schema (`iqra_hostinger_backup_verify_tmp`, **never** into `u393409297_iqra_billing` or `iqra_billing`):
```bash
mysql -u root -e "CREATE DATABASE iqra_hostinger_backup_verify_tmp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root iqra_hostinger_backup_verify_tmp < "${DB_NAME_VAL}_${RELEASE_TS}.sql"
mysql -u root -e "DROP DATABASE iqra_hostinger_backup_verify_tmp;"
```

---

## 6. Production Divergence Assessment & Read-Only Queries (Task 6)

### 6.1 Local Canonical Database (`iqra_billing`) Baseline Summary

Read-only inspection of local `iqra_billing` (`10.4.32-MariaDB`) establishes the following local baseline:
- **Core Entity Counts:** `2,706` families, `3,953` students, `3,953` enrollments, `10,286` invoices, `14,143` invoice items.
- **Active MySkool Batch (`BATCH-20261025-FINAL-MYSKOOL`):** `10,329` `qa_staged_dues` rows, `34,712` `qa_staged_collections` rows, `10,257` `qa_opening_balance_lineage` rows.
- **Local Portal Cashier Activity:** `1` payment (`SAR 280.00`, `status = 'received'`), `1` payment allocation (`SAR 280.00`), `2` void adjustments (`SAR 580.00` total across `2` voided `SAR` items).
- **Local Migration State:** `19` migrations applied (latest: `2026_10_25_003711_add_guardian_name_override_to_student_registration_details_table`); `2026_10_28_000020_create_qa_historical_settlements_table` is pending deployment.

#### Local `iqra_billing` Per-Currency Summary (Opening-Balance vs. All Invoice Items)
| Currency | Opening-Balance Items (`frequency = 'opening_balance'`) | Opening-Balance Net Minor (Formatted) | All Active Invoice Items | All Active Net Minor (Formatted) | Local Paid Minor | Local Voided Items |
| :--- | ---: | ---: | ---: | ---: | ---: | ---: |
| **`SAR`** | `6,094` | `170,840,700` (`SAR 1,708,407.00`) | `8,537` | `239,198,200` (`SAR 2,391,982.00`) | `28,000` (`SAR 280.00`) | `2` |
| **`AED`** | `3,997` | `111,425,700` (`AED 1,114,257.00`) | `5,404` | `150,743,200` (`AED 1,507,432.00`) | `0` (`AED 0.00`) | `0` |
| **`PKR`** | `148` | `216,300,000` (`PKR 2,163,000.00`) | `167` | `247,150,000` (`PKR 2,471,500.00`) | `0` (`PKR 0.00`) | `0` |
| **`QAR`** | `12` | `334,000` (`QAR 3,340.00`) | `23` | `655,500` (`QAR 6,555.00`) | `0` (`QAR 0.00`) | `0` |
| **`OMR`** | `6` | `17,400` (`OMR 174.00`) | `10` | `28,800` (`OMR 288.00`) | `0` (`OMR 0.00`) | `0` |
| **Total** | **`10,257`** | — | **`14,141` active** | — | — | **`2` voided** |

---

### 6.2 Live Hostinger Verification Status: **PENDING READ-ONLY EXECUTION**

Because live Hostinger database access was not executed during Phase 4, **live Hostinger divergence verification is marked PENDING** and must be executed using the exact read-only SQL queries below immediately after taking the Step 1 backup.

#### Parameterized Read-Only SQL Queries for Hostinger (`u393409297_iqra_billing`)

```sql
-- Q1: Engine version, migration state, and core table counts
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

-- Q2: Active MySkool batch distribution on Hostinger
SELECT batch_id, COUNT(*) AS dues_rows, SUM(balance_minor) AS total_balance_minor
FROM qa_staged_dues GROUP BY batch_id;

SELECT batch_id, COUNT(*) AS collection_rows, SUM(amount_minor) AS total_collection_minor
FROM qa_staged_collections GROUP BY batch_id;

-- Q3: Per-currency summary of live cashier payments, allocations, and adjustments on Hostinger
SELECT currency, status, COUNT(*) AS payment_count,
       SUM(amount_minor) AS total_paid_minor,
       SUM(unallocated_minor) AS total_unallocated_minor
FROM payments
GROUP BY currency, status
ORDER BY currency, status;

SELECT fa.kind, ii.currency, COUNT(*) AS adjustment_count, SUM(fa.amount_minor) AS total_adjusted_minor
FROM fee_adjustments fa
JOIN invoice_items ii ON ii.id = fa.invoice_item_id
GROUP BY fa.kind, ii.currency
ORDER BY fa.kind, ii.currency;

-- Q4: Per-currency invoice_items status breakdown on Hostinger
SELECT currency, status, COUNT(*) AS items_count,
       SUM(amount_minor) AS gross_minor,
       SUM(discount_minor) AS discount_minor,
       SUM(exemption_minor) AS exemption_minor,
       SUM(net_minor) AS net_minor,
       SUM(paid_minor) AS paid_minor
FROM invoice_items
GROUP BY currency, status
ORDER BY currency, status;

-- Q5: Inspect Family 5929 (GR 5929 & GR 5930) live IDs, statuses, payments, and adjustments on Hostinger
SELECT s.id AS student_id, s.gr_number, s.name AS student_name, f.id AS family_id, f.family_code,
       i.id AS invoice_id, i.invoice_number, i.status AS invoice_status, i.total_minor AS inv_total_minor, i.paid_minor AS inv_paid_minor,
       ii.id AS invoice_item_id, ii.description, ii.period_key, ii.currency,
       ii.amount_minor, ii.discount_minor, ii.exemption_minor, ii.net_minor, ii.paid_minor, ii.status AS item_status
FROM students s
JOIN families f ON f.id = s.family_id
LEFT JOIN invoice_items ii ON ii.student_id = s.id
LEFT JOIN invoices i ON i.id = ii.invoice_id
WHERE s.gr_number IN ('5929', '5930') OR f.family_code = '5929'
ORDER BY s.gr_number, ii.period_start, ii.id;

-- Q6: Identify Opening-Balance items on Hostinger that ALREADY have live cashier payments, discounts, exemptions, or voids
-- (These items will automatically trigger LIVE_ACTIVITY_HOLD in reconciliation:myskool-historical)
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

## 7. Ten-Step Deployment and Recovery Plan (Task 7)

This release plan strictly separates **code/schema deployment (Steps 1–5)** from **financial data reconciliation and restoration (Steps 6–9)**.

### Step 1: Verified Backup and Rollback Readiness
1. Execute the Hostinger backup script in **Section 5.1** to capture:
   - Consistent database dump (`${DB_NAME_VAL}_${RELEASE_TS}.sql` and `.sql.gz`)
   - Application source tarball (`app_source_${RELEASE_TS}.tar.gz`)
   - Storage tarball (`storage_${RELEASE_TS}.tar.gz`)
   - `.env` backup (`dotenv.backup`)
   - Pre-release Git commit hash (`git_commit_before.txt`) and migration list (`migrate_status_before.txt`).
2. Verify checksums and dump completion footer (`Section 5.2`). **Abort immediately if backup verification fails.**

### Step 2: Release Freeze and File Inventory
1. On the local workstation, stage and commit only the verified release files listed in **Section 1.2** (excluding temporary reports or scratch files if desired by the project owner) and push to `origin/master` once authorized.
2. Confirm with cashiers/operations a brief **5-minute maintenance window** for code deployment (Steps 3–5).

### Step 3: Code Deployment Preparation
1. SSH into Hostinger and enter the application root:
   ```bash
   cd /home/u393409297/domains/iqra.edu.pk/public_html/billing
   php artisan down --render="errors::503" --retry=60
   ```
2. Verify `.env` settings before pulling code:
   - Ensure `APPROVED_DATABASE_NAME=u393409297_iqra_billing` is set in `.env` (matching `DB_DATABASE=u393409297_iqra_billing` so `DatabaseSafetyGuard` validates the Hostinger database identity).
   - Ensure `RECONCILIATION_ALLOW_APPLY=false` is set in `.env` during code deployment so no reconciliation mutation can be triggered during deployment.
3. Pull the verified release commit and install production dependencies:
   ```bash
   git fetch origin
   git pull origin master
   composer install --no-dev --optimize-autoloader --no-interaction
   ```

### Step 4: Migration Compatibility Checks & Additive Schema Migration
1. Preview pending migrations without executing:
   ```bash
   php artisan migrate:status
   php artisan migrate --pretend
   ```
2. Confirm that **only** `2026_10_28_000020_create_qa_historical_settlements_table` is pending and that its pretend SQL only runs `CREATE TABLE qa_historical_settlements` and `CREATE TABLE qa_historical_settlement_collections`.
3. Execute the additive migration and rebuild caches:
   ```bash
   php artisan migrate --force
   php artisan optimize:clear
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   php artisan up
   ```

### Step 5: Post-Deploy Smoke Testing (Zero Financial Mutations)
1. Verify web portal health (`GET /login`, `GET /dashboard`, `GET /collection`).
2. Log in as an administrator and test:
   - Searching Family `5929` on `/collection` (confirm multi-currency summary cards and Fee History tab load cleanly).
   - Running a read-only **Create Fee Preview** (`POST /challan/preview-fee`) to confirm the 7-status eligibility table renders without saving.
   - If any voided fee exists on the family, clicking **Inspect & Restore** to verify the read-only forensic modal (`GET /challan/inspect-voided-fee/{itemId}`) loads without mutating data.

### Step 6: Live Historical Reconciliation Dry-Run & Divergence Audit
1. Run the read-only SQL divergence queries in **Section 6.2** against `u393409297_iqra_billing`.
2. Run the read-only Artisan audit and dry-run preview on Hostinger:
   ```bash
   php artisan billing:audit-myskool-reconciliation \
     --cutoff=2026-10-22 \
     --batch-id=BATCH-20261025-FINAL-MYSKOOL \
     --export=csv \
     --output=storage/app/hostinger_live_recon_audit.csv

   php artisan reconciliation:myskool-historical \
     --dry-run \
     --cutoff=2026-10-22 \
     --batch-id=BATCH-20261025-FINAL-MYSKOOL \
     --export-csv=storage/app/hostinger_live_recon_preview.csv
   ```
3. Inspect all `LIVE_ACTIVITY_HOLD` items (items where Hostinger cashiers already recorded payments, discounts, exemptions, or voids since go-live).

### Step 7: Finance Approval of Eligible Changes
1. Deliver `hostinger_live_recon_preview.csv` and the list of any voided items (`Q5`/`Q6` from Section 6.2) to the Finance Owner / Management.
2. Finance must explicitly sign off on:
   - Which `CANDIDATE_FULL_SETTLEMENT` items are approved for automated settlement.
   - How each `CONCESSION_REVIEW` item (such as `GR 5930`'s `QAR 50.00` remaining balance on MySkool Slip `27328`) should be handled (e.g., settle the verified `QAR 230.00` historical payment and either keep `QAR 50.00` collectible or apply an authorized `QAR 50.00` sibling discount via `FeeAdjustmentService`).
   - Which specific voided invoice item IDs are approved for restoration via `billing:restore-voided-fee`.

### Step 8: Separate Correction and Restoration Execution
1. Only after Step 7 written sign-off, temporarily enable `RECONCILIATION_ALLOW_APPLY=true` in `.env` (and run `php artisan config:clear`).
2. **Pilot Execution First (Single Student / Family):**
   - If `GR 5929` or `GR 5930`'s October 2026 item was accidentally voided on Hostinger, inspect and restore it first using its **live Hostinger `invoice_item_id`**:
     ```bash
     php artisan billing:restore-voided-fee <LIVE_ITEM_ID>
     php artisan billing:restore-voided-fee <LIVE_ITEM_ID> \
       --apply \
       --reason="Finance-approved restoration of Oct-2026 fee (Slip 27328)" \
       --confirm-owner \
       --actor-id=<ADMIN_USER_ID_OR_EMAIL> \
       --force
     ```
   - If `GR 5929`'s October 2026 item is active and unpaid on Hostinger, reconcile it individually first:
     ```bash
     php artisan reconciliation:myskool-historical \
       --apply \
       --gr=5929 \
       --cutoff=2026-10-22 \
       --batch-id=BATCH-20261025-FINAL-MYSKOOL \
       --confirm-cutoff \
       --confirm-owner \
       --actor-id=<ADMIN_USER_ID_OR_EMAIL> \
       --force-authorized-live
     ```
3. Verify the pilot family (`5929`) in `/collection` before executing any broader currency-by-currency or batch-wide `--apply`.
4. Immediately after completing approved settlements, reset `RECONCILIATION_ALLOW_APPLY=false` in `.env` and run `php artisan config:cache`.

### Step 9: Post-Release Financial Verification
1. Verify that `SELECT COUNT(*), SUM(amount_minor) FROM payments` is **unchanged** before vs. after historical reconciliation (confirming zero inflation of portal cashier collections).
2. Verify `SELECT status, currency, COUNT(*), SUM(settled_amount_minor) FROM qa_historical_settlements GROUP BY status, currency` matches the Finance-approved dry-run totals to the exact minor unit.
3. Re-run `php artisan reconciliation:myskool-historical --dry-run` to confirm idempotency (`0` remaining unapplied full-settlement candidates among the applied group).

### Step 10: Incident Recovery Procedure
- **Case A — Reversing an Individual Historical Settlement (Safe Compensating Rollback):**
  If any specific settlement needs to be undone (and no subsequent cashier payment was allocated to that item):
  ```bash
  php artisan reconciliation:myskool-historical \
    --rollback \
    --reconcile-id=<RECONCILIATION_ID> \
    --confirm-owner \
    --actor-id=<ADMIN_USER_ID_OR_EMAIL>
  ```
- **Case B — Application Code Rollback (Before Financial Mutations):**
  If a code issue is detected during Step 5 smoke testing before any financial mutations are applied:
  ```bash
  cd /home/u393409297/domains/iqra.edu.pk/public_html/billing
  php artisan down
  php artisan migrate:rollback --step=1 --force
  git checkout $(cat "${BACKUP_DIR}/git_commit_before.txt")
  cp -p "${BACKUP_DIR}/dotenv.backup" .env
  composer install --no-dev --optimize-autoloader --no-interaction
  php artisan optimize:clear
  php artisan up
  ```
- **Case C — Full Emergency Database Recovery:**
  Only in the event of a catastrophic database failure during a maintenance window where zero new cashier transactions have occurred since `${BACKUP_DIR}/${DB_NAME_VAL}_${RELEASE_TS}.sql` was taken, restore the verified SQL dump after taking a forensic snapshot of the failed state.

---

## 8. Final Test Results & Readiness Assessment (Task 8)

### 8.1 Automated Regression & Integration Test Results
| Test Suite / Environment | Engine | Tests | Assertions | Result |
| :--- | :--- | ---: | ---: | :--- |
| **Full Application Regression Suite** (`php vendor/bin/phpunit`) | SQLite `:memory:` | `420` | `2,827` | **PASS (100%)** |
| **`HistoricalPaymentReconciliationTest`** (includes DB-level uniqueness, `ON DELETE RESTRICT`, & CLI actor gates) | SQLite & MariaDB `10.4.32` | `21` | `157` | **PASS (100%)** |
| **`VoidedFeeRestorationTest`** (includes cross-currency block, pre-cutoff obligation block, & shared Slip `27328`) | SQLite & MariaDB `10.4.32` | `17` | `125` | **PASS (100%)** |
| **`MySkoolReconciliationAuditTest`** | SQLite `:memory:` | `7` | `58` | **PASS (100%)** |
| **`FeeCollectionAccountingTest`** | SQLite `:memory:` | `32` | `290` | **PASS (100%)** |

### 8.2 Remaining Unresolved Risks & Required Management Approvals
1. **Live Hostinger Cashier Overlap Risk (Mitigated by Guard, Pending Live Dry-Run):**
   - Cashiers on Hostinger have been recording payments since go-live. While `HistoricalPaymentReconciliationService` automatically places any item with portal payments, allocations, discounts, exemptions, or voids into `LIVE_ACTIVITY_HOLD`, the exact count of overlapping items on Hostinger must be inspected via Step 6 before Finance approves Step 8.
2. **Hostinger `.env` `APPROVED_DATABASE_NAME` Requirement:**
   - Because `DatabaseSafetyGuard` enforces that `DB_DATABASE` matches `APPROVED_DATABASE_NAME` (defaulting to `iqra_billing`), the deployment operator **must** confirm `APPROVED_DATABASE_NAME=u393409297_iqra_billing` is present in Hostinger's `.env` during Step 3.
3. **Required Approvals Before Step 8 (Financial Apply):**
   - **Project Owner / Technical Lead Approval:** Sign-off on Step 1 backup checksums and Step 6 live dry-run report.
   - **Finance Administrator Approval:** Written sign-off on the exported `hostinger_live_recon_preview.csv` candidate rows and explicit decision on the `6` `POST_CUTOFF_SIBLING_CONCESSION` items (including `GR 5930`'s `QAR 50.00` balance).
