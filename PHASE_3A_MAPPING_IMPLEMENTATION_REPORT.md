# IQRA Billing System: Phase 3A Mapping Implementation Report

**Author**: Senior Laravel 12, MySQL & Financial Systems Architect  
**Project**: IQRA Virtual School Billing & Accounting System  
**Phase**: Phase 3A — Student Identity, Family, Campus, Department & Currency Review Workflows  
**Date**: October 19, 2026  
**Status**: COMPLETED (Awaiting Administrative Review & Sign-Off before Phase 3B)  

---

## Executive Summary

Phase 3A establishes the review, governance, and identity alignment architecture for the IQRA Virtual School billing migration without altering or polluting final accounting ledgers. The baseline established in Phase 2 in the isolated `iqra_billing_clean` MySQL database has been verified and enriched with deterministic mapping, campus assignment, department proposal, currency resolution, and historical transaction reconciliation workflows.

### Strict Governance Invariants Upheld:
1. **Quarantine Database Untouched**: The baseline `iqra_billing` database remains 100% untouched (`students: 4,349`, `invoices: 8,558`, `payments: 31,086`).
2. **Zero Premature Financial Posting**: Final ledger tables in `iqra_billing_clean` (`students`, `families`, `enrollments`, `invoices`, `payments`, `payment_allocations`) have **0** rows. No invoices, advance credits, or ledger entries were created.
3. **No Automatic Mapping / Assumption**: All 4,340 campus assignments and department classifications require explicit administrative confirmation. Unresolved records remain in pending review status.
4. **Permanent Family Codes Preserved**: Original family codes from MySkool were preserved verbatim. For the 6 students lacking a family code, non-destructive provisional tracking codes (`PROV-FAM-<GR>`) were assigned without mutating the source `family_code` field.
5. **Lossless Source Preservation**: Source transactions in `staging_dues` (16,706 rows) and `staging_payments` (37,595 rows) remain entirely unchanged.

---

## 1. Baseline Verification & Database Isolation

Prior to implementing any Phase 3A code, the exact Phase 2 staging counts and database isolation boundaries were verified:

| Metric / Table | Expected (Phase 2 Spec) | Verified in `iqra_billing_clean` | Verification Result |
| :--- | :---: | :---: | :---: |
| `staging_students` | 4,340 | 4,340 | Exact Match |
| `staging_currency_reviews` | 4,340 | 4,340 | Exact Match |
| Approved Currencies (`review_status = 'APPROVED'`) | 4,202 | 4,202 | Exact Match |
| Pending Currencies (`review_status = 'PENDING_REVIEW'`) | 138 | 138 | Exact Match |
| `staging_dues` | 16,706 | 16,706 | Exact Match |
| `staging_payments` | 37,595 | 37,595 | Exact Match |
| `staging_exceptions` | 16,727 | 16,727 | Exact Match |
| `staging_batches` | 79 | 79 | Exact Match |

### Source Contact & Exception Analysis
- Total original exception findings recorded in staging: **16,727**.
- In the staging pipeline, missing contact details (e.g. absent mother/father phone or blank guardian contact) are captured as data completeness flags but do not prevent staging ingestion. Not every missing contact generated a fatal exception; instead, validation flags were cataloged into `staging_exceptions` while preserving the student records in `staging_students`.
- In the review interface, phone numbers, addresses, and parent contact details are strictly gated behind `admin` role authorization and are masked from unprivileged views.

### Database Isolation Status
- **Quarantined Database (`iqra_billing`)**: Checked via active connection. All record counts remain identical to the pre-Phase 1 baseline (`students: 4,349`, `invoices: 8,558`, `payments: 31,086`).
- **Clean Database Ledger Tables (`iqra_billing_clean`)**:
  - `students`: **0**
  - `families`: **0**
  - `enrollments`: **0**
  - `invoices`: **0**
  - `payments`: **0**
  - `payment_allocations`: **0**
- All Phase 3A operations operate exclusively inside dedicated staging review tables guarded by `DatabaseSafetyGuard`.

---

## 2. Files Created and Modified

