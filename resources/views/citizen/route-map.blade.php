@extends('layouts.app')
@section('title','Route Map')
@section('content')
<div class="container">
  <div class="page-head">
    <h1>Full Route Map</h1>
    <a class="btn sm" href="{{ route($role.'.dashboard') }}">← Back to dashboard</a>
  </div>
  <p class="sub">Your barangay's route is highlighted. Other routes are shown for reference.</p>
  <div class="card">
    <div id="map" class="map tall"></div>
    <div class="legend">@foreach($routes as $route)<span class="pill"><i class="dot" data-color="{{ $route['color'] }}"></i>{{ $route['truck'] }} — {{ $route['name'] }} ({{ $route['barangay'] }})</span>@endforeach</div>
  </div>
</div>
<script type="application/json" id="page-data">@json(['routes' => $routes, 'myRouteIds' => $myRouteIds])</script>
@endsection
@push('scripts')
<script src="{{ asset('js/route-map.js') }}"></script>
@endpush
