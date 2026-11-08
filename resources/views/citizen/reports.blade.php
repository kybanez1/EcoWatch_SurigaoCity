@extends('layouts.app')
@section('title','My Reports')
@section('content')
<div class="container">
  <div class="page-head">
    <h1>My Reports</h1>
    <a class="btn primary sm" href="{{ route($role.'.reports.create') }}">+ Report a Problem</a>
  </div>
  @include('partials.flash')
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Category</th>
          <th>Location</th>
          <th>Date</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        @forelse($reports as $report)
          <tr>
            <td>{{ $report->code }}</td>
            <td>{{ $report->category }}</td>
            <td>{{ $report->location_text }}</td>
            <td>{{ $report->created_at->format('Y-m-d') }}</td>
            <td><span class="badge {{ $report->badge_class }}">{{ $report->status }}</span></td>
            <td><button class="btn sm" data-open="rep{{ $report->id }}" type="button">View</button></td>
          </tr>
        @empty
          <tr>
            <td colspan="6" class="empty">You have not submitted any reports yet.</td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>
  {{ $reports->links() }}
  @if($role === 'official')
    <br>
    <h2>Other reports in your barangay</h2>
    <br>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>ID</th>
            <th>Category</th>
            <th>Reported by</th>
            <th>Date</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          @forelse($barangayReports as $report)
            <tr>
              <td>{{ $report->code }}</td>
              <td>{{ $report->category }}</td>
              <td>{{ $report->user->name ?? 'Removed user' }}</td>
              <td>{{ $report->created_at->format('Y-m-d') }}</td>
              <td><span class="badge {{ $report->badge_class }}">{{ $report->status }}</span></td>
              <td><button class="btn sm" data-open="rep{{ $report->id }}" type="button">View</button></td>
            </tr>
          @empty
            <tr>
              <td colspan="6" class="empty">No other reports in your barangay.</td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
    {{ $barangayReports->links() }}
  @endif
</div>
@foreach($reports as $report)
  @include('citizen.report-modal')
@endforeach
@if($barangayReports)
  @foreach($barangayReports as $report)
    @include('citizen.report-modal')
  @endforeach
@endif
@endsection
