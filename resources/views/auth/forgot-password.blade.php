@extends('layouts.app')
@section('title','Forgot Password')
@section('content')
<div class="login-wrap">
  <div class="login-card">
    <div class="head">
      <img src="{{ asset('img/logo.png') }}" alt="">
      <div>
        <h2>Forgot your password?</h2>
        <span class="sub small-sub">Enter your email address and we will send you a link to choose a new password.</span>
      </div>
    </div>
    @include('partials.flash')
    <form method="POST" action="{{ route('password.email') }}">
      @csrf
      <div class="field"><label for="email">Email address</label><input type="email" name="email" id="email" value="{{ old_text('email') }}" required autofocus></div>
      <button class="btn primary full-width-button" type="submit">Send Reset Link</button>
    </form>
    <div class="demo demo-plain">Remembered it? <a href="{{ route('login') }}">Back to login</a></div>
  </div>
</div>
@endsection