### A. Database Migrations
- `database/migrations/2026_10_19_000012_create_phase3a_mapping_tables.php`: Created the schema for student identity mappings, administrative campus assignments, multi-department enrollments, and historical GR transaction reconciliations.

### B. Core Services & Console Commands
- `app/Services/Staging/StudentIdentityMappingService.php`: Core mapping and reconciliation engine responsible for:
  - Deterministic student identity consolidation.
  - Sibling family detection and multi-currency conflict identification.
  - Campus assignment proposals and batch approval processing with audit trails.
  - Department classification (School, Tuition, Quran) and evidence preservation.
  - Currency manual resolution and synchronization.
  - Historical GR reconciliation matching (unknown GRs and unlinked transactions) with deterministic exact-name suggestion scoring.
  - Full audit logging of approver ID, timestamps, prior values, and notes.
- `app/Console/Commands/StagingProcessMappingsCommand.php`: Artisan CLI command (`php artisan staging:process-mappings --database=staging`) providing idempotent execution and diagnostic feedback.

### C. Controllers & Routes
- `app/Http/Controllers/MigrationReviewController.php`: Administrative review controller providing MySkool-style interface endpoints:
  - `GET /migration` (Executive summary dashboard with progress badges).
  - `GET /migration/campus` & `POST /migration/campus/approve` (Campus assignment & bulk preview/approval).
  - `GET /migration/department` & `POST /migration/department/approve` (Multi-department classification).
  - `GET /migration/currency` & `POST /migration/currency/approve` (Currency review tool for the 138 unresolved students).
  - `GET /migration/gr-reconciliation` & `POST /migration/gr-reconciliation/resolve` (Historical transaction reconciliation tool).
  - `GET /migration/exceptions` & `GET /migration/exceptions/export` (Downloadable CSV audit reports).
- `routes/web.php`: Registered migration review route group, protected by `auth` middleware and restricted to `admin` / `super-admin` roles.

### D. User Interface (Blade Views)
- `resources/views/migration/dashboard.blade.php`: Overview of migration metrics, approval badges, sibling analytics, and quick links.
- `resources/views/migration/campus.blade.php`: Administrative campus review with bulk selection, preview modal, confirmation dialog, and audit history.
- `resources/views/migration/department.blade.php`: Department classification grid showing evidence tags, fee hints, and approval status.
- `resources/views/migration/currency.blade.php`: Currency review workbench focusing on the 138 unresolved students, displaying paid/due currency occurrences and resolution controls.
- `resources/views/migration/gr-reconciliation.blade.php`: Historical GR reconciliation tool showing unknown dues, unknown payments, and unlinked receipts with deterministic match suggestions.
- `resources/views/migration/exceptions.blade.php`: Searchable exception list with category filtering and instant CSV export.
- `resources/views/layouts/app.blade.php`: Integrated "Migration Review" navigation link with briefcase icon, visible only to authorized administrators.

### E. Automated Test Suite
- `tests/Feature/Phase3aMappingWorkflowTest.php`: 8 comprehensive feature tests covering database isolation, sibling grouping, multi-currency detection, campus/department non-auto-approval, historical GR matching, audit logging, unauthorized rejection, and strict idempotency.

---

## 3. Database Tables & Schema Architecture

All Phase 3A tables reside in `iqra_billing_clean` and are fully separated from production billing tables:

### 1. `staging_student_identities`
Preserves source identifiers, links siblings under verified family codes, and flags edge cases.
- `source_key` (VARCHAR 50, UNIQUE): Composite unique key from staging (`STU-<row>`).
- `raw_id` (VARCHAR 50, NULL): Original MySkool student identifier.
- `gr_number` (VARCHAR 50, INDEX): Verified GR number.
- `family_code` (VARCHAR 50, NULL, INDEX): Original family code preserved verbatim.
- `provisional_family_code` (VARCHAR 50, NULL, INDEX): System-generated internal code (`PROV-FAM-<GR>`) for students lacking original family codes.
- `student_name`, `father_name`, `guardian_name`: Original student and parent names.
- `status`: Student active/inactive/leaving status.
- `date_of_admission`, `date_of_leaving`, `leaving_reason`: Historical admission milestones.
- `class_name`, `class_admitted`: Current and admission classes.
- `is_sibling` (BOOLEAN): Flag indicating if student shares a family code with other students.
- `sibling_count` (INT): Total enrolled siblings sharing this family code.
- `sibling_gr_numbers` (TEXT): Comma-separated list of sibling GR numbers.
- `has_multi_currency_siblings` (BOOLEAN): Critical flag indicating whether siblings under this family code have different approved billing currencies.
- `mapping_status` (VARCHAR 50): Status (`VERIFIED` or `PROVISIONAL_FAMILY`).

