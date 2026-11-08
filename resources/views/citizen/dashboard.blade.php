@extends('layouts.app')
@section('title','Dashboard')
@section('content')
<div class="container">
  <h1>Welcome, {{ auth()->user()->name }}</h1>
  <p class="sub">@if($role === 'official')You are the registered official for @if(auth()->user()->barangay)Barangay {{ auth()->user()->barangay->name }}@else your barangay @endif.@else @if(auth()->user()->barangay)Barangay {{ auth()->user()->barangay->name }} — @endif here's what's happening with garbage collection in your area.@endif</p>
  @include('partials.flash')
  <div class="grid main-grid">
    <div class="grid">
      @include('citizen.schedule-card')
      <div class="card">
        <h3>⚑ @if($role === 'official') My Reports @else Recent Reports @endif</h3>
        <div class="table-wrap table-flat">
          <table>
            <tbody>
              @forelse($recentReports as $report)
                <tr>
                  <td>{{ $report->code }}</td>
                  <td>{{ $report->category }}</td>
                  <td><span class="badge {{ $report->badge_class }}">{{ $report->status }}</span></td>
                  <td class="cell-right"><a href="{{ route($role.'.reports') }}">View</a></td>
                </tr>
              @empty
                <tr>
                  <td class="empty">You have not submitted any reports yet.</td>
                </tr>
              @endforelse
            </tbody>
          </table>
        </div>
        <br> <a class="btn primary sm" href="{{ route($role.'.reports.create') }}">+ Report a New Problem</a>
      </div>
      @if($role === 'official')
        <div class="card">
          <h3>👥 All Reports in Your Barangay</h3>
          <p class="sub no-top-margin">As barangay official, you can see every report residents have filed in your area — including ones you didn't submit yourself.</p>
          <div class="table-wrap table-flat">
            <table>
              <tbody>
                @forelse($barangayReports as $report)
                  <tr>
                    <td>{{ $report->code }}</td>
                    <td>{{ $report->category }}</td>
                    <td>{{ $report->user->name ?? 'Removed user' }}</td>
                    <td><span class="badge {{ $report->badge_class }}">{{ $report->status }}</span></td>
                  </tr>
                @empty
                  <tr>
                    <td class="empty">No reports in your barangay yet.</td>
                  </tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
        <div class="card">
          <h3>☰ Today's Collection Progress in Your Barangay</h3>
          @forelse($barangayRoutes as $route)
            <p class="route-progress-title"><span class="pill"><i class="dot" data-color="{{ $route->truck_color }}"></i>{{ $route->truck_label }} — {{ $route->name }}</span></p>
            @forelse($route->areas as $area)
              <div class="area-row"><span>{{ $area->name }}</span><span class="badge badge-{{ $area->today_status }}">{{ $area->today_status }}</span></div>
            @empty
              <p class="sub">No streets/areas have been added to this route yet.</p>
            @endforelse
          @empty
            <p class="sub">No routes have been set for your barangay yet.</p>
          @endforelse
        </div>
      @endif
    </div>
    <div class="grid">
      <div class="grid grid-two">
        <div class="card stat"><b>{{ $myReportCount }}</b><span>My Reports</span></div>
        <div class="card stat"><b>{{ $myResolvedCount }}</b><span>Resolved</span></div>
      </div>
      <div class="card">
        <h3>📣 Announcements</h3>
        @forelse($announcements as $announcement)
          <div class="announcement-row">
            <b>{{ $announcement->title }}</b>
            <div class="sub announcement-date no-margin">{{ $announcement->published_on->format('Y-m-d') }}</div>
          </div>
        @empty
          <p class="sub">No announcements.</p>
        @endforelse
        <p class="view-all-link"><a href="{{ route('announcements') }}">View all</a></p>
      </div>
    </div>
  </div>
</div>
@endsection
