@extends('layouts.app')
@section('title','Home')
@section('content')
<section class="hero">
  <div class="content-inner">
    <div>
      <span class="chip"><img src="{{ asset('img/logo.png') }}" alt="">Surigao City · Community Service Portal</span>
      <h1>Keep Surigao City clean. Monitor, report, improve.</h1>
      <p>EcoWatch gives residents accessible garbage collection information and helps communities monitor and report garbage-related concerns — see which truck is assigned to your area and when it is scheduled.</p>
      <div class="cta"><a class="btn white" href="{{ route('schedule') }}">📅 Check Collection Schedule</a><a class="btn light" href="{{ route('trucks-routes') }}">🚚 Collection Routes</a> @auth @if(auth()->user()->role === 'resident' || auth()->user()->role === 'official') <a class="btn amber" href="{{ route(auth()->user()->role.'.reports.create') }}">⚑ Report a Problem</a> @else <a class="btn amber" href="{{ auth()->user()->homeRoute() }}">⚑ Report a Problem</a> @endif @else <a class="btn amber" href="{{ route('login') }}">⚑ Report a Problem</a> @endauth </div>
    </div>
    <div class="hero-art"><svg viewBox="0 0 300 200"><path d="M30 150 C80 120 90 110 120 105 S170 60 210 70 S250 40 265 30" fill="none" stroke="#7fdcb0" stroke-width="3" stroke-dasharray="1 7" stroke-linecap="round"/><circle cx="30" cy="150" r="7" fill="#fff"/><text x="44" y="155" fill="#fff" font-size="11">Start</text><circle cx="120" cy="105" r="6" fill="#7fdcb0"/><circle cx="265" cy="30" r="9" fill="#e0a030"/><text x="195" y="25" fill="#fff" font-size="11">Assigned route</text></svg></div>
  </div>
</section>
<div class="feature">
  <div class="card">
    <div class="ico">🗺</div>
    <h3 class="feature-title">See every route</h3>
    <span class="sub">View which streets each truck is assigned to on an interactive map, color-coded by truck. (Routes are assignments, not live tracking.)</span>
  </div>
  <div class="card">
    <div class="ico">📍</div>
    <h3 class="feature-title">Pin the problem</h3>
    <span class="sub">Report overflowing bins or illegal dumping with a photo and exact map location.</span>
  </div>
  <div class="card">
    <div class="ico">✔</div>
    <h3 class="feature-title">Verify it's fixed</h3>
    <span class="sub">Track your report from submission to resolution. The CGSO verifies the cleanup, and you can give your feedback.</span>
  </div>
</div>
@include('partials.footer')
@endsection
