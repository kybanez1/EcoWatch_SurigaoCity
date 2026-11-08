@extends('layouts.app')
@section('title','Login')
@section('content')
<div class="login-wrap">
  <div class="login-card">
    <div class="head">
      <img src="{{ asset('img/logo.png') }}" alt="">
      <div>
        <h2>Welcome back</h2>
        <span class="sub small-sub">Log in to report problems and track your area's collection.</span>
      </div>
    </div>
    @include('partials.flash')
    <form method="POST" action="{{ route('login.submit') }}">
      @csrf
      <div class="field"><label>Email address</label><input type="email" name="email" id="email" value="{{ old_text('email') }}" required autofocus></div>
      <div class="field"><label>Password</label><input type="password" name="password" id="password" required></div>
      <button class="btn primary full-width-button" type="submit">Log In</button>
      <p class="forgot-link"><a href="{{ route('password.request') }}">Forgot your password?</a></p>
    </form>
    <div class="demo demo-plain">No account? <a href="{{ route('register') }}">Register as a resident</a><br><a href="{{ route('home') }}">← Back to home</a></div>
  </div>
</div>
@endsection
