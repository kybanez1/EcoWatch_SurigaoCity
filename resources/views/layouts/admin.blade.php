@extends('layouts.base')
@section('body')
<header class="topbar full">
  <div class="content-inner">
    <div class="brand-row">
      @include('partials.brand')
      <span class="role-chip">CGSO</span>
    </div>
    <button class="nav-toggle" type="button" aria-label="Menu">☰</button>
    <nav class="nav">
      <a href="{{ route('schedule') }}">Public Schedule</a><a href="{{ route('account.edit') }}" @if(request()->routeIs('account.*')) class="active" @endif>My Account</a><span class="user">City General Services Office</span>
      @include('partials.logout')
    </nav>
  </div>
</header>
<div class="admin-wrap">
  <aside class="sidebar"> <a href="{{ route('cgso.dashboard') }}" @if(request()->routeIs('cgso.dashboard*')) class="active" @endif><span class="sidebar-icon">◔</span>Dashboard</a> <a href="{{ route('cgso.routes') }}" @if(request()->routeIs('cgso.routes*')) class="active" @endif><span class="sidebar-icon">⚲</span>Route Assignment</a> <a href="{{ route('cgso.trucks') }}" @if(request()->routeIs('cgso.trucks*')) class="active" @endif><span class="sidebar-icon">🚚</span>Trucks</a> <a href="{{ route('cgso.schedules') }}" @if(request()->routeIs('cgso.schedules*')) class="active" @endif><span class="sidebar-icon">▦</span>Schedules</a> <a href="{{ route('cgso.reports') }}" @if(request()->routeIs('cgso.reports*')) class="active" @endif><span class="sidebar-icon">⚑</span>Reports</a> <a href="{{ route('cgso.collections') }}" @if(request()->routeIs('cgso.collections*')) class="active" @endif><span class="sidebar-icon">☰</span>Collection Monitoring</a> <a href="{{ route('cgso.announcements') }}" @if(request()->routeIs('cgso.announcements*')) class="active" @endif><span class="sidebar-icon">📣</span>Announcements</a> <a href="{{ route('cgso.users') }}" @if(request()->routeIs('cgso.users*')) class="active" @endif><span class="sidebar-icon">◎</span>Users &amp; Barangays</a> </aside>
  <main class="admin-main">
    @include('partials.flash')
    @include('partials.route-alert')
    @yield('content')
  </main>
</div>
@endsection
