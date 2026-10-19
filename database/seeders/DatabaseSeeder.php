<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
class DatabaseSeeder extends Seeder {
 public function run():void {
  $now=now();
  DB::table('campuses')->updateOrInsert(['code'=>'main'],['name'=>'Main Campus','updated_at'=>$now,'created_at'=>$now]);
  foreach(['school'=>'School','quran'=>'Quran','tuition'=>'Tuition'] as $code=>$name){DB::table('departments')->updateOrInsert(['code'=>$code],['name'=>$name,'updated_at'=>$now,'created_at'=>$now]);}
  foreach(['monthly'=>'Monthly Fee','registration'=>'Registration Fee','admission'=>'Admission Fee','books'=>'Books Charges','slc_charges'=>'SLC Charges','exam'=>'Exam Fee','re_exam'=>'Re-Exam','custom'=>'Custom Charge'] as $code=>$name){DB::table('fee_types')->updateOrInsert(['department_id'=>null,'code'=>$code],['name'=>$name,'updated_at'=>$now,'created_at'=>$now]);}
 }
}
