@extends('layouts.admin')
@section('title','Trucks')
@section('content')
<div class="page-head">
  <h1>Truck Management</h1>
  <button class="btn primary sm" data-open="addTruck" type="button">+ Add Truck</button>
</div>
<div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th>Truck #</th>
        <th>Code</th>
        <th>Plate</th>
        <th>Capacity</th>
        <th>Driver (Collector)</th>
        <th>Status</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      @forelse($trucks as $truck)
        <tr>
          <td><span class="pill"><i class="dot" data-color="{{ $truck->color }}"></i>{{ $truck->label }}</span></td>
          <td>{{ $truck->truck_code }}</td>
          <td>{{ $truck->plate_number }}</td>
          <td>{{ rtrim(rtrim(number_format($truck->capacity_tons, 1), '0'), '.') }} tons</td>
          <td>@if($truck->collector){{ $truck->collector->name }}@else — @endif</td>
          <td>
            <form method="POST" action="{{ route('cgso.trucks.status', $truck) }}">
              @csrf @method('PATCH')
              <select name="status" data-autosubmit class="select-auto" aria-label="Truck status">
                @foreach(\App\Models\Truck::STATUSES as $status)
                  <option @selected($truck->status === $status)>{{ $status }}</option>
                @endforeach
              </select>
            </form>
          </td>
          <td>
            <div class="actions">
              <button class="btn sm icon" data-open="editTruck{{ $truck->id }}" type="button" title="Edit" aria-label="Edit truck">✎</button>
              <form method="POST" action="{{ route('cgso.trucks.destroy', $truck) }}" data-confirm="Delete {{ $truck->label }}? A truck that still has routes, schedules, notes or report history cannot be deleted.">@csrf @method('DELETE')<button class="btn sm danger icon" title="Delete" aria-label="Delete truck">🗑</button></form>
            </div>
          </td>
        </tr>
      @empty
        <tr>
          <td colspan="7" class="empty">No trucks yet.</td>
        </tr>
      @endforelse
    </tbody>
  </table>
</div>
@foreach($trucks as $truck)
  @include('cgso.truck-modal')
@endforeach
@include('cgso.truck-modal', ['truck' => $newTruck])
@endsection
