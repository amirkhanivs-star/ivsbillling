-- ==============================================================================
-- IQRA VIRTUAL SCHOOL — OPTIONAL MANUAL TEST RECORDS REMOVAL SCRIPT
-- ==============================================================================
-- WARNING: DO NOT RUN THIS AUTOMATICALLY.
-- This script is prepared ONLY for an explicit user decision if the school
-- administration elects to remove the post-posting manual test transactions
-- before opening the portal to parents.
--
-- TARGET DATABASE: iqra_billing
-- AUDIT REFERENCE: Phase 8 & 8.1 manual verification test records
-- ==============================================================================

START TRANSACTION;

-- 1. Identify and delete payment allocations for test payments (RCP-2026-0000001, RCP-2026-0000002)
DELETE FROM `payment_allocations` WHERE `payment_id` IN (1, 2);

-- 2. Delete test payments
DELETE FROM `payments` WHERE `id` IN (1, 2);

-- 3. Delete manual test invoice items
DELETE FROM `invoice_items` WHERE `id` IN (11352, 11353, 11354, 11355, 11356);

-- 4. Delete manual test invoices
DELETE FROM `invoices` WHERE `id` IN (8099, 8100, 8103);

-- 5. Delete manual test billing runs
DELETE FROM `billing_runs` WHERE `id` IN (58, 59, 60);

-- 6. (Optional) Remove manual student created during testing (GR: 01, test)
-- Note: Student 4307 (GR: 0000001) is an official workbook import and is PRESERVED.
DELETE FROM `fee_plan_items` WHERE `enrollment_id` = 4359;
DELETE FROM `student_registration_details` WHERE `student_id` = 4359;
DELETE FROM `enrollments` WHERE `student_id` = 4359;
DELETE FROM `students` WHERE `id` = 4359 AND `gr_number` = '01';

-- Verification counts after optional cleanup:
-- Expected invoices: 8,098 (100% opening balance)
-- Expected items: 11,351 (100% opening balance)
-- Expected payments: 0
-- Expected students: 4,358 (100% approved MySkool import)

COMMIT;
