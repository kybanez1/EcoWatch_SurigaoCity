@php
  // $truck is an existing truck (edit) or an empty one (add).
  $exists = $truck->exists;
  $formId = 'new';
  $modalId = 'addTruck';
  if ($exists) {
      $formId = 't' . $truck->id;
      $modalId = 'editTruck' . $truck->id;
  }

  $useOldValues = (old('_form') === $formId);
  $codeValue = $truck->truck_code;
  $plateValue = $truck->plate_number;
  $capacityValue = $truck->capacity_tons;
  $statusValue = $truck->status;
  $collectorValue = $truck->collector_id;
  if (! $exists) {
      $codeValue = $nextCode;
      $capacityValue = 10;
      $statusValue = 'Available';
  }
  if ($useOldValues) {
      $codeValue = old_text('truck_code');
      $plateValue = old_text('plate_number');
      $capacityValue = old_text('capacity_tons');
      $statusValue = old_text('status');
      $collectorValue = old_text('collector_id');
  }
@endphp
<div class="modal-bg @if($useOldValues) open @endif" id="{{ $modalId }}">
  <div class="modal">
    <button class="close" data-close type="button" aria-label="Close">&times;</button>
    <h2>@if($exists) Edit {{ $truck->label }} @else Add Truck @endif</h2>
    @include('partials.modal-errors', ['show' => $useOldValues])
    <form method="POST" action="@if($exists){{ route('cgso.trucks.update', $truck) }}@else{{ route('cgso.trucks.store') }}@endif">
      @csrf @if($exists) @method('PUT') @endif <input type="hidden" name="_form" value="{{ $formId }}">
      <div class="two-columns">
        <div class="field"><label>Truck Code (unique)</label><input type="text" name="truck_code" value="{{ $codeValue }}" required></div>
        <div class="field"><label>Plate Number</label><input type="text" name="plate_number" value="{{ $plateValue }}" required></div>
      </div>
      <div class="two-columns">
        <div class="field"><label>Capacity (tons)</label><input type="number" step="0.1" min="0" name="capacity_tons" value="{{ $capacityValue }}" required></div>
        <div class="field">
          <label>Status</label>
          <select name="status">
            @foreach(\App\Models\Truck::STATUSES as $status)
              <option @selected($statusValue === $status)>{{ $status }}</option>
            @endforeach
          </select>
        </div>
      </div>
      <div class="field">
        <label>Assigned Collector (driver)</label>
        <select name="collector_id">
          <option value="">— None —</option>
          @foreach($collectors as $collector)
            <option value="{{ $collector->id }}" @selected($collectorValue == $collector->id)>{{ $collector->name }}</option>
          @endforeach
        </select>
        <small class="sub">The truck driver is not automatically the collector of a report. Reports are assigned to a collector separately.</small>
      </div>
      <div class="cell-right"><button class="btn primary">@if($exists) Save Changes @else Add Truck @endif</button></div>
    </form>
  </div>
</div>