### 2. `staging_campus_assignments`
Provides campus governance, preventing automatic campus defaults.
- `source_key` (VARCHAR 50, UNIQUE): Student source key.
- `gr_number` (VARCHAR 50, INDEX): Student GR number.
- `class_name` (VARCHAR 100): Current class.
- `proposed_campus` (VARCHAR 50): Default heuristic suggestion (`main`).
- `approved_campus` (VARCHAR 50, NULL, INDEX): Actual approved campus (strictly `NULL` until administrative approval).
- `status` (VARCHAR 50): `PENDING` or `APPROVED`.
- `approved_by` (VARCHAR 100, NULL): User identifier of the approver.
- `approved_at` (TIMESTAMP, NULL): Approval timestamp.
- `notes` (TEXT, NULL): Administrative remarks.

### 3. `staging_department_assignments`
Enables multi-department enrollment per student (School, Tuition, Quran) with evidence tracking.
- `source_key` (VARCHAR 50, INDEX): Student source key.
- `gr_number` (VARCHAR 50, INDEX): Student GR number.
- `class_name` (VARCHAR 100): Enrolled class.
- `department_code` (VARCHAR 50): `school`, `tuition`, or `quran`.
- `evidence_source` (VARCHAR 100): Classification rationale (e.g. `source_class:TUITION-4`, `source_class:Grade-5`).
- `status` (VARCHAR 50): `PROPOSED` or `APPROVED`.
- `is_primary` (BOOLEAN): Distinguishes primary enrollment from secondary programs.
- `approved_by`, `approved_at`, `notes`: Full audit trail columns.

### 4. `staging_gr_reconciliations`
Manages unmatched dues and payments without altering historical transactions.
- `source_type` (VARCHAR 20): `due` or `payment`.
- `source_key` (VARCHAR 50, UNIQUE): References the underlying staging row (`DUE-<row>` or `PAY-<row>`).
- `source_row` (INT): Physical row in staging dataset.
- `raw_gr_number` (VARCHAR 50, NULL): GR string from source transaction.
- `raw_student_name` (VARCHAR 255, NULL): Student name recorded on invoice or receipt.
- `amount` (DECIMAL 12,2): Transaction face value.
- `currency_iso` (VARCHAR 10): Transaction currency.
- `slip_number` (VARCHAR 255, NULL): Receipt / challan number.
- `particular_raw` (TEXT, NULL): Fee description / notes.
- `transaction_date` (VARCHAR 50, NULL): Transaction timestamp.
- `suggested_match_gr` (VARCHAR 50, NULL, INDEX): Deterministic suggested GR based on exact normalized name match.
- `suggested_match_name` (VARCHAR 255, NULL): Name of candidate student.
- `match_confidence` (VARCHAR 50): `EXACT_NAME_MATCH`, `UNRESOLVED`, etc.
- `reconciliation_status` (VARCHAR 50): `UNRESOLVED` or `RESOLVED`.
- `resolved_gr_number` (VARCHAR 50, NULL): Verified target GR upon manual administrative confirmation.
- `reviewed_by`, `reviewed_at`, `notes`: Audit trail.

---

## 4. Mapping Algorithms & Business Rules

