@extends('layouts.admin')
@section('title','Schedules')
@section('content')
<div class="page-head">
  <div>
    <h1>Collection Schedules</h1>
    <p class="sub no-bottom-margin">A truck can have several schedule entries. For example, Truck #01 can collect San Juan on Monday/Wednesday/Friday at 7:00 AM and have a separate entry for Anomar every Saturday.</p>
  </div>
  <button class="btn primary sm" data-open="addSched" type="button">+ Add Schedule</button>
</div>
@if($needsTimeCount > 0)
  <div class="alert bad"><b>{{ $needsTimeCount }} old schedule(s) have no exact pickup time.</b> Old time ranges are not converted automatically. Click the edit button (✎) on each flagged schedule and select the correct pickup time. They show "Time to be set" to the public until then.</div>
@endif
<div class="card">
  <h3>🚚 Grouped by Truck</h3>
  <div class="grid grid-three">
    @foreach($byTruck as $list)
      <div class="item-box">
        <span class="pill"><i class="dot" data-color="{{ $list[0]->truck_color }}"></i>{{ $list[0]->truck_label }}</span>
        <div class="list-small-block">
          @foreach($list as $schedule)
            <div><b>{{ $schedule->barangay_name }}: {{ $schedule->days_label }}, {{ $schedule->pickup_time_label }}</b></div>
          @endforeach
        </div>
      </div>
    @endforeach
  </div>
</div>
<br>
<div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th>Truck</th>
        <th>Barangay</th>
        <th>Linked Route</th>
        <th>Days</th>
        <th>Pickup Time</th>
        <th>Active</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      @forelse($schedules as $schedule)
        <tr>
          <td><span class="pill"><i class="dot" data-color="{{ $schedule->truck_color }}"></i>{{ $schedule->truck_label }}</span>@if($schedule->needs_reassignment)<br><span class="badge badge-Reopened">Needs reassignment</span>@endif</td>
          <td>{{ $schedule->barangay_name }}</td>
          <td>@if($schedule->route){{ $schedule->route->name }} ({{ $schedule->route->barangay_name }})@else — (no drawn path yet) @endif</td>
          <td>{{ $schedule->days_label }}</td>
          <td>@if($schedule->needs_pickup_time)<span class="badge badge-Maintenance">Needs pickup time</span>@if($schedule->time_range)<br><small class="sub">Old range: {{ $schedule->time_range }}</small>@endif @else {{ $schedule->pickup_time_label }} @endif</td>
          <td>
            <form method="POST" action="{{ route('cgso.schedules.toggle', $schedule) }}">@csrf @method('PATCH')<label class="switch"><input type="checkbox" data-autosubmit aria-label="Schedule active" @checked($schedule->is_active)><span></span></label></form>
            @if($schedule->is_active && $schedule->service_interrupted)<span class="badge badge-Maintenance">Truck in maintenance</span>@endif
          </td>
          <td>
            <div class="actions">
              <button class="btn sm icon" data-open="editSched{{ $schedule->id }}" type="button" title="Edit" aria-label="Edit schedule">✎</button>
              <form method="POST" action="{{ route('cgso.schedules.destroy', $schedule) }}" data-confirm="Delete this schedule?">@csrf @method('DELETE')<button class="btn sm danger icon" title="Delete" aria-label="Delete schedule">🗑</button></form>
            </div>
          </td>
        </tr>
      @empty
        <tr>
          <td colspan="7" class="empty">No schedules yet.</td>
        </tr>
      @endforelse
    </tbody>
  </table>
</div>
@foreach($schedules as $schedule)
  @include('cgso.schedule-modal')
@endforeach
@include('cgso.schedule-modal', ['schedule' => $newSchedule])
@endsection
