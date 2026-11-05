# IQRA Billing — MySkool Final Financial Exception Summary

**Generated:** 2026-10-28  
**Target Database:** Local `iqra_billing` (Strictly Read-Only Forensic Audit)  
**Reconciliation Batch:** `BATCH-20261025-FINAL-MYSKOOL`  
**Cutoff Date:** `2026-10-22`  
**Related Artifact:** [`MYSKOOL_FINAL_EXCEPTION_LIST.csv`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/MYSKOOL_FINAL_EXCEPTION_LIST.csv)

---

## 1. Executive Summary & Required Key Metrics

A comprehensive, strictly read-only audit of the local `iqra_billing` database was executed using the implemented `MySkoolReconciliationAuditService`, `MySkoolPaymentCorrectionService`, and `VoidedFeeRestorationService`. All historical settlements, staged collections, opening balance lineage items, and portal adjustments were evaluated against the official October 22, 2026 migration cutoff.

All **246 correctly settled full payments**, **211 genuine pre-cutoff residual balances**, and **13 correctly settled partial payments** were verified and **cleanly excluded** from the exception queue in accordance with project instructions. Zero calculation anomalies were found in the 265 applied historical settlements (all mathematical balance invariants hold 100%). Zero conflicts exist with portal cashier payments (the single portal receipt recorded in the system is completely isolated to a post-migration registration student).

A total of **20 exceptional invoice items** across **16 unique students (GR numbers)** and **14 families** remain with unresolved accounting conditions requiring manual action, management sign-off, or correction UI execution.

### Key Summary Metrics (as required by Instruction 9)

| Metric | Count | Details & Affected Entities |
| :--- | :---: | :--- |
| **Total Unique Affected GR Numbers** | **16** | GR 5930, 5130, 5168, 5502, 7364, 7266, 8620, 6562, 10051, 10052, 7611, 8857, 10111, 10282, 10283, 3230 |
| **Total Affected Families** | **14** | Families 5929, 5130, 5166, 5502, 5615, 7264, 8563, 6562, 10051, 4087, 8857, 4985, 5506, 3135 |
| **Confirmed Accounting Errors** | **9** | GR 6562 (2 duplicate OB items), GR 10051 & 10052 (2 improperly voided fees), GR 8620 (2 currency mismatches), GR 3230 (3 outstanding currency conflicts) |
| **Cases Requiring Finance Approval** | **20** | All 20 exception items require Finance / Management review and sign-off prior to balance adjustment or ledger modification |
| **Cases Requiring Source Evidence** | **10** | GR 8620 (2), GR 7611 (1), GR 8857 (1), GR 10111 (1), GR 10282 (1), GR 10283 (1), GR 3230 (3) require voucher / slip / registration verification |
| **Cases Resolvable via Existing Correction UI** | **7** | GR 10051 & 10052 (Voided Fee Restoration UI), GR 7611, 8857, 10111, 10282, 10283 (MySkool Payment Correction UI reassignment / review) |
| **Cases Requiring a Separate Technical Fix** | **5** | GR 8620 (2 AED/SAR cross-currency settlement), GR 3230 (3 PKR/SAR currency redenomination & invoice re-issue) |

---

## 2. Detailed Breakdown of Financial Exception Categories

### A. Sibling Concession Reviews (`CONCESSION_REVIEW`) — 6 Items / 6 GRs / 6 Families

In all 6 cases, a post-cutoff payment was received on or after October 23, 2026 for less than the nominal opening balance item amount, leaving an unpaid residual that matches the student's recurring sibling discount pattern. Cash received was verified and settled to the extent paid; the remaining balance is held pending Finance determination whether to write off the remainder as an approved concession or pursue collection.

1. **GR 5930 — Zahra Feroz** (Family `5929`)
   - **Item ID:** `14329` | **Invoice:** `SCH-OB-2026-0000085-512` | **Period:** `Oct-2026`
   - **Currency:** `QAR` | **Original Amount:** `280.00` | **Hist. Paid:** `230.00` | **Outstanding:** `50.00`
   - **Slip Reference:** `Slip 27328` (2026-10-24)
   - **Condition:** Paid QAR 230.00 against QAR 280.00 opening balance (difference QAR 50.00). Sibling in family 5929 has an active QAR 50.00 discount tier.
   - **Recommended Action:** Finance review to approve QAR 50.00 concession discount (post `FeeAdjustment` with kind `concession`) or retain as collectible balance.
   - **Resolvable via MySkool Correction UI:** No (Requires Fee Adjustment).
   - **Management Approval:** **Required** (Finance sign-off).

