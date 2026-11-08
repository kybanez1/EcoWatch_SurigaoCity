@extends('layouts.app')
@section('title','Collection Routes')
@section('content')
<div class="container">
  <div class="eyebrow">Public · No login required</div>
  <h1>Collection Routes</h1>
  <p class="sub">Find out which truck is assigned to your area, which streets it collects and when. The map shows <b>assigned collection routes</b>, not the live position of a truck: EcoWatch has no GPS tracking. A barangay may have more than one schedule.</p>
  @if($maintenanceTrucks->count() > 0)
    <div class="notice-box"><b>Service interrupted:</b> @foreach($maintenanceTrucks as $maintenanceTruck){{ $maintenanceTruck->label }}@if(! $loop->last), @endif @endforeach under maintenance. Their routes may be collected late or not at all.</div>
  @endif
  <div class="grid two-column-grid">
    <div class="grid">
      <div class="card">
        <h3>🚚 Which Truck Collects My Barangay?</h3>
        <label>Select your Barangay</label>
        <select id="brgy">
          <option value="">Choose barangay...</option>
          @foreach($barangays as $barangay)
            <option value="{{ $barangay->id }}">{{ $barangay->name }}</option>
          @endforeach
        </select>
        <div id="brgy-info" class="info-box"></div>
      </div>
      <div class="card">
        <h3>📍 Find Your Area on the Map</h3>
        <p class="sub no-top-margin">Click anywhere on the map, or use your current location, to check which route covers that spot.</p>
        <button class="btn full-width-button" id="loc" type="button">◇ Use My Current Location</button>
        <div id="spot" class="info-box"></div>
      </div>
    </div>
    <div class="card">
      <div class="card-header-row">
        <h3 class="no-margin">🗺 Assigned Collection Routes</h3>
        <select id="filter" class="select-auto">
          <option value="">View All Routes</option>
          @foreach($trucks as $truck)
            <option value="{{ $truck->id }}">{{ $truck->label }}</option>
          @endforeach
        </select>
      </div>
      <div id="map" class="map tall"></div>
      <div class="eyebrow legend-title">Route Legend</div>
      <div class="legend">@foreach($routes as $route)<span class="pill"><i class="dot" data-color="{{ $route['color'] }}"></i>{{ $route['truck'] }} — {{ $route['name'] }}</span>@endforeach</div>
    </div>
  </div>
</div>
@include('partials.footer')
<script type="application/json" id="page-data">@json(['routes' => $routes, 'scheduleLines' => $scheduleLines])</script>
@endsection
@push('scripts')
<script src="{{ asset('js/public-routes.js') }}"></script>
@endpush
