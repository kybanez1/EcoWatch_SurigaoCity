@extends('layouts.app')
@section('title','Announcements')
@section('content')
<div class="container">
  <div class="eyebrow">Public · No login required</div>
  <h1>Announcements</h1>
  <br>
  <div class="grid grid-two">
    @forelse($announcements as $announcement)
      <div class="card">
        <span class="badge">{{ $announcement->category }}</span>
        <h3 class="announcement-title">{{ $announcement->title }}</h3>
        <div class="sub announcement-date">{{ $announcement->published_on->format('Y-m-d') }}</div>
        <p class="no-margin">{{ $announcement->body }}</p>
      </div>
    @empty
      <div class="card empty">No announcements yet.</div>
    @endforelse
  </div>
  {{ $announcements->links() }}
</div>
@include('partials.footer')
@endsection
