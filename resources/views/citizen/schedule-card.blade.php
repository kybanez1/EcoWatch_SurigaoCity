<div class="card">
  <h3>🚚 Collection Schedule for {{ auth()->user()->barangay->name ?? 'your barangay' }}</h3>
  @forelse($schedules as $schedule)
    <div class="item-box spaced">
      <span class="pill"><i class="dot" data-color="{{ $schedule->truck_color }}"></i>{{ $schedule->truck_label }}@if($schedule->route) — {{ $schedule->route->name }}@endif</span>
      <div class="detail-grid detail-top">
        <div><small>Collection Days</small>{{ $schedule->days_label }}</div>
        <div><small>Pickup Time</small>{{ $schedule->pickup_time_label }}</div>
        <div><small>Status</small><span class="badge {{ $schedule->status_badge_class }}">{{ $schedule->status_label }}</span></div>
        <div><small>Truck Driver</small>@if($schedule->truck && $schedule->truck->collector){{ $schedule->truck->collector->name }}@else Not assigned @endif</div>
      </div>
      @if($schedule->service_interrupted)
        <p class="notice-box">This truck is under maintenance. Collection may be delayed or cancelled. Please check the announcements.</p>
      @endif
    </div>
  @empty
    <p class="sub">No collection schedule has been set for your barangay yet.</p>
  @endforelse
  <a class="btn sm" href="{{ route($role.'.route-map') }}">View Full Route Map</a>
</div>