2. **GR 5130 — Rashid** (Family `5130`)
   - **Item ID:** `13378` | **Invoice:** `SCH-OB-2026-0000085-384` | **Period:** `Oct-2026`
   - **Currency:** `SAR` | **Original Amount:** `330.00` | **Hist. Paid:** `270.00` | **Outstanding:** `60.00`
   - **Slip Reference:** `Slip 27223` (2026-10-23)
   - **Condition:** Paid SAR 270.00 against SAR 330.00 opening balance (difference SAR 60.00). Matches recurring sibling concession pattern.
   - **Recommended Action:** Finance review to post SAR 60.00 sibling concession adjustment or retain balance.
   - **Resolvable via MySkool Correction UI:** No (Requires Fee Adjustment).
   - **Management Approval:** **Required**.

3. **GR 5168 — Alaa** (Family `5166`)
   - **Item ID:** `13406` | **Invoice:** `SCH-OB-2026-0000084-110` | **Period:** `Aug-2026`
   - **Currency:** `SAR` | **Original Amount:** `200.00` | **Hist. Paid:** `100.00` | **Outstanding:** `100.00`
   - **Slip Reference:** `Slip 27204` (2026-10-23)
   - **Condition:** Paid SAR 100.00 against SAR 200.00 opening balance (difference SAR 100.00). Matches recurring 50% concession pattern.
   - **Recommended Action:** Finance review to approve SAR 100.00 concession adjustment or confirm partial payment status.
   - **Resolvable via MySkool Correction UI:** No (Requires Fee Adjustment).
   - **Management Approval:** **Required**.

4. **GR 5502 — Amnah Imad Khan** (Family `5502`)
   - **Item ID:** `13794` | **Invoice:** `SCH-OB-2026-0000086-178` | **Period:** `Sep-2026`
   - **Currency:** `SAR` | **Original Amount:** `400.00` | **Hist. Paid:** `330.00` | **Outstanding:** `70.00`
   - **Slip Reference:** `Slip 27272` (2026-10-23)
   - **Condition:** Paid SAR 330.00 against SAR 400.00 opening balance (difference SAR 70.00). Matches sibling concession pattern.
   - **Recommended Action:** Finance review to approve SAR 70.00 concession adjustment or retain balance.
   - **Resolvable via MySkool Correction UI:** No (Requires Fee Adjustment).
   - **Management Approval:** **Required**.

5. **GR 7364 — Ishmal Fatima** (Family `5615`)
   - **Item ID:** `13936` | **Invoice:** `SCH-OB-2026-0000086-188` | **Period:** `Sep-2026`
   - **Currency:** `SAR` | **Original Amount:** `230.00` | **Hist. Paid:** `195.00` | **Outstanding:** `35.00`
   - **Slip Reference:** `Slip 27265` (2026-10-23)
   - **Condition:** Paid SAR 195.00 against SAR 230.00 opening balance (difference SAR 35.00). Matches sibling concession pattern.
   - **Recommended Action:** Finance review to approve SAR 35.00 concession adjustment or retain balance.
   - **Resolvable via MySkool Correction UI:** No (Requires Fee Adjustment).
   - **Management Approval:** **Required**.

6. **GR 7266 — Zainab Sami** (Family `7264`)
   - **Item ID:** `16645` | **Invoice:** `SCH-OB-2026-0000085-875` | **Period:** `Oct-2026`
   - **Currency:** `AED` | **Original Amount:** `220.00` | **Hist. Paid:** `160.00` | **Outstanding:** `60.00`
   - **Slip Reference:** `Slip 27287` (2026-10-23)
   - **Condition:** Paid AED 160.00 against AED 220.00 opening balance (difference AED 60.00). Matches sibling concession pattern.
   - **Recommended Action:** Finance review to approve AED 60.00 sibling concession adjustment or retain balance.
   - **Resolvable via MySkool Correction UI:** No (Requires Fee Adjustment).
   - **Management Approval:** **Required**.

---

### B. Currency Conflict Holds (`CURRENCY_CONFLICT_HOLD`) — 5 Items / 2 GRs / 2 Families

Cross-currency discrepancies between compiled invoice items and source payments/registrations require explicit exchange rate or redenomination fixes.

