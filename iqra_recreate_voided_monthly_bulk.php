<?php
/**
 * IQRA bulk recreation of technically eligible, previously voided MONTHLY fees.
 * No backup prerequisite. Uses the application's audited VoidedFeeRestorationService.
 *
 * Preview: php iqra_recreate_voided_monthly_bulk.php --preview
 * Apply:   php iqra_recreate_voided_monthly_bulk.php --apply --actor-id=ADMIN_ID_OR_EMAIL --confirm-bulk
 * Narrow:  php iqra_recreate_voided_monthly_bulk.php --preview --gr=10221
 *
 * This is NOT a direct status update. It creates a new active obligation using approved
 * current settings, retaining the voided original. Historical-payment conflicts and
 * duplicate obligations are skipped. Other fee types are not bulk-modified.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }
$root = __DIR__;
if (!is_file("{$root}/bootstrap/app.php") || !is_file("{$root}/vendor/autoload.php")) {
    fwrite(STDERR, "Place the script in the Laravel project root next to artisan.\n"); exit(1);
}
require "{$root}/vendor/autoload.php";
$app = require "{$root}/bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\VoidedFeeRestorationService;
use Illuminate\Support\Facades\DB;

$opts = getopt('', ['preview', 'apply', 'gr::', 'actor-id::', 'confirm-bulk', 'help']);
if (isset($opts['help'])) {
    echo "PREVIEW: php iqra_recreate_voided_monthly_bulk.php --preview [--gr=10221]\n";
    echo "APPLY:   php iqra_recreate_voided_monthly_bulk.php --apply --actor-id=ADMIN_ID_OR_EMAIL --confirm-bulk [--gr=10221]\n";
    exit(0);
}
$preview = isset($opts['preview']);
$apply = isset($opts['apply']);
if ($preview === $apply) {
    fwrite(STDERR, "Provide exactly one of --preview or --apply.\n"); exit(1);
}
$actorId = null;
if ($apply) {
    if (!isset($opts['confirm-bulk']) || !isset($opts['actor-id']) || trim((string)$opts['actor-id']) === '') {
        fwrite(STDERR, "Apply requires --actor-id=ADMIN_ID_OR_EMAIL and --confirm-bulk. No changes made.\n"); exit(1);
    }
    $actor = trim((string)$opts['actor-id']);
    $admin = DB::table('users')->where(is_numeric($actor) ? 'id' : 'email', is_numeric($actor) ? (int)$actor : $actor)->first();
    if (!$admin || !in_array((string)($admin->role ?? ''), ['super_admin','finance_admin','admin'], true)
        || (property_exists($admin, 'is_active') && !$admin->is_active)) {
        fwrite(STDERR, "Active authorized finance administrator not found. No changes made.\n"); exit(1);
    }
    $actorId = (int)$admin->id;
}

$grs = array_values(array_filter(array_map('trim', explode(',', trim((string)($opts['gr'] ?? ''))))));
$dir = storage_path('app/reconciliation');
if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
    fwrite(STDERR, "Could not create report directory. No changes made.\n"); exit(1);
}
$stamp = date('Ymd_His') . '_' . getmypid();
$report = "{$dir}/voided_monthly_" . ($apply ? 'apply' : 'preview') . "_{$stamp}.csv";
$fh = fopen($report, 'xb');
if ($fh === false) {
    fwrite(STDERR, "Could not create report. No changes made.\n"); exit(1);
}
fputcsv($fh, [
    'original_item_id','gr','student','fee_type','period','old_currency','old_net_minor',
    'approved_currency','approved_net_minor','decision','replacement_item_id','invoice_number','details',
]);
fflush($fh);

$svc = app(VoidedFeeRestorationService::class);
$counts = ['scanned'=>0,'eligible'=>0,'recreated'=>0,'blocked'=>0,'superseded'=>0,'errors'=>0];
$totals = [];
$reason = 'Authorized bulk recreation of previously voided monthly fees using approved current student settings';

// Only monthly/tuition fees. The underlying recreation service creates MONTHLY billing runs.
// Using it for a voided one-time registration/admission/book charge could misclassify that fee.
$q = DB::table('invoice_items as ii')
    ->join('fee_types as ft', 'ft.id', '=', 'ii.fee_type_id')
    ->whereIn('ii.status', ['void','voided'])
    ->whereIn('ft.code', ['monthly','tuition']);
if ($grs) {
    $q->join('students as s', 's.id', '=', 'ii.student_id')->whereIn('s.gr_number', $grs);
}
$q->select('ii.id as id')->orderBy('ii.id');

try {
    $q->chunkById(100, function ($rows) use ($svc, $fh, $apply, $actorId, $reason, &$counts, &$totals) {
        foreach ($rows as $candidate) {
            $id = (int)$candidate->id;
            ++$counts['scanned'];
            $gr = ''; $name = ''; $ft = ''; $period = '';
            $oldCurrency = ''; $oldNet = ''; $newCurrency = ''; $newNet = '';
            $decision = ''; $replacementId = ''; $invoiceNumber = ''; $detail = '';
            try {
                $inspection = $svc->inspect($id);
                $item = $inspection['item'] ?? null;
                if (!$item) {
                    ++$counts['blocked'];
                    $decision = 'BLOCKED';
                    $detail = (string)($inspection['recreate_block_reason'] ?? $inspection['error'] ?? 'Item could not be inspected');
                } else {
                    $gr = (string)($item->gr_number ?? '');
                    $name = (string)($item->student_name ?? '');
                    $ft = (string)($item->fee_type_name ?? '');
                    $period = (string)($item->period_key ?: $item->period_start);
                    $oldCurrency = (string)$item->currency;
                    $oldNet = (string)($inspection['original_approved_values']['net_minor'] ?? $item->net_minor);
                    $newSettings = $inspection['current_approved_settings'] ?? [];
                    $newCurrency = (string)($newSettings['currency'] ?? '');
                    $newNet = isset($newSettings['net_minor']) ? (string)$newSettings['net_minor'] : '';

                    // For multiple voids of the same obligation, only the most recent can be recreated.
                    $hasNewerVoided = DB::table('invoice_items')
                        ->where('student_id', $item->student_id)
                        ->where('fee_type_id', $item->fee_type_id)
                        ->where('period_start', $item->period_start)
                        ->whereIn('status', ['void','voided'])
                        ->where('id', '>', $id)
                        ->exists();
                    if ($hasNewerVoided) {
                        ++$counts['superseded'];
                        $decision = 'SKIPPED_SUPERSEDED';
                        $detail = 'A newer voided version exists for the same student, fee and period.';
                    } elseif (empty($inspection['is_safe_to_recreate_current'])) {
                        ++$counts['blocked'];
                        $decision = 'BLOCKED_BY_APPLICATION';
                        $detail = (string)($inspection['recreate_block_reason'] ?? $inspection['error'] ?? 'Application safety check rejected recreation');
                    } elseif (!$apply) {
                        ++$counts['eligible'];
                        $decision = 'ELIGIBLE_PREVIEW';
                        $detail = 'Technically eligible for recreation using current settings. No changes made.';
                    } else {
                        // The service INSPECTS AGAIN under a database lock inside a transaction;
                        // it prevents active duplicates, intervening payments and historical conflicts.
                        $result = $svc->recreateWithCurrentSettings(
                            $id, ['reason'=>$reason,'actor_id'=>$actorId], $actorId
                        );
                        ++$counts['eligible'];
                        ++$counts['recreated'];
                        $decision = 'RECREATED';
                        $replacementId = (string)($result['replacement_invoice_item_id'] ?? '');
                        $invoiceNumber = (string)($result['invoice_number'] ?? '');
                        $detail = (string)($result['message'] ?? 'Recreated');
                        $cur = (string)($result['currency'] ?? $newCurrency);
                        $totals[$cur] = ($totals[$cur] ?? 0) + (int)($result['net_minor'] ?? 0);
                    }
                }
            } catch (Throwable $e) {
                ++$counts['errors'];
                $decision = 'ERROR';
                $detail = substr($e->getMessage(), 0, 1500);
                fwrite(STDERR, "Item #{$id}: {$detail}\n");
            }
            fputcsv($fh, [$id,$gr,$name,$ft,$period,$oldCurrency,$oldNet,$newCurrency,$newNet,
                $decision,$replacementId,$invoiceNumber,$detail]);
            fflush($fh);
        }
        echo "Processed {$counts['scanned']} previously voided monthly items...\n";
    }, 'ii.id', 'id');
} finally {
    fclose($fh);
}

echo "\n" . ($apply ? 'BULK RECREATION COMPLETE' : 'READ-ONLY PREVIEW COMPLETE') . "\n";
echo 'Database: ' . DB::connection()->getDatabaseName() . "\n";
foreach ($counts as $label=>$value) { echo "{$label}: {$value}\n"; }
if ($apply) {
    foreach ($totals as $currency=>$minor) {
        echo 'Created face-value obligations: ' . $currency . ' ' . number_format($minor/100, 2) . " (before any automatic prepayment allocation)\n";
    }
}
echo "CSV report: {$report}\n";
echo "Any BLOCKED or ERROR rows remain untouched; do not force them to active status.\n";
exit($counts['errors'] > 0 ? 2 : 0);
