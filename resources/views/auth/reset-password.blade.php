@extends('layouts.app')
@section('title','Reset Password')
@section('content')
<div class="login-wrap">
  <div class="login-card">
    <div class="head">
      <img src="{{ asset('img/logo.png') }}" alt="">
      <div>
        <h2>Choose a new password</h2>
        <span class="sub small-sub">Use at least 8 characters.</span>
      </div>
    </div>
    @include('partials.flash')
    <form method="POST" action="{{ route('password.update') }}">
      @csrf <input type="hidden" name="token" value="{{ $token }}">
      <div class="field"><label for="email">Email address</label><input type="email" name="email" id="email" value="{{ old_text('email', $email) }}" required></div>
      <div class="field"><label for="password">New password</label><input type="password" name="password" id="password" minlength="8" required></div>
      <div class="field"><label for="password_confirmation">Confirm new password</label><input type="password" name="password_confirmation" id="password_confirmation" minlength="8" required></div>
      <button class="btn primary full-width-button" type="submit">Change Password</button>
    </form>
  </div>
</div>
@endsection
