@extends('layouts.app')
@section('title','Collection Schedule')
@section('content')
<div class="container">
  <div class="eyebrow">Public · No login required</div>
  <h1>Collection Schedule</h1>
  @include('partials.flash')
  <p class="sub">A truck can have more than one schedule — for example Truck #01 collects San Juan on Monday, Wednesday and Friday, and also picks up Anomar every Saturday. Each schedule shows one exact pickup time.</p>
  @if($maintenanceTrucks->count() > 0)
    <div class="notice-box"><b>Service interrupted:</b> @foreach($maintenanceTrucks as $maintenanceTruck){{ $maintenanceTruck->label }}@if(! $loop->last), @endif @endforeach under maintenance. Collections of these trucks may be delayed or cancelled. Please check the <a href="{{ route('announcements') }}">announcements</a>.</div>
  @endif
  <form method="GET" class="card filters">
    <div>
      <label>Filter by Barangay</label>
      <select name="barangay" data-autosubmit>
        <option value="">All Barangays</option>
        @foreach($barangays as $barangay)
          <option value="{{ $barangay->id }}" @selected(request('barangay') == $barangay->id)>{{ $barangay->name }}</option>
        @endforeach
      </select>
    </div>
    <div>
      <label>Filter by Day</label>
      <select name="day" data-autosubmit>
        <option value="">All Days</option>
        @foreach(\App\Models\Schedule::DAYS as $dayKey => $dayName)
          <option value="{{ $dayKey }}" @selected(request('day') == $dayKey)>{{ $dayName }}</option>
        @endforeach
      </select>
    </div>
    <div class="filter-count">{{ $schedules->count() }} schedule(s) found</div>
  </form>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Barangay</th>
          <th>Collection Days</th>
          <th>Pickup Time</th>
          <th>Assigned Truck</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        @forelse($schedules as $schedule)
          <tr>
            <td>{{ $schedule->barangay_name }}</td>
            <td>{{ $schedule->days_label }}</td>
            <td>{{ $schedule->pickup_time_label }}</td>
            <td><span class="pill"><i class="dot" data-color="{{ $schedule->truck_color }}"></i>{{ $schedule->truck_label }}</span></td>
            <td><span class="badge {{ $schedule->status_badge_class }}">{{ $schedule->status_label }}</span></td>
          </tr>
        @empty
          <tr>
            <td colspan="5" class="empty">No schedules match your filter.</td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>
  <br>
  <div class="card">
    <h3>🚚 Schedules Grouped by Truck</h3>
    <div class="grid grid-three">
      @foreach($byTruck as $list)
        <div class="item-box">
          <span class="pill"><i class="dot" data-color="{{ $list[0]->truck_color }}"></i>{{ $list[0]->truck_label }}</span>
          <ul class="list-small">
            @foreach($list as $schedule)
              <li>{{ $schedule->barangay_name }}: <b>{{ $schedule->days_label }}</b>, {{ $schedule->pickup_time_label }}</li>
            @endforeach
          </ul>
        </div>
      @endforeach
    </div>
  </div>
</div>
@include('partials.footer')
@endsection
