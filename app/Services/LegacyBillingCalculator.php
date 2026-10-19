<?php
namespace App\Services;
/** PHP port of utils/calcPendingDues.js for legacy 12-month admissions billing. */
final class LegacyBillingCalculator {
 public const MONTHS=['january','february','march','april','may','june','july','august','september','october','november','december'];
 private static function amount(mixed $raw):float { preg_match('/-?\d+(?:\.\d+)?/',(string)$raw,$m);return (float)($m[0]??0); }
 public static function calculate(mixed $baseFee,mixed $billing, mixed $feeHistory=[],?string $currentMonth=null):array {
  if (is_string($billing))$billing=json_decode($billing,true)?:[];
  if (is_string($feeHistory))$feeHistory=json_decode($feeHistory,true)?:[];
  $base=max(0,self::amount($baseFee));$history=[];
  foreach ((array)$feeHistory as $entry) {if(!is_array($entry))continue;$key=strtolower((string)($entry['effectiveMonthKey']??''));$idx=array_search($key,self::MONTHS,true);$fee=self::amount($entry['fee']??0);if($idx!==false&&$fee>0)$history[]=['idx'=>$idx,'fee'=>$fee,'changedAt'=>(string)($entry['changedAt']??'')];}
  if(!$history&&$base>0)$history[]=['idx'=>0,'fee'=>$base,'changedAt'=>''];
  usort($history,fn($a,$b)=>[$a['idx'],$a['changedAt']]<=>[$b['idx'],$b['changedAt']]);
  $totals=['expected'=>0.,'netExpected'=>0.,'paid'=>0.,'pending'=>0.,'discount'=>0.];$perMonth=[];
  foreach(self::MONTHS as $i=>$key){
   $raw=$billing[$key]??null;$entry=is_array($raw)?$raw:[];
   if(!is_array($raw)&&is_string($raw)){
    foreach(['Not admitted','No payment','Partial payment','Full payment','Paid with Discount'] as $status){if($raw===$status||str_starts_with($raw,$status.' | ')||str_starts_with($raw,$status.' - ')){$entry=['status'=>$status,'amount'=>self::amount(substr($raw,strlen($status)))];break;}}
   }
   $status=trim((string)($entry['status']??''));$amount=max(0,self::amount($entry['amount']??0));$override=max(0,self::amount($entry['feeOverride']??0));
   $active=in_array(strtolower((string)($entry['discountActive']??$entry['discount_active']??'false')),['1','true'],true)||strtolower($status)==='paid with discount';
   $fee=$base;foreach($history as $h){if($h['idx']<=$i)$fee=$h['fee'];}if($override>0)$fee=$override;
   if(!$status&&$amount<=0&&$override<=0&&!$active){$perMonth[$key]=['status'=>'','fee'=>$fee,'expected'=>0,'netExpected'=>0,'paid'=>0,'pending'=>0,'discount'=>0];continue;}
   if(strtolower($status)==='not admitted'){$perMonth[$key]=['status'=>'Not admitted','fee'=>0,'expected'=>0,'netExpected'=>0,'paid'=>0,'pending'=>0,'discount'=>0];continue;}
   $final=$fee;
   $discountFinal=$entry['discountFinalPayable']??$entry['discount_final_payable']??null;
   $discountAmount=self::amount($entry['discountAmount']??$entry['discount_amount']??0);
   if($active){if($discountFinal!==null&&trim((string)$discountFinal)!=='')$final=min($fee,max(0,self::amount($discountFinal)));elseif($discountAmount>0)$final=max(0,$fee-$discountAmount);elseif(strtolower($status)==='paid with discount')$final=min($fee,$amount);}
   $discount=$active?max(0,$fee-$final):0;
   $paid=in_array(strtolower($status),['partial payment','full payment','paid with discount'],true)||(!$status&&$amount>0)?$amount:0;
   $pending=$active?0:max($fee-$paid,0);
   $perMonth[$key]=['status'=>$active?'Paid with Discount':$status,'fee'=>$fee,'expected'=>$fee,'netExpected'=>$final,'paid'=>$paid,'pending'=>$pending,'discount'=>$discount];
   foreach(['expected'=>$fee,'netExpected'=>$final,'paid'=>$paid,'pending'=>$pending,'discount'=>$discount] as $n=>$v)$totals[$n]+=$v;
  }
  $currentMonth=strtolower((string)($currentMonth?:date('F')));$currentFee=$perMonth[$currentMonth]['fee']??$base;
  return array_merge($totals,['currentFee'=>$currentFee,'perMonth'=>$perMonth]);
 }
}
