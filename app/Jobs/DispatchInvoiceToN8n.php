<?php
namespace App\Jobs;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use RuntimeException;
class DispatchInvoiceToN8n implements ShouldQueue {
 use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;
 public int $tries=3;public int $timeout=45;public array $backoff=[60,300,900];
 public function __construct(public int $notificationId){}
 public function handle():void {
  $notification=DB::table('notifications')->where('id',$this->notificationId)->first();
  if(!$notification||in_array($notification->status,['submitted','delivered','read'],true))return;
  if(config('services.notifications_disabled',false)||\App\Services\Staging\DatabaseSafetyGuard::isQaDatabase()){
   DB::table('notifications')->where('id',$notification->id)->update(['status'=>'qa_suppressed','error'=>'QA Portal: real notifications and external webhooks are disabled.','updated_at'=>now()]);
   return;
  }
  $invoice=DB::table('invoices')->where('id',$notification->invoice_id)->first();
  $family=$invoice?DB::table('families')->where('id',$invoice->family_id)->first():null;
  if(!$invoice||!$family)throw new RuntimeException('Missing invoice or family.');
  $recipient=$notification->channel==='whatsapp'?$family->whatsapp:$family->email;
  if(!$recipient||!$family->contact_verified||($notification->channel==='whatsapp'&&!$family->messaging_opt_in)){
   DB::table('notifications')->where('id',$notification->id)->update(['status'=>'blocked','error'=>'Contact missing/unverified or WhatsApp permission absent','updated_at'=>now()]);return;
  }
  $url=config('services.n8n.url');$secret=config('services.n8n.secret');
  if(!$url||!$secret)throw new RuntimeException('n8n URL/signing secret not configured');
  $payload=['event'=>'invoice.send','notification_id'=>$notification->id,'invoice_id'=>$invoice->id,'invoice_number'=>$invoice->invoice_number,'channel'=>$notification->channel,'department_id'=>$invoice->department_id,'family_code'=>$family->family_code,'currency'=>$invoice->currency,'amount_due'=>MoneyPayload::money($invoice->total_minor-$invoice->paid_minor),'recipient'=>$recipient,'pdf_url'=>URL::temporarySignedRoute('invoices.signed_pdf',now()->addHours(24),['invoice'=>$invoice->id]),'dedupe_key'=>'invoice-'.$notification->id];
  $body=json_encode($payload,JSON_THROW_ON_ERROR);
  DB::table('notifications')->where('id',$notification->id)->update(['attempts'=>DB::raw('attempts+1'),'updated_at'=>now()]);
  try{
   $response=Http::timeout(25)->withHeaders(['Content-Type'=>'application/json','X-IQRA-Signature'=>hash_hmac('sha256',$body,$secret)])->withBody($body,'application/json')->post($url);
   if(!$response->successful())throw new RuntimeException('Webhook HTTP '.$response->status());
   DB::table('notifications')->where('id',$notification->id)->whereNotIn('status',['delivered','read'])->update(['status'=>'submitted','provider_message_id'=>$response->json('message_id'),'error'=>null,'submitted_at'=>now(),'updated_at'=>now()]);
  }catch(\Throwable $e){DB::table('notifications')->where('id',$notification->id)->update(['status'=>'failed','error'=>substr($e->getMessage(),0,1000),'updated_at'=>now()]);throw $e;}
 }
}
