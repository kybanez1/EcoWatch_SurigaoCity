@php
  // $schedule is an existing schedule (edit) or an empty one (add).
  $exists = $schedule->exists;
  $formId = 'new';
  $modalId = 'addSched';
  if ($exists) {
      $formId = 's' . $schedule->id;
      $modalId = 'editSched' . $schedule->id;
  }

  // Use the values the user typed if this form had an error, otherwise the saved values.
  $useOldValues = (old('_form') === $formId);
  $truckValue = $schedule->truck_id; // NULL when the truck was removed: the CGSO must choose one
  $barangayValue = $schedule->barangay_id;
  $routeValue = $schedule->truck_route_id;
  $daysValue = $schedule->days;
  $timeValue = '';
  if ($exists && $schedule->pickup_time) {
      $timeValue = date('H:i', strtotime($schedule->pickup_time));
  }
  if (! $exists) {
      $timeValue = '07:00';
  }
  if ($useOldValues) {
      $truckValue = old_text('truck_id');
      $barangayValue = old_text('barangay_id');
      $routeValue = old_text('truck_route_id');
      $daysValue = old_list('days', null);
      $timeValue = old_text('pickup_time');
  }
  if (! $daysValue) {
      $daysValue = [];
  }
@endphp
<div class="modal-bg @if($useOldValues) open @endif" id="{{ $modalId }}">
  <div class="modal">
    <button class="close" data-close type="button" aria-label="Close">&times;</button>
    <h2>@if($exists) Edit Schedule @else Add Schedule @endif</h2>
    @include('partials.modal-errors', ['show' => $useOldValues])
    @if($exists && $schedule->needs_reassignment)
      <div class="alert bad"><b>This schedule needs reassignment.</b> Its @if(! $schedule->truck)truck @endif @if(! $schedule->truck && ! $schedule->barangay)and @endif @if(! $schedule->barangay)barangay @endif was removed. Choose a replacement below and save. It stays hidden from the public until then.</div>
    @endif
    @if($exists && $schedule->needs_pickup_time)
      <div class="alert bad">This is an old schedule without an exact pickup time.@if($schedule->time_range) The old range was {{ $schedule->time_range }}.@endif Please select the correct pickup time.</div>
    @endif
    <form method="POST" action="@if($exists){{ route('cgso.schedules.update', $schedule) }}@else{{ route('cgso.schedules.store') }}@endif">
      @csrf @if($exists) @method('PUT') @endif <input type="hidden" name="_form" value="{{ $formId }}">
      <div class="field">
        <label>Truck</label>
        <select name="truck_id" required>
          @if(! $trucks->contains('id', (int) $truckValue))
            <option value="" selected>— Select a truck —</option>
          @endif
          @foreach($trucks as $truck)
            <option value="{{ $truck->id }}" @selected($truckValue == $truck->id)>{{ $truck->select_label }}</option>
          @endforeach
        </select>
      </div>
      <div class="field">
        <label>Barangay</label>
        <select name="barangay_id" required>
          @if(! $barangays->contains('id', (int) $barangayValue))
            <option value="" selected>— Select a barangay —</option>
          @endif
          @foreach($barangays as $barangay)
            <option value="{{ $barangay->id }}" @selected($barangayValue == $barangay->id)>{{ $barangay->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="field">
        <label>Linked Route <small class="muted-text">(optional — reuses a drawn map path)</small></label>
        <select name="truck_route_id">
          <option value="">— None —</option>
          @foreach($routes as $route)
            <option value="{{ $route->id }}" @selected($routeValue == $route->id)>{{ $route->truck_label }} · {{ $route->name }} ({{ $route->barangay_name }})</option>
          @endforeach
        </select>
      </div>
      <div class="field">
        <label>Collection Days <span class="days-quick"><a data-days="weekdays">Weekdays</a><a data-days="all">Everyday</a></span></label>
        <div class="days">@foreach(\App\Models\Schedule::DAYS as $dayKey => $dayName)<label><input type="checkbox" name="days[]" value="{{ $dayKey }}" @checked(in_array($dayKey, $daysValue))> {{ $dayKey }}</label>@endforeach</div>
      </div>
      <div class="field"><label>Pickup Time</label><input type="time" name="pickup_time" value="{{ $timeValue }}" required><small class="sub">Select the scheduled pickup time, such as 7:00 AM.</small></div>
      <div class="cell-right"><button class="btn primary">Save Schedule</button></div>
    </form>
  </div>
</div>
