@extends('layouts.app')
@section('title','Register')
@section('content')
<div class="login-wrap">
  <div class="login-card">
    <div class="head">
      <img src="{{ asset('img/logo.png') }}" alt="">
      <div>
        <h2>Create resident account</h2>
        <span class="sub small-sub">Register to report garbage problems in your barangay.</span>
      </div>
    </div>
    @include('partials.flash')
    <form method="POST" action="{{ route('register.submit') }}">
      @csrf
      <div class="field"><label>Full name</label><input type="text" name="name" value="{{ old_text('name') }}" required></div>
      <div class="field"><label>Email address</label><input type="email" name="email" value="{{ old_text('email') }}" required></div>
      <div class="field">
        <label>Barangay</label>
        <select name="barangay_id" required>
          <option value="">Select barangay</option>
          @foreach($barangays as $barangay)
            <option value="{{ $barangay->id }}" @selected(old_text('barangay_id') == $barangay->id)>{{ $barangay->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="field"><label>Phone (optional)</label><input type="text" name="phone" value="{{ old_text('phone') }}"></div>
      <div class="two-columns">
        <div class="field"><label>Password (8+ characters)</label><input type="password" name="password" minlength="8" required></div>
        <div class="field"><label>Confirm</label><input type="password" name="password_confirmation" required></div>
      </div>
      <button class="btn primary full-width-button" type="submit">Register</button>
    </form>
    <div class="demo demo-plain-top">Already registered? <a href="{{ route('login') }}">Log in</a></div>
  </div>
</div>
@endsection
