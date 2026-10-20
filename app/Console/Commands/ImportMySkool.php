<?php
namespace App\Console\Commands;
use App\Services\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use RuntimeException;
class ImportMySkool extends Command {
 protected $signature='iqra:import-myskool {file : Path to exported XLSX} {--campus=main} {--department=} {--currency= : REQUIRED, e.g. PKR/SAR/AED} {--commit : Without this, dry-run only}';
 protected $description='Import student/family numbers and monthly fee plans from Total data of Myskool sheet';
 public function handle():int {
  $this->error('UNSAFE DIRECT IMPORT BLOCKED: Direct MySkool Excel imports bypass campus, department, currency, and identity verification.');
  $this->line('Use the approved staging migration pipeline (php artisan qa:import-approved-students) which imports verified staging data.');
  return self::FAILURE;
  $path=$this->argument('file');if(!is_file($path)){$this->error('File not found.');return self::FAILURE;}
  $currency=strtoupper(trim((string)$this->option('currency')));if(!preg_match('/^[A-Z]{3}$/',$currency)){$this->error('Specify --currency=PKR, --currency=SAR etc. Student export does not contain currency.');return self::FAILURE;}
  if(!$this->option('department')){$this->error('Specify --department=school|quran|tuition. The workbook has no department column.');return self::FAILURE;}
  $campus=DB::table('campuses')->where('code',$this->option('campus'))->first();$dept=DB::table('departments')->where('code',$this->option('department'))->first();if(!$campus||!$dept){$this->error('Invalid campus or department. Seed the database first.');return self::FAILURE;}
  $reader=IOFactory::createReaderForFile($path);if(method_exists($reader,'setLoadSheetsOnly'))$reader->setLoadSheetsOnly(['Total data of Myskool']);$reader->setReadDataOnly(true);
  $book=$reader->load($path);$sheet=$book->getSheetByName('Total data of Myskool');if(!$sheet){$this->error('Missing Total data of Myskool worksheet.');return self::FAILURE;}
  $header=$sheet->rangeToArray('A3:U3',null,true,true,false)[0];
  $expected=['ID','GR No','Family Code','Student Name','Gender','Date of Birth','Religion','Contact (Father)','Contact (Mother)','Address','Date of Admission','Class','Class Admitted','Monthly Fee','Status'];
  foreach($expected as $index=>$column)if(trim((string)($header[$index]??''))!==$column){$this->error("Unexpected header column ".($index+1).": expected {$column}");return self::FAILURE;}
  $stats=['students'=>0,'missing_family_codes'=>0,'missing_contacts'=>0,'invalid_rows'=>0,'skipped'=>0];$rows=[];
  for($r=4;$r<=$sheet->getHighestRow();$r++){
   $data=$sheet->rangeToArray("A{$r}:U{$r}",null,true,true,false)[0];$gr=trim((string)($data[1]??''));$name=trim((string)($data[3]??''));
   if(!$gr&&!$name)continue;if(!$gr||!$name){$stats['invalid_rows']++;continue;}
   $familyCode=trim((string)($data[2]??''));if(!$familyCode){$familyCode='UNASSIGNED-'.$gr;$stats['missing_family_codes']++;}
   if(empty($data[7])&&empty($data[8]))$stats['missing_contacts']++;
   $rows[]=['legacy_id'=>trim((string)($data[0]??'')),'gr'=>$gr,'family_code'=>$familyCode,'provisional'=>str_starts_with($familyCode,'UNASSIGNED-'),'name'=>$name,'gender'=>trim((string)($data[4]??'')),'guardian'=>trim((string)($data[15]??'')),'class'=>trim((string)($data[11]??'')),'fee'=>is_numeric($data[13]??null)?(string)$data[13]:'0','status'=>strtolower(trim((string)($data[14]??'present'))),'admission_date'=>$this->date($data[10]??null)];$stats['students']++;
  }
  $this->table(['Metric','Value'],array_map(fn($k,$v)=>[$k,$v],array_keys($stats),array_values($stats)));
  if(!$this->option('commit')){$this->warn('Dry-run only. IMPORTANT: every valid row will be assigned the ONE chosen department and currency. Split the workbook into accurately classified exports first, then run --commit.');return self::SUCCESS;}
  $hash=hash('sha256',hash_file('sha256',$path).'|'.$this->option('campus').'|'.$this->option('department').'|'.$currency);$existing=DB::table('import_batches')->where('source_sha256',$hash)->first();if($existing){$this->error('This exact workbook has already been imported (batch '.$existing->id.').');return self::FAILURE;}
  $monthly=DB::table('fee_types')->whereNull('department_id')->where('code','monthly')->first();if(!$monthly)throw new RuntimeException('Run php artisan db:seed first.');
  foreach(array_chunk($rows,250) as $chunk){DB::transaction(function()use($chunk,$dept,$campus,$currency,$monthly){foreach($chunk as $row){
   $now=now();$family=DB::table('families')->where('family_code',$row['family_code'])->first();if(!$family){$familyId=DB::table('families')->insertGetId(['family_code'=>$row['family_code'],'guardian_name'=>$row['guardian']?:null,'is_provisional'=>$row['provisional'],'created_at'=>$now,'updated_at'=>$now]);}else $familyId=$family->id;
   $existingStudent=DB::table('students')->where('gr_number',$row['gr'])->first();if($existingStudent){$studentId=$existingStudent->id;DB::table('students')->where('id',$studentId)->update(['name'=>$row['name'],'family_id'=>$familyId,'updated_at'=>$now]);}else{$studentId=DB::table('students')->insertGetId(['family_id'=>$familyId,'gr_number'=>$row['gr'],'legacy_myskool_id'=>$row['legacy_id']?:null,'name'=>$row['name'],'gender'=>$row['gender']?:null,'date_of_admission'=>$row['admission_date'],'status'=>$row['status']?:'present','created_at'=>$now,'updated_at'=>$now]);}
   $programName=$row['class']?:'Unassigned';$program=DB::table('programs')->where(['department_id'=>$dept->id,'name'=>$programName])->first();if(!$program){$programId=DB::table('programs')->insertGetId(['department_id'=>$dept->id,'name'=>$programName,'created_at'=>$now,'updated_at'=>$now]);}else{$programId=$program->id;}
   $enrollment=DB::table('enrollments')->where(['student_id'=>$studentId,'campus_id'=>$campus->id,'department_id'=>$dept->id,'program_id'=>$programId,'currency'=>$currency])->first();
   $amount=Money::minor($row['fee']);
   if(!$enrollment){$enrollmentId=DB::table('enrollments')->insertGetId(['student_id'=>$studentId,'campus_id'=>$campus->id,'department_id'=>$dept->id,'program_id'=>$programId,'grade_label'=>$programName,'monthly_fee_minor'=>$amount,'currency'=>$currency,'started_on'=>$row['admission_date'],'created_at'=>$now,'updated_at'=>$now]);}else $enrollmentId=$enrollment->id;
   if($amount>0 && !DB::table('fee_plan_items')->where(['enrollment_id'=>$enrollmentId,'fee_type_id'=>$monthly->id])->exists())DB::table('fee_plan_items')->insert(['enrollment_id'=>$enrollmentId,'fee_type_id'=>$monthly->id,'description'=>'Monthly Fee','currency'=>$currency,'amount_minor'=>$amount,'frequency'=>'monthly','created_at'=>$now,'updated_at'=>$now]);
  }});}
  DB::table('import_batches')->insert(['source_name'=>basename($path),'source_sha256'=>$hash,'imported'=>$stats['students'],'errors'=>$stats['invalid_rows'],'details'=>json_encode($stats),'created_at'=>now(),'updated_at'=>now()]);
  $this->info('Import committed. Contacts/currencies/department allocations and historic balances still require reconciliation.');return self::SUCCESS;
 }
 private function date(mixed $v):?string {if(!$v)return null;if(is_numeric($v)){try{return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$v)->format('Y-m-d');}catch(\Throwable $e){return null;}}foreach(['d/m/Y','d-m-Y','Y-m-d'] as $format){$d=\DateTimeImmutable::createFromFormat('!'.$format,trim((string)$v));if($d&&$d->format($format)===trim((string)$v))return $d->format('Y-m-d');}return null;}
}
