@extends($layout)
@section('title','My Account')
@section('content')
@if($layout === 'layouts.admin')
  <h1>My Account</h1>
  <p class="sub">Edit your own details and change your password.</p>
  @include('account.forms')
@else
  <div class="container narrow">
    <h1>My Account</h1>
    <p class="sub">Edit your own details and change your password.</p>
    @include('partials.flash')
    @include('account.forms')
  </div>
@endif
@endsection
