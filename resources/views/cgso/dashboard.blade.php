@extends('layouts.admin')
@section('title','CGSO Dashboard')
@section('content')
<h1>CGSO Dashboard</h1>
<p class="sub">City General Services Office — garbage collection overview.</p>
<div class="todo-row"> <a href="{{ route('cgso.reports', ['status' => 'Pending']) }}"><b>{{ $todoCounts['pending'] }}</b> new reports to assign</a> <a href="{{ route('cgso.reports', ['status' => 'For Verification']) }}"><b>{{ $todoCounts['verification'] }}</b> waiting for verification</a> <a href="{{ route('cgso.reports', ['needs_collector' => 1]) }}"><b>{{ $todoCounts['needsCollector'] }}</b> older reports without a collector</a> <a href="{{ route('cgso.reports', ['feedback' => 'not_resolved']) }}"><b>{{ $todoCounts['feedback'] }}</b> "Not Resolved" feedback to review</a> </div>
<div class="grid grid-four">
  @foreach($stats as $label => $value)
    <div class="card stat"><b>{{ $value }}</b><span>{{ $label }}</span></div>
  @endforeach
</div>
<br>
<div class="grid grid-two">
  <div class="card">
    <h3>Reports by Category</h3>
    <div class="chart-box"><canvas id="chartCategory"></canvas></div>
  </div>
  <div class="card">
    <h3>Reports by Barangay</h3>
    <div class="chart-box"><canvas id="chartBarangay"></canvas></div>
  </div>
</div>
<script type="application/json" id="page-data">@json(['byCategory' => $byCategory, 'byBarangay' => $byBarangay])</script>
@endsection
@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="{{ asset('js/cgso-dashboard.js') }}"></script>
@endpush
