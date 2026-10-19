<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
class AuthController {
 public function form(){return view('auth.login');}
 public function login(Request $request){$credentials=$request->validate(['email'=>'required|email','password'=>'required|string']);
  if(Auth::attempt([...$credentials,'is_active'=>true],false)){$request->session()->regenerate();return redirect()->intended('/dashboard');}
  return back()->withErrors(['email'=>'Invalid login or inactive user.'])->onlyInput('email');
 }
 public function logout(Request $request){Auth::logout();$request->session()->invalidate();$request->session()->regenerateToken();return redirect('/login');}
}
