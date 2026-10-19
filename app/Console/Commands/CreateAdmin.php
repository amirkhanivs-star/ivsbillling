<?php
namespace App\Console\Commands;
use App\Models\User;
use Illuminate\Console\Command;
class CreateAdmin extends Command {
 protected $signature='iqra:create-admin';
 protected $description='Securely create or update a finance super administrator';
 public function handle():int {
  $name=$this->ask('Admin name');$email=$this->ask('Admin email');$password=$this->secret('New password (min 12 characters)');
  if (!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen((string)$password)<12){$this->error('Valid email and a 12+ character password required.');return self::FAILURE;}
  User::updateOrCreate(['email'=>$email],['name'=>$name,'password'=>$password,'role'=>'super_admin','is_active'=>true]);
  $this->info('Admin ready.');return self::SUCCESS;
 }
}
