@extends('layouts.app')
@section('title', 'Collector')
@section('content')
<div class="container">
  <h1>Hi, {{ auth()->user()->name }}</h1>
  <p class="sub">Here is your assigned truck, your collection routes and the reports assigned to you.</p>
  @include('partials.flash')
  @if(! $truck)
    <div class="card empty">No truck is assigned to you yet. Please contact the CGSO.</div>
  @else
    @if($truck->status === 'Maintenance')
      <div class="notice-box"> <b>{{ $truck->label }} is under maintenance.</b> Collection progress updates are disabled until the CGSO sets the truck to Available. </div>
    @endif
    <div class="grid grid-two align-start">
      <div class="grid">
        <div class="card">
          <h3>🚚 My Truck</h3>
          <span class="pill"> <i class="dot" data-color="{{ $truck->color }}"></i> {{ $truck->label }} </span>
          <div class="detail-grid detail-top">
            <div><small>Plate / Code</small>{{ $truck->plate_number }} · {{ $truck->truck_code }}</div>
            <div><small>Truck Status</small><span class="badge {{ $truck->badge_class }}">{{ $truck->status }}</span></div>
          </div>
          <p class="schedule-title"><small class="sub">My Schedules</small></p>
          <ul class="list-small">
            @forelse($schedules as $schedule)
              <li> {{ $schedule->barangay_name }}: <b>{{ $schedule->days_label }}</b>, {{ $schedule->pickup_time_label }} @if($schedule->runsToday()) <span class="badge badge-Active">Today</span> @endif </li>
            @empty
              <li>No active schedules.</li>
            @endforelse
          </ul>
        </div>
        <div class="card">
          <h3>☰ Collection Progress</h3>
          @if($allRoutes->count() > 1)
            <p class="sub no-top-margin">Choose a route:</p>
          @endif
          <div class="route-picker"> @foreach($allRoutes as $oneRoute) <a href="{{ route('collector.dashboard', ['route' => $oneRoute->id]) }}" @if($route && $route->id === $oneRoute->id) class="current" aria-current="page" @endif> {{ $oneRoute->name }} ({{ $oneRoute->barangay_name }}) @if(in_array($oneRoute->id, $todayRouteIds)) · Today @else · Not today @endif </a> @endforeach </div>
          @if($route)
            @if($routeIsToday)
              <p class="sub no-top-margin"> <b>{{ $route->name }}</b> is scheduled today. Change an area from Pending to Collecting, then to Done. This is separate from your reports. </p>
            @else
              <div class="notice-box"> <b>{{ $route->name }}</b> is not scheduled today. You can view its streets, but progress can only be changed on its collection days. </div>
            @endif
            @forelse($route->areas as $area)
              <div class="area-row">
                <span> @if($area->today_status === 'Done') ✅ @elseif($area->today_status === 'Collecting') 🟡 @else ⚪ @endif {{ $area->name }} </span>
                <form method="POST" action="{{ route('collector.areas.update', $area) }}" class="seg"> @csrf @foreach(\App\Models\CollectionArea::STATUSES as $status) <button name="status" value="{{ $status }}" @if($area->today_status === $status) class="on-{{ $status }}" @endif @if(! $routeIsToday || $truck->status === 'Maintenance' || ! $area->canChangeTo($status)) disabled @endif> {{ $status }} </button> @endforeach </form>
              </div>
            @empty
              <p class="sub">No streets/areas have been added to this route yet.</p>
            @endforelse
            @if($areaCount > 0)
              <div class="progress"><i data-width="{{ $progressPercent }}"></i></div>
              <small class="sub">{{ $doneCount }} of {{ $areaCount }} areas completed</small>
            @endif
          @else
            <p class="sub">No route has been set for your truck yet.</p>
          @endif
        </div>
      </div>
      <div class="grid">
        <div class="card">
          <h3>🗺 Route Map</h3>
          <p class="sub no-top-margin">The line shows the assigned collection route. It is not a live truck position.</p>
          <div id="map" class="map sm"></div>
        </div>
        <div class="card">
          <h3>📝 Add Collection Note</h3>
          <p class="sub no-top-margin">Enter an update or problem encountered during collection.</p>
          <form method="POST" action="{{ route('collector.notes.store') }}" enctype="multipart/form-data">
            @csrf
            @php
              $selectedRouteId = 0;
              if ($route) {
                  $selectedRouteId = $route->id;
              }
            @endphp
            @if($allRoutes->count() > 0)
              <div class="field">
                <label for="note-route">Route</label>
                <select id="note-route" name="truck_route_id" required>
                  @foreach($allRoutes as $oneRoute)
                    <option value="{{ $oneRoute->id }}" @selected(old_text('truck_route_id', $selectedRouteId) == $oneRoute->id)> {{ $oneRoute->name }} ({{ $oneRoute->barangay_name }}) </option>
                  @endforeach
                </select>
              </div>
            @endif
            <div class="button-row"> <input type="text" name="note" value="{{ old_text('note') }}" maxlength="255" aria-label="Collection note" placeholder="e.g. Blocked road, delayed by 30 mins" required> <button class="btn primary">Send Note</button> </div>
            <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" aria-label="Optional note photo" class="file-input-top"> <small class="sub">Optional: attach a photo (JPG, PNG or WEBP, up to 5 MB).</small>
          </form>
          <div class="note-list">
            @forelse($notes as $note)
              <div class="note-item"> {{ $note->note }} <span class="sub"> · {{ $note->created_at->format('Y-m-d g:i A') }} @if($note->route_text) · {{ $note->route_text }} @endif </span> @if($note->photo_path) <a href="{{ asset($note->photo_path) }}" target="_blank" rel="noopener">Photo</a> @endif </div>
            @empty
              <p class="sub">No notes yet.</p>
            @endforelse
          </div>
          {{ $notes->links() }}
        </div>
      </div>
    </div>
  @endif
  <br>
  <div class="card">
    <h3>⚑ My Assigned Reports</h3>
    @forelse($activeReports as $report)
      @include('collector.report-card')
    @empty
      <p class="sub">No open reports are assigned to you.</p>
    @endforelse
    @if($finishedReports->count() > 0)
      <h3>Finished reports</h3>
      @foreach($finishedReports as $report)
        <div class="note-item"> {{ $report->code }} — {{ $report->category }} <span class="badge {{ $report->badge_class }}">{{ $report->status }}</span> <span class="sub">The CGSO Administrator approved this cleanup.</span> </div>
      @endforeach
    @endif
  </div>
</div>
<script type="application/json" id="page-data">@json(['route' => $routeMap])</script>
@endsection
@push('scripts')
@if($truck)
  <script src="{{ asset('js/collector.js') }}"></script>
@endif
@endpush
