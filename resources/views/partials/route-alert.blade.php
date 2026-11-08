{{-- Tells the CGSO which routes lost their truck or barangay and what to do. Needs $routesNeedingReassignment. --}}
@if(isset($routesNeedingReassignment) && $routesNeedingReassignment->count() > 0)
  <div class="alert bad" role="alert">
    <b>{{ $routesNeedingReassignment->count() }} route(s) need a replacement truck or barangay.</b>
    These routes are hidden from the public schedule, the maps and the schedule dropdowns until you fix them.
    Open each route in <a href="{{ route('cgso.routes') }}">Route Assignment</a>, choose a truck and barangay, then save. Its schedules are updated together.
    <ul class="error-list">
      @foreach($routesNeedingReassignment as $brokenRoute)
        <li><a href="{{ route('cgso.routes.edit', $brokenRoute) }}">{{ $brokenRoute->name }}</a> — {{ $brokenRoute->problem }}</li>
      @endforeach
    </ul>
  </div>
@endif
