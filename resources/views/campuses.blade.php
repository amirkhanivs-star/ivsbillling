@extends('layouts.app')
@section('title','Campuses')
@section('content')
<div class="row" style="margin-bottom:25px"><form method="post" action="/manage-branches" class="row">@csrf<input class="input" name="name" required placeholder="Campus name" style="width:200px"><input class="input" name="code" required placeholder="Unique code" style="width:130px"><button class="btn purple wide">+ Add Branch</button></form></div>
<div class="row" style="align-items:stretch">@foreach($campuses as $campus)<div class="campus-card"><div class="upper">{{ $campus->name }}</div><div class="lower"><strong>IQRA VIRTUAL SCHOOL</strong><br>Campus Code: <span class="purple-text">{{ $campus->code }}</span><br>{{ $campus->address }}</div></div>@endforeach</div>
@endsection
