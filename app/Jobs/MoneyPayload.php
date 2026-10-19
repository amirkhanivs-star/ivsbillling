<?php
namespace App\Jobs;
final class MoneyPayload {public static function money(int $value):string {return number_format($value/100,2,'.','');}}