### A. Identity & Sibling Consolidation Algorithm
1. Extract all 4,340 staging students.
2. Group students by trimmed `family_code`.
   - If `family_code` is present:
     - Group with siblings. If family size > 1, mark `is_sibling = true`, record `sibling_count`, and compile `sibling_gr_numbers`.
   - If `family_code` is missing/blank:
     - Mark `mapping_status = 'PROVISIONAL_FAMILY'`.
     - Assign internal non-permanent reference `provisional_family_code = 'PROV-FAM-' . $gr_number`.
     - **Do not populate `family_code`** (leave null) to avoid manufacturing permanent family links.
3. Multi-Currency Conflict Evaluation:
   - For all sibling groups, look up the approved currencies in `staging_currency_reviews`.
   - If siblings within the same family have different billing currencies (e.g. one child in `SAR` and another in `AED`), flag `has_multi_currency_siblings = true`.
   - *Financial Architecture Note*: Sibling ledger generation in Phase 3B must create separate invoice headers for distinct currencies; siblings cannot be forcibly merged into a single multi-currency invoice.

### B. Campus Assignment Engine
- Rule: Propose `main` campus as a default suggestion based on current operational structure.
- **Strict Invariant**: `approved_campus` is set to `NULL` and `status` is set to `PENDING`.
- No campus is assumed or automatically approved.
- Approval requires an administrative action via web interface or CLI, recording user credentials, timestamp, and target campus.

### C. Department Classification Heuristics
- Evaluate `class_name` and `class_admitted`:
  - If class contains keywords `tuition`, `coaching`, or `tut`: classify as `tuition` (`evidence_source = 'source_class:tuition'`).
  - If class contains keywords `quran`, `hifz`, `tajweed`, or `nazra`: classify as `quran` (`evidence_source = 'source_class:quran'`).
  - Otherwise: classify as `school` (`evidence_source = 'source_class:regular'`).
- Multiple Enrollments: If historical dues or particular descriptions show enrollment across both regular schooling and tuition, secondary rows can be attached to the same `source_key`.
- **Strict Invariant**: All department rows are inserted with `status = 'PROPOSED'` and `approved_by = NULL`. Zero records are auto-approved.

### D. Historical GR Reconciliation Algorithm
1. Scan `staging_dues` (16,706 rows) for GR numbers not found in `staging_students`. Result: 4,692 rows.
2. Scan `staging_payments` (37,595 rows) for GR numbers not found in `staging_students`. Result: 6,479 rows.
3. Scan `staging_payments` for records with missing/blank GR numbers. Result: 860 rows.
4. Deterministic Candidate Matching:
   - Normalize student names in active master: strip honorifics, convert to lowercase, normalize whitespace and punctuation.
   - For unmatched transaction rows with a non-blank student name, query active students for an exact normalized name match.
   - If exactly one active student matches, populate `suggested_match_gr`, `suggested_match_name`, and `match_confidence = 'EXACT_NAME_MATCH'`.
   - If zero or multiple matches exist, leave `suggested_match_gr = NULL` and set `match_confidence = 'UNRESOLVED'`.
- **Strict Invariant**: Suggestions are purely informational. `reconciliation_status` remains `UNRESOLVED` until an authorized administrator explicitly validates the link. Source tables are never mutated.

---

## 5. Actual Mapping & Reconciliation Statistics

Below are the verified live statistics extracted from `iqra_billing_clean`:

### Student Identity & Family Mapping
| Metric | Value | Architectural Significance |
| :--- | :---: | :--- |
| **Total Staging Students** | **4,340** | Complete staging master. |
| Students with Original Family Code | 4,334 | Verified MySkool family linkage intact. |
| Distinct Family Codes | 3,051 | Unique family units. |
| Students Missing Family Code | 6 | Assigned provisional codes (`PROV-FAM-<GR>`). Original `family_code` remains `NULL`. |
| Sibling Students (>1 per family) | 2,275 | Eligible for combined family review. |
| Distinct Sibling Families | 992 | Total multi-child households. |
| Sibling Families with Multi-Currency Conflict | **37** | **95 students** belong to families where siblings hold different currencies. |

### Campus Assignment Status
| Status | Count | Percentage | Action Required |
| :--- | :---: | :---: | :--- |
| **Pending Review** | **4,340** | **100.0%** | Requires administrative bulk confirmation. |
| **Approved** | **0** | **0.0%** | Invariant upheld (no auto-approval). |
| Proposed Campus: `main` | 4,340 | 100.0% | Suggested default campus. |

