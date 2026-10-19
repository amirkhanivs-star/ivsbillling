<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
class AccountsAccess {
 public function handle(Request $request, Closure $next) {
  abort_unless($request->user()?->is_active && in_array($request->user()->role,['super_admin','finance_admin','cashier','department_manager','auditor'],true),403);
  return $next($request);
 }
}
