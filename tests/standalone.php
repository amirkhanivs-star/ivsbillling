<?php
/** No Composer needed: regression checks of source JS-equivalent legacy calculations. */
require __DIR__.'/../app/Services/Money.php';
require __DIR__.'/../app/Services/LegacyBillingCalculator.php';
use App\Services\Money;
use App\Services\LegacyBillingCalculator as C;
function eq(mixed $actual,mixed $expected,string $label):void{if($actual!==$expected)throw new RuntimeException("FAIL {$label}: ".var_export($actual,true).' vs '.var_export($expected,true));echo "PASS {$label}\n";}
eq(Money::minor('300.50'),30050,'money decimal conversion');eq(Money::minor('300'),30000,'money integer conversion');eq(Money::discount(10000,'fixed',3500),3500,'fixed discount');eq(Money::discount(10000,'percent',2500),2500,'percentage discount');
$bill=['january'=>['status'=>'No payment'],'february'=>['status'=>'Partial payment','amount'=>'150'],'march'=>['status'=>'Full payment','amount'=>'300'],'april'=>['status'=>'Not admitted'],'may'=>['status'=>'Paid with Discount','amount'=>'200','discountActive'=>true,'discountFinalPayable'=>'200']];
$r=C::calculate(300,$bill,[],'may');eq((int)$r['expected'],1200,'legacy expected for billed months');eq((int)$r['paid'],650,'legacy received');eq((int)$r['pending'],450,'legacy unpaid with full discount month');eq((int)$r['discount'],100,'legacy paid with discount');eq((int)$r['perMonth']['june']['expected'],0,'blank months do not become dues');
$feeChange=C::calculate(100,['january'=>['status'=>'No payment'],'february'=>['status'=>'No payment'],'march'=>['status'=>'No payment']],[['fee'=>100,'effectiveMonthKey'=>'january'],['fee'=>180,'effectiveMonthKey'=>'march']],'march');eq((int)$feeChange['expected'],380,'fee history effective month');
eq(C::calculate(300,['january'=>'Partial payment | 100'])['perMonth']['january']['paid'],100.,'legacy string billing status');
echo "All standalone regression checks passed.\n";
require __DIR__.'/../app/Services/BillingPeriod.php';
use App\Services\BillingPeriod;
$periods=BillingPeriod::selectedMonths(2028,[12,2,1],10);
eq($periods[0]['start'],'2028-01-01','billing months sorted chronologically');
eq($periods[1]['end'],'2028-02-29','February leap-year month end');
eq($periods[2]['due'],'2028-12-10','month-specific due date');
try { BillingPeriod::selectedMonths(2028,[1,1],10); throw new RuntimeException('Duplicate month accepted'); }
catch (InvalidArgumentException $exception) {echo "PASS duplicate-month rejection\n";}
echo "All standalone period checks passed.\n";