1. **GR 8620 — Ibrahim yahya** (Family `8563`) — 2 Items
   - **Item ID `19524`:** `Sep-2026` | `AED 330.00` (Hist Paid: 0.00, Outstanding: AED 330.00) | `Slip 27256`
   - **Item ID `19526`:** `Oct-2026` | `AED 330.00` (Hist Paid: 0.00, Outstanding: AED 330.00) | `Slip 27256`
   - **Problem & Evidence:** The opening-balance invoices are compiled in **AED** (AED 330.00 each), but the post-cutoff MySkool receipt on Slip 27256 (2026-10-23) was recorded as **SAR 330.00** each (total SAR 660.00). Automatic settlement was blocked by safety guards (`CURRENCY_CONFLICT_HOLD`) to prevent cross-currency distortion.
   - **Recommended Manual Action:** Admissions/Finance verify whether the family's fee currency should be SAR or AED. If SAR cash was accepted for AED obligation, either apply currency conversion or rectify the invoice denomination to SAR and execute historical settlement.
   - **Resolvable via MySkool Correction UI:** Partially (can mark Under Review or Disputed in UI; cross-currency execution requires technical script or invoice redenomination).
   - **Management Approval:** **Required** (Finance sign-off on currency treatment).

2. **GR 3230 — Amna Khan** (Family `3135`) — 3 Items
   - **Item ID `12023`:** `Jun-2025` | `PKR 5,000.00` (Hist Paid: 0.00, Outstanding: PKR 5,000.00)
   - **Item ID `12025`:** `Jul-2025` | `PKR 5,000.00` (Hist Paid: 0.00, Outstanding: PKR 5,000.00)
   - **Item ID `12027`:** `Aug-2025` | `PKR 5,000.00` (Hist Paid: 0.00, Outstanding: PKR 5,000.00)
   - **Problem & Evidence:** In `qa_staged_exceptions` (records 654, 655, 656), outstanding dues for these 3 historical months are listed in **PKR**, whereas the student's registration currency and all subsequent monthly fees (Sep-2025 through Nov-2026) are denominated in **SAR** (SAR 350.00/month).
   - **Recommended Manual Action:** Finance / Admissions review: Determine if historical PKR dues are obsolete or need conversion to SAR. Void the PKR items and re-issue in SAR if genuine dues exist, or write off obsolete foreign currency dues.
   - **Resolvable via MySkool Correction UI:** No (Requires currency redenomination / Fee adjustment).
   - **Management Approval:** **Required**.

---

### C. Duplicate Source Holds (`DUPLICATE_SOURCE_HOLD`) — 2 Items / 1 GR / 1 Family

Confirmed duplicate obligation imported during opening balance compilation.

1. **GR 6562 — Maryam Bint Ali** (Family `6562`) — 2 Items
   - **Item ID `15193`:** `Oct-2026` | `AED 200.00` | Net: AED 200.00, Paid: 0.00, Outstanding: `AED 200.00`
   - **Item ID `15194`:** `Oct-2026` | `AED 200.00` | Net: AED 200.00, Paid: 0.00, Outstanding: `AED 200.00`
   - **Problem & Evidence:** Invoice `SCH-OB-2026-0000085-645` contains two identical items for "Monthly Fee - Oct-2026", resulting in a total billed obligation of AED 400.00, whereas Maryam Bint Ali's approved monthly fee is AED 200.00.
   - **Recommended Manual Action:** Retain Item 15193 as the legitimate monthly fee obligation. Void redundant Item 15194 via Portal Fee Voiding feature (`/challan/void`) with reason "Duplicate opening-balance import" and recalculate invoice total.
   - **Resolvable via MySkool Correction UI:** No (Requires Fee Voiding feature in challan module).
   - **Management Approval:** **Required** (Finance sign-off on voiding duplicate obligation).

---

### D. Incorrectly Voided Fees (`INCORRECTLY_VOIDED_FEES`) — 2 Items / 2 GRs / 1 Family

Fees improperly voided via portal without legitimate accounting justification, confirmed safe for restoration.

