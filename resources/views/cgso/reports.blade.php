@extends('layouts.admin')
@section('title','Reports')
@section('content')
<h1>Garbage Reports</h1>
<p class="sub">Reports can come from residents or from a barangay official. Open a report to see the photos, assign a collector, and approve or reject the cleanup.</p>
<div class="card">
  <h3>🗺 Garbage Report Map <small class="sub">(reports on this page)</small></h3>
  <div id="map" class="map"></div>
</div>
<br>
<form method="GET" class="card filters">
  <div class="filter-wide"><label>Search</label><input type="text" name="search" value="{{ request('search') }}" placeholder="Report ID, location or resident"></div>
  <div>
    <label>Status</label>
    <select name="status">
      <option value="">All</option>
      @foreach(\App\Models\Report::STATUSES as $status)
        <option @selected(request('status') === $status)>{{ $status }}</option>
      @endforeach
    </select>
  </div>
  <div>
    <label>Category</label>
    <select name="category">
      <option value="">All</option>
      @foreach(\App\Models\Report::CATEGORIES as $category)
        <option @selected(request('category') === $category)>{{ $category }}</option>
      @endforeach
    </select>
  </div>
  <div>
    <label>Barangay</label>
    <select name="barangay_id">
      <option value="">All</option>
      @foreach($barangays as $barangay)
        <option value="{{ $barangay->id }}" @selected(request('barangay_id') == $barangay->id)>{{ $barangay->name }}</option>
      @endforeach
    </select>
  </div>
  <div><label>Date</label><input type="date" name="date" value="{{ request('date') }}"></div>
  <div>
    <label>Follow-up</label>
    <select name="feedback">
      <option value="">Any</option>
      <option value="not_resolved" @selected(request('feedback') === 'not_resolved')>Resident said "Not Resolved"</option>
    </select>
  </div>
  <div>
    <label>Collector</label>
    <select name="needs_collector">
      <option value="">Any</option>
      <option value="1" @selected(request('needs_collector') === '1')>Older reports without a collector</option>
    </select>
  </div>
  <div class="filter-buttons"><button class="btn primary">Filter</button> <a class="btn" href="{{ route('cgso.reports') }}">Reset</a></div>
</form>
<div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th>ID</th>
        <th>Category</th>
        <th>Barangay</th>
        <th>Reported By</th>
        <th>Collector</th>
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
          <td>{{ $report->barangay->name ?? 'Unknown barangay' }}</td>
          <td>{{ $report->user->name ?? 'Removed user' }} @if($report->user)<span class="badge badge-role">{{ $report->user->roleLabel() }}</span>@endif</td>
          <td>@if($report->collector){{ $report->collector->name }}@elseif($report->needs_collector)<span class="badge badge-Reopened">Needs collector</span>@else — @endif</td>
          <td>{{ $report->created_at->format('Y-m-d') }}</td>
          <td><span class="badge {{ $report->badge_class }}">{{ $report->status }}</span></td>
          <td class="cell-right"><button class="btn sm" data-open="rep{{ $report->id }}" type="button">View</button></td>
        </tr>
      @empty
        <tr>
          <td colspan="8" class="empty">No reports found.</td>
        </tr>
      @endforelse
    </tbody>
  </table>
</div>
{{ $reports->links() }}
@foreach($reports as $report)
  @include('cgso.report-modal')
@endforeach
<script type="application/json" id="page-data">@json(['pins' => $pins])</script>
@endsection
@push('scripts')
<script src="{{ asset('js/cgso-reports.js') }}"></script>
@endpush
