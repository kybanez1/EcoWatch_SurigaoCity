@extends('layouts.base')
@section('body')
<header class="topbar">
  <div class="content-inner">
    <div class="brand-row">
      @include('partials.brand')
      @auth @if(auth()->user()->role !== 'cgso') <span class="role-chip">{{ auth()->user()->roleLabel() }}</span> @endif @endauth
    </div>
    <button class="nav-toggle" type="button" aria-label="Menu">☰</button>
    <nav class="nav">
      @auth
      @if(auth()->user()->role === 'resident' || auth()->user()->role === 'official')
        <a href="{{ route(auth()->user()->role.'.dashboard') }}" @if(request()->routeIs('*.dashboard')) class="active" @endif>Dashboard</a> <a href="{{ route(auth()->user()->role.'.reports.create') }}" @if(request()->routeIs('*.reports.create')) class="active" @endif>Report a Problem</a> <a href="{{ route(auth()->user()->role.'.reports') }}" @if(request()->routeIs('*.reports')) class="active" @endif>My Reports</a> <a href="{{ route('schedule') }}" @if(request()->routeIs('schedule')) class="active" @endif>Schedule</a> <a href="{{ route(auth()->user()->role.'.route-map') }}" @if(request()->routeIs('*.route-map')) class="active" @endif>Route Map</a> <a href="{{ route('announcements') }}" @if(request()->routeIs('announcements')) class="active" @endif>Announcements</a> <a href="{{ route('account.edit') }}" @if(request()->routeIs('account.*')) class="active" @endif>{{ auth()->user()->name }}</a>
        @include('partials.logout')
      @elseif(auth()->user()->role === 'collector')
        <a href="{{ route('collector.dashboard') }}" @if(request()->routeIs('collector.dashboard')) class="active" @endif>My Work</a> <a href="{{ route('schedule') }}" @if(request()->routeIs('schedule')) class="active" @endif>Schedule</a> <a href="{{ route('trucks-routes') }}" @if(request()->routeIs('trucks-routes')) class="active" @endif>Collection Routes</a> <a href="{{ route('announcements') }}" @if(request()->routeIs('announcements')) class="active" @endif>Announcements</a> <a href="{{ route('account.edit') }}" @if(request()->routeIs('account.*')) class="active" @endif>{{ auth()->user()->name }}</a>
        @include('partials.logout')
      @else
        <a href="{{ route('home') }}" @if(request()->routeIs('home')) class="active" @endif>Home</a> <a href="{{ route('schedule') }}" @if(request()->routeIs('schedule')) class="active" @endif>Schedule</a> <a href="{{ route('trucks-routes') }}" @if(request()->routeIs('trucks-routes')) class="active" @endif>Collection Routes</a> <a href="{{ route('announcements') }}" @if(request()->routeIs('announcements')) class="active" @endif>Announcements</a> <a class="btn sm light" href="{{ auth()->user()->homeRoute() }}">My Portal</a><a href="{{ route('account.edit') }}">My Account</a>
        @include('partials.logout')
      @endif
      @else <a href="{{ route('home') }}" @if(request()->routeIs('home')) class="active" @endif>Home</a> <a href="{{ route('schedule') }}" @if(request()->routeIs('schedule')) class="active" @endif>Schedule</a> <a href="{{ route('trucks-routes') }}" @if(request()->routeIs('trucks-routes')) class="active" @endif>Collection Routes</a> <a href="{{ route('announcements') }}" @if(request()->routeIs('announcements')) class="active" @endif>Announcements</a> <a class="btn sm light" href="{{ route('login') }}">Login</a><a class="btn sm light" href="{{ route('register') }}">Register</a> @endauth
    </nav>
  </div>
</header>
@yield('content')
@endsection