1. **GR 10051 — ABDUL HADI** (Family `10051`)
   - **Item ID:** `25348` | **Invoice:** `SCH-2026-0018224` | **Period:** `Nov-2026`
   - **Currency:** `SAR` | **Original Amount:** `320.00` | **Hist. Paid:** `0.00` | **Current Outstanding:** `0.00` (Voided)
   - **Problem & Evidence:** Voided on 2026-10-27 (FeeAdjustment #7, reason: "Fee voided via portal"). Parent invoice reduced to 0. Forensics confirm student is active and obligation is genuine. Verified as `SAFE_NORMAL_RESTORATION`.
   - **Recommended Manual Action:** Restore fee item to active status via Portal Voided Fee Restoration feature (`/challan/restore-voided-fee`).
   - **Resolvable via Existing Restoration UI:** **Yes** (Native target for Voided Fee Restoration service).
   - **Management Approval:** **Required** (Admin/Finance sign-off to restore obligation).

2. **GR 10052 — FATIMAH** (Family `10051`)
   - **Item ID:** `25349` | **Invoice:** `SCH-2026-0018224` | **Period:** `Nov-2026`
   - **Currency:** `SAR` | **Original Amount:** `260.00` | **Hist. Paid:** `0.00` | **Current Outstanding:** `0.00` (Voided)
   - **Problem & Evidence:** Voided on 2026-10-27 (FeeAdjustment #8, reason: "Fee voided via portal"). Verified as `SAFE_NORMAL_RESTORATION`.
   - **Recommended Manual Action:** Restore fee item to active status via Portal Voided Fee Restoration feature (`/challan/restore-voided-fee`).
   - **Resolvable via Existing Restoration UI:** **Yes**.
   - **Management Approval:** **Required**.

---

### E. Unreconciled Post-Cutoff Payments & Pending Corrections — 5 Items / 5 GRs / 4 Families

MySkool payments received after cutoff that could not be matched automatically due to mismatched fee period descriptions, unbilled historical months, or pre-cutoff audit safeguards.

1. **GR 7611 — Humna** (Family `4087`)
   - **Item ID:** `17354` (or `17355`) | **Invoice:** `SCH-OB-2026-0000084-469` | **Target Month:** `Aug-2026`
   - **Currency:** `SAR` | **Original Amount:** `190.00` | **Hist. Paid:** `0.00` | **Outstanding:** `190.00`
   - **Collection Row ID:** `74267` | **Slip:** `Slip 27247` (2026-10-23, SAR 190.00, "Monthly Fee - Sep-2026")
   - **Problem & Evidence:** Payment of SAR 190.00 was recorded for "Sep-2026", but Humna already had Sep-2026 paid and only has active unpaid dues for Aug-2026 (Item 17354) and Oct-2026 (Item 17355). Cash received is completely unreconciled (`CASE_B_UNRECONCILED_POST_CUTOFF`).
   - **Recommended Manual Action:** In MySkool Payment Correction UI (`/challan`), select Collection Row #74267, propose reassignment to Aug-2026 (Item 17354), and execute historical settlement upon Finance sign-off.
   - **Resolvable via MySkool Correction UI:** **Yes** (`propose_reassignment`).
   - **Management Approval:** **Required**.

2. **GR 8857 — Marwa Abdul Jalil** (Family `8857`)
   - **Item ID:** `20005` | **Invoice:** `SCH-OB-2026-0000085-1494` | **Target Month:** `Oct-2026`
   - **Currency:** `AED` | **Original Amount:** `344.00` | **Hist. Paid:** `0.00` | **Outstanding:** `344.00`
   - **Collection Row ID:** `71102` | **Slip:** `Slip 25192` (2026-09-21, AED 344.00, "Monthly Fee - Sep-2026")
   - **Problem & Evidence:** Correction record `MSCORR-71102-S1-0cc008c7` proposed reassignment to Oct-2026 Item 20005. Because receipt date was pre-cutoff (2026-09-21), financial ledger balance mutation was blocked (`BLOCKED_PRE_CUTOFF_SNAPSHOT`) until Finance confirms whether this payment was already credited into opening balances.
   - **Recommended Manual Action:** Finance check physical/legacy MySkool ledger. If uncredited, execute approved pre-cutoff compensation adjustment via correction UI.
   - **Resolvable via MySkool Correction UI:** **Yes** (Workflow already logged; awaiting Finance confirmation).
   - **Management Approval:** **Required**.

3. **GR 10111 — Mahnoor Israr** (Family `4985`)
   - **Item ID:** `11740` | **Invoice:** `SCH-OB-2026-0000085-70` | **Target Month:** `Oct-2026`
   - **Currency:** `SAR` | **Original Amount:** `370.00` | **Hist. Paid:** `0.00` | **Outstanding:** `370.00`
   - **Collection Row ID:** `74277` | **Slip:** `Slip 27252` (2026-10-23, SAR 100.00, "Admission Fee - Sep-2026")
   - **Problem & Evidence:** Post-cutoff receipt of SAR 100.00 for "Admission Fee - Sep-2026". No Admission Fee OB item was compiled; student has an unpaid Oct-2026 monthly fee (SAR 370.00). Cash is unreconciled.
   - **Recommended Manual Action:** Finance review source voucher. If SAR 100.00 was a partial installment towards monthly fee, propose reassignment in correction UI; if genuine admission fee, bill admission fee and settle.
   - **Resolvable via MySkool Correction UI:** **Yes** (`propose_reassignment` / `mark_under_review`).
   - **Management Approval:** **Required**.

4. **GR 10282 — Muhammed abubaker ghulam rasool** & **GR 10283 — Hajra Abubaker Ghulam Rasool** (Family `5506`) — 2 Items
   - **Items:** `11869` & `11870` | **Invoice:** `SCH-OB-2026-0000085-142` | **Fee Month:** `Sep-2026`
   - **Currency:** `SAR` | **Original Amount:** `200.00` each | **Hist. Paid:** `200.00` each (settled on Oct fee) | **Outstanding:** `0.00`
   - **Collection Row IDs:** `74421` & `74422` | **Slip:** `Slip 27324` (2026-10-24, SAR 90.00 each)
   - **Problem & Evidence:** Post-cutoff collection rows state "Monthly Fee - Sep-2026" (SAR 90.00 each). Both students' Oct-2026 opening balance fees were already settled, leaving these two post-cutoff collections unallocated.
   - **Recommended Manual Action:** Finance investigate whether SAR 90.00 each was intended as advance towards Nov-2026 monthly fees. Reassign in MySkool correction UI to Nov-2026 invoices.
   - **Resolvable via MySkool Correction UI:** **Yes** (`propose_reassignment`).
   - **Management Approval:** **Required**.

---

## 3. Verified Normal & Excluded Cohorts (Instruction 5)

Per Instruction 5, all correctly settled payments and genuine unpaid balances have been excluded from the correction queue:

1. **246 Full Historical Settlements (`POST_CUTOFF_FULL_SETTLEMENT`):**  
   All 246 invoice items where post-cutoff collections matched the exact opening balance amount were settled with 100% mathematical accuracy (`paid_minor` equals `net_minor`, status set to `paid`). All parent invoice totals and balances were updated cleanly.
2. **211 Genuine Pre-Cutoff Residuals (`PRE_CUTOFF_RESIDUAL_BALANCE`):**  
   All 211 items with historical payments on or before October 22, 2026 represent authentic unpaid residuals compiled into opening balances. These are genuine receivables owed to IQRA School and correctly remain in active collection.
3. **13 Correctly Settled Partial Payments (`POST_CUTOFF_PARTIAL_PAYMENT`):**  
   Students GR 9258, 6191, 6495, 6502, 6555, 6769, 6928, 6938, 6964, 7021, 7050, 8649, and 9511 made authentic partial payments against their monthly fees. Settlements were accurately applied to the extent paid, and the remaining balances are genuine unpaid dues with no sibling concession pattern.

---

## 4. Operational Action Plan for Finance and Billing Operations

```
┌────────────────────────────────────────────────────────────────────────────────────────┐
│                               OPERATIONAL ACTION PLAN                                  │
├───────────────────────────────────┬──────────────────────────────────┬─────────────────┤
│ ACTION STEP                       │ TARGET GRs / ITEMS               │ RESPONSIBILITY  │
├───────────────────────────────────┼──────────────────────────────────┼─────────────────┤
│ 1. Void Duplicate OB Item         │ GR 6562 (Item 15194)             │ Finance Admin   │
│ 2. Restore Erroneously Voided Fees│ GR 10051 & 10052 (Items 25348/49)│ Finance Admin   │
│ 3. Sign-off Sibling Concessions   │ GR 5930, 5130, 5168, 5502,       │ Finance Head    │
│                                   │ 7364, 7266                       │                 │
│ 4. Reassign Unreconciled Slips    │ GR 7611, 8857, 10111, 10282,10283│ Billing Operator│
│ 5. Technical Currency Correction  │ GR 8620 & GR 3230                │ Tech Lead & Fin │
└───────────────────────────────────┴──────────────────────────────────┴─────────────────┘
```

1. **Immediate Zero-Risk Actions:**
   - Restore voided items `25348` and `25349` for family `10051` via `/challan/restore-voided-fee`.
   - Void duplicate item `15194` for GR `6562` via `/challan/void`.
2. **Concession Approval Session:**
   - Present the 6 `CONCESSION_REVIEW` cases (including GR `5930` for QAR 50) to the Finance Committee. For each approved case, post an official `FeeAdjustment` (kind: `concession`).
3. **Correction UI Processing:**
   - Open `/challan` and use `propose_reassignment` on Slips `27247` (GR 7611), `25192` (GR 8857), `27252` (GR 10111), and `27324` (GR 10282/10283).
4. **Currency Resolution:**
   - Confirm official conversion rate or currency denomination for GR `8620` (SAR vs AED) and GR `3230` (PKR vs SAR), and execute approved adjustments.

---

*Report prepared strictly from local forensic evidence.*
