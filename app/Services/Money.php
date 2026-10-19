<?php
namespace App\Services;
use InvalidArgumentException;
final class Money {
 public static function minor(string|int|float $value):int {
  $s=trim((string)$value);
  if (!preg_match('/^\d{1,13}(?:\.\d{1,2})?$/D',$s)) throw new InvalidArgumentException('Amount must be a nonnegative number with at most two decimals.');
  [$whole,$fraction]=array_pad(explode('.',$s,2),2,'');
  return ((int)$whole)*100+(int)str_pad($fraction,2,'0');
 }
 public static function format(int $minor):string {return number_format($minor/100,2,'.',',');}
 public static function discount(int $amount,?string $kind,int $value):int {
  if ($kind==='fixed') return min($amount,$value);
  if ($kind==='percent') return min($amount,intdiv($amount*$value+5000,10000)); // percentage in basis points
  return 0;
 }
}