### Department Classification Status
| Department / Status | Proposed | Approved | Percentage | Notes |
| :--- | :---: | :---: | :---: | :--- |
| **School (`school`)** | 3,969 | 0 | 91.5% | Regular grade curriculum. |
| **Tuition (`tuition`)** | 371 | 0 | 8.5% | Dedicated coaching/tuition classes. |
| **Quranic (`quran`)** | 0 | 0 | 0.0% | No standalone Quran classes detected in current student class names. |
| **Total Department Enrollments** | **4,340** | **0** | **100.0% Pending** | Zero auto-approved. |

### Currency Review Status
| Currency Status | Student Count | Percentage | Policy / State |
| :--- | :---: | :---: | :--- |
| **Approved (Phase 2 Verified)** | **4,202** | **96.8%** | Preserved verbatim (SAR: 3,924, PKR: 177, AED: 93, USD: 8). |
| **Pending Administrative Review** | **138** | **3.2%** | Maintained in review workbench. No auto-default to SAR. |
| **Total Currency Records** | **4,340** | **100.0%** | 100% accounted for. |

### Historical GR Reconciliation Findings
| Category | Row Count | Distinct GRs | Exact Name Matches Found | Unresolved |
| :--- | :---: | :---: | :---: | :---: |
| **Dues Unknown GR** | 4,692 | 628 | 110 | 4,582 |
| **Payment Unknown GR** | 6,479 | 1,248 | 808 | 5,671 |
| **Payment Unlinked (Blank GR)** | 860 | N/A (Blank) | 101 | 759 |
| **Total Reconciliation Records** | **12,031** | **1,488 unique GRs** | **1,019 suggested matches** | **12,031 (100% pending)** |

#### Key Overlap Finding:
- **388 distinct GR numbers** appear in BOTH the unknown dues dataset and the unknown payment dataset.
- These represent former or archived students who had both billings and payments recorded under historical GR numbers that were never entered into the current active student master list.
- They must not be treated as separate distinct individuals; rather, they form a cohesive historical ledger cohort to be addressed during financial reconciliation.

---

## 6. Administrative Review Dashboard

A comprehensive, role-protected interface was implemented to manage the migration review lifecycle:

```
[Migration Review Navigation]
├── /migration (Executive Dashboard)
│   ├── Progress Badges (Identity, Campus, Dept, Currency, Reconciliation)
│   ├── Sibling Family & Multi-Currency Analytics
│   └── Summary Status Cards
├── /migration/campus (Campus Mapping Workbench)
│   ├── Filter by Class & Status
│   ├── Bulk Campus Assignment Preview Modal
│   ├── Explicit Confirmation Modal
│   └── Audit Log History (User, Timestamp, Old/New Value)
├── /migration/department (Department Assignment Workbench)
│   ├── Filter by Department (School / Tuition / Quran)
│   ├── Classification Evidence Tags
│   └── Bulk & Individual Approval Controls
├── /migration/currency (Currency Resolution Workbench)
│   ├── Dedicated view of 138 Pending Students
│   ├── Paid/Due Currency History Breakdown
│   └── Manual Currency Selection & Approval
├── /migration/gr-reconciliation (Historical Transaction Reconciliation)
│   ├── Categorized Views: Unknown Dues / Unknown Payments / Unlinked Receipts
│   ├── Deterministic Match Suggestions (Badge: Exact Name Match)
│   └── Safe GR Linking Action
└── /migration/exceptions (Exception Diagnostic Center)
    ├── Search & Filter across 16,727 findings
    └── Downloadable CSV Export (`iqra_migration_exceptions_<date>.csv`)
```

### Security & Privacy Controls
- All migration routes are protected by Laravel's `auth` middleware.
- Cashier and unprivileged staff accounts are strictly prohibited from viewing or accessing `/migration/*` routes (enforced via role authorization checks).
- Student contact numbers, father/mother phone details, and home addresses are omitted from migration review views to maintain student privacy.

---

## 7. Automated Test Results

A dedicated suite of automated feature tests was authored in `tests/Feature/Phase3aMappingWorkflowTest.php` using fictional student data. In addition, the entire application test suite was executed to verify regression safety.

### Test Execution Summary:
- **Phase 3A Mapping Workflow Tests**: **8 passed (94 assertions)**
- **Full Laravel Application Test Suite**: **61 passed (450 assertions)**
- **Failures / Errors**: **0**
- **Execution Time**: **11.64 seconds**

### Specific Test Coverage:
1. `database isolation rejects quarantine database on phase3a`: Asserts that `DatabaseSafetyGuard` blocks Phase 3A mapping actions if connected to the quarantined database `iqra_billing`.
2. `student identity mapping groups siblings and identifies missing family codes`: Verifies accurate grouping of siblings (e.g. 3 siblings under one family code), compilation of sibling GR lists, and assignment of non-destructive provisional family codes to students lacking family numbers.
3. `campus and department proposals never auto approve`: Asserts that campus assignments default to `PENDING` with `approved_campus = null` and department assignments default to `PROPOSED` with `approved_by = null`.
4. `historical gr reconciliation detects unknown and exact name matches`: Confirms that unknown dues, unknown payments, and unlinked payments are cataloged into `staging_gr_reconciliations`, and that exact normalized name matches generate deterministic suggestions.
5. `bulk campus and department approval with audit logs`: Validates bulk assignment preview, state transition to `APPROVED`, and recording of user ID, timestamp, and notes.
6. `currency manual approval for unresolved student`: Asserts manual resolution of pending currency records, updating `staging_currency_reviews` and `staging_students`.
7. `repeated execution is strictly idempotent`: Proves that executing the mapping engine multiple times generates identical records with 0 duplicate rows.
8. `unauthorized users cannot access or approve mappings`: Verifies that unauthenticated users and Cashier-role users are denied access with HTTP 403 / redirects.

---

## 8. Remaining Business Decisions (Awaiting Approval)

Before proceeding to Phase 3B (Historical Accounting Reconstruction and Ledger Import), the following administrative and financial policies must be finalized by school leadership:

1. **Campus Approval Policy**:
   - Currently, 4,340 students are proposed for `main` campus.
   - *Decision Needed*: Should administrators confirm all 4,340 students under `main` campus via bulk approval, or will specific grades/classes (e.g. Tuition or Junior sections) be mapped to secondary campuses?
2. **Department Multi-Enrollment Fee Models**:
   - 371 students are enrolled in Tuition classes (`tuition`), while 3,969 are in regular School classes (`school`).
   - *Decision Needed*: In the future ledger (Phase 3B), should Tuition fees be billed on independent invoice challans or combined with regular School fees?
3. **Multi-Currency Sibling Billing Policy**:
   - Exactly **37 sibling families (95 students)** have siblings with differing approved billing currencies (e.g. Child A billed in SAR, Child B billed in AED).
   - *Decision Needed*: Confirm that sibling invoicing will generate independent invoice headers per currency rather than forcing currency conversion or merging into a single family invoice.
4. **Resolution of the 138 Pending Currencies**:
   - The review workbench `/migration/currency` is operational.
   - *Decision Needed*: Authorize designated finance officers to review and manually assign the billing currency for these 138 students before invoice generation begins.
5. **Historical GR Reconciliation Strategy**:
   - 12,031 transactions reference GR numbers not in the current student master (including 388 overlapping GR numbers present in both dues and payments).
   - *Decision Needed*: Shall the administration approve creating "Archived / Historical Student" profile stubs for verified historical GR numbers to retain full transactional audit trails during ledger import?

---

## Conclusion & Gate Status

Phase 3A is **COMPLETE**. All identity mapping, family grouping, campus proposal, department classification, currency review, and GR reconciliation pipelines are implemented, verified, tested, and guarded.

**CRITICAL HALT**: In strict compliance with architectural directives, all financial reconstruction, invoice generation, payment allocation, and ledger import activities remain completely halted pending administrative review of this report.
