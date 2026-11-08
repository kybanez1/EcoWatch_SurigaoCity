@extends('layouts.admin')
@section('title', 'Users & Barangays')
@section('content')
<h1>Users &amp; Barangays</h1>
<br>
<div class="card">
  <div class="page-head tight-head">
    <h3>🏛 Barangay Officials</h3>
    <div class="button-row"> <button class="btn sm" data-open="addCollector" type="button">+ Create Collector Account</button> <button class="btn primary sm" data-open="addOfficial" type="button">+ Create Official Account</button> </div>
  </div>
  <p class="sub no-top-margin">Exactly one active official account is allowed per barangay.</p>
  <div class="table-wrap table-flat">
    <table>
      <thead>
        <tr>
          <th>Barangay</th>
          <th>Official</th>
          <th>Email</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        @foreach($barangays as $barangay)
          <tr>
            <td>{{ $barangay->name }}</td>
            <td>@if($barangay->official){{ $barangay->official->name }}@else — @endif</td>
            <td>@if($barangay->official){{ $barangay->official->email }}@else — @endif</td>
            <td class="cell-right"> @if($barangay->official && $barangay->official->is_active) <span class="badge badge-Active">Assigned</span> @elseif($barangay->official) <span class="badge badge-Inactive">Deactivated</span> @else <span class="badge badge-Inactive">No official yet</span> @endif </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
</div>
<br>
<div class="grid grid-two align-start">
  <div class="card">
    <h3>👥 All System Users</h3>
    <div class="table-wrap table-flat">
      <table>
        <thead>
          <tr>
            <th>Name</th>
            <th>Email</th>
            <th>Role</th>
            <th>Account</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          @foreach($users as $user)
            <tr>
              <td>{{ $user->name }}</td>
              <td>{{ $user->email }}</td>
              <td><span class="badge badge-role">{{ $user->roleLabel() }}</span></td>
              <td> @if($user->is_active) <span class="badge badge-Active">Active</span> @else <span class="badge badge-Inactive">Deactivated</span> @endif </td>
              <td>
                @if($user->role !== 'cgso')
                  <div class="actions">
                    <button class="btn sm icon" data-open="editUser{{ $user->id }}" type="button" title="Edit" aria-label="Edit {{ $user->name }}">✎</button> <button class="btn sm icon" data-open="resetUser{{ $user->id }}" type="button" title="Reset password" aria-label="Reset password of {{ $user->name }}">🔑</button>
                    @if($user->is_active)
                      <form method="POST" action="{{ route('cgso.users.deactivate', $user) }}" data-confirm="Deactivate {{ $user->name }}? The user can no longer log in."> @csrf @method('PATCH') <button class="btn sm icon" title="Deactivate" aria-label="Deactivate {{ $user->name }}">⏸</button> </form>
                    @else
                      <form method="POST" action="{{ route('cgso.users.activate', $user) }}"> @csrf @method('PATCH') <button class="btn sm icon" title="Activate" aria-label="Activate {{ $user->name }}">▶</button> </form>
                    @endif
                    <form method="POST" action="{{ route('cgso.users.destroy', $user) }}" data-confirm="Delete {{ $user->name }}? Only accounts without any history can be deleted."> @csrf @method('DELETE') <button class="btn sm danger icon" title="Delete" aria-label="Delete {{ $user->name }}">🗑</button> </form>
                  </div>
                @endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    {{ $users->links() }}
  </div>
  <div class="card">
    <h3>📍 Barangays</h3>
    <form method="POST" action="{{ route('cgso.barangays.store') }}" class="barangay-add-form"> @csrf <input type="text" name="name" placeholder="Add barangay" aria-label="New barangay name" required> <button class="btn primary sm">Add</button> </form>
    <div class="legend">
      @foreach($barangays as $barangay)
        <span class="pill">
          {{ $barangay->name }} <a href="#" data-open="renB{{ $barangay->id }}" class="plain-link" title="Rename" aria-label="Rename {{ $barangay->name }}">✎</a>
          <form method="POST" action="{{ route('cgso.barangays.destroy', $barangay) }}" class="inline-form" data-confirm="Remove barangay {{ $barangay->name }}?"> @csrf @method('DELETE') <button class="remove-button" title="Remove" aria-label="Remove {{ $barangay->name }}">✕</button> </form>
        </span>
      @endforeach
    </div>
  </div>
</div>
{{-- Rename barangay --}}
@foreach($barangays as $barangay)
  <div class="modal-bg" id="renB{{ $barangay->id }}">
    <div class="modal">
      <button class="close" data-close type="button" aria-label="Close">&times;</button>
      <h2>Rename Barangay</h2>
      <form method="POST" action="{{ route('cgso.barangays.update', $barangay) }}">
        @csrf @method('PUT')
        <div class="field"> <label for="barangay-name-{{ $barangay->id }}">Barangay name</label> <input type="text" id="barangay-name-{{ $barangay->id }}" name="name" value="{{ $barangay->name }}" required> </div>
        <div class="cell-right"><button class="btn primary">Save</button></div>
      </form>
    </div>
  </div>
@endforeach
{{-- Edit user and reset password (one pair of modals for every user on this page) --}}
@foreach($users as $user)
  @if($user->role !== 'cgso')
    @php
      $editIsOpen = (old('_form') === 'u' . $user->id);
      $resetIsOpen = (old('_form') === 'r' . $user->id);
      $nameValue = $user->name;
      $emailValue = $user->email;
      $phoneValue = $user->phone;
      $barangayValue = $user->barangay_id;
      if ($editIsOpen) {
          $nameValue = old_text('name');
          $emailValue = old_text('email');
          $phoneValue = old_text('phone');
          $barangayValue = old_text('barangay_id');
      }
    @endphp
    <div class="modal-bg @if($editIsOpen) open @endif" id="editUser{{ $user->id }}">
      <div class="modal">
        <button class="close" data-close type="button" aria-label="Close">&times;</button>
        <h2>Edit {{ $user->name }}</h2>
        @include('partials.modal-errors', ['show' => $editIsOpen])
        <form method="POST" action="{{ route('cgso.users.update', $user) }}">
          @csrf @method('PUT') <input type="hidden" name="_form" value="u{{ $user->id }}">
          <div class="field"><label for="user-name-{{ $user->id }}">Full name</label><input type="text" id="user-name-{{ $user->id }}" name="name" value="{{ $nameValue }}" required></div>
          <div class="field"><label for="user-email-{{ $user->id }}">Email</label><input type="email" id="user-email-{{ $user->id }}" name="email" value="{{ $emailValue }}" required></div>
          <div class="field"><label for="user-phone-{{ $user->id }}">Phone</label><input type="text" id="user-phone-{{ $user->id }}" name="phone" value="{{ $phoneValue }}"></div>
          @if($user->role === 'resident' || $user->role === 'official')
            <div class="field">
              <label for="user-barangay-{{ $user->id }}">Barangay</label>
              <select id="user-barangay-{{ $user->id }}" name="barangay_id" required>
                @foreach($barangays as $barangay)
                  <option value="{{ $barangay->id }}" @selected($barangayValue == $barangay->id)>{{ $barangay->name }}</option>
                @endforeach
              </select>
            </div>
          @endif
          <p class="sub">Role: {{ $user->roleLabel() }} (the role cannot be changed).</p>
          <div class="cell-right"><button class="btn primary">Save Changes</button></div>
        </form>
      </div>
    </div>
    <div class="modal-bg @if($resetIsOpen) open @endif" id="resetUser{{ $user->id }}">
      <div class="modal">
        <button class="close" data-close type="button" aria-label="Close">&times;</button>
        <h2>Reset password — {{ $user->name }}</h2>
        @include('partials.modal-errors', ['show' => $resetIsOpen])
        <form method="POST" action="{{ route('cgso.users.reset-password', $user) }}">
          @csrf <input type="hidden" name="_form" value="r{{ $user->id }}">
          <div class="field"> <label for="temp-password-{{ $user->id }}">Temporary password (8+ characters)</label> <input type="password" id="temp-password-{{ $user->id }}" name="password" minlength="8" autocomplete="new-password" required> <small class="sub">Give this password to the user in person. The user must choose a new password at the next login, and is logged out everywhere now.</small> </div>
          <div class="cell-right"><button class="btn primary">Set Temporary Password</button></div>
        </form>
      </div>
    </div>
  @endif
@endforeach
{{-- Create accounts --}}
<div class="modal-bg @if(old('_form') === 'official') open @endif" id="addOfficial">
  <div class="modal">
    <button class="close" data-close type="button" aria-label="Close">&times;</button>
    <h2>Create Account — Brgy. Official</h2>
    @include('partials.modal-errors', ['show' => old('_form') === 'official'])
    <form method="POST" action="{{ route('cgso.officials.store') }}">
      @csrf <input type="hidden" name="_form" value="official">
      <div class="field">
        <label for="official-barangay">Barangay</label>
        <select id="official-barangay" name="barangay_id" required>
          @foreach($barangays as $barangay)
            @if(! $barangay->official || ! $barangay->official->is_active)
              <option value="{{ $barangay->id }}" @selected(old_text('barangay_id') == $barangay->id)>{{ $barangay->name }}</option>
            @endif
          @endforeach
        </select>
        <small class="sub">Only barangays without an active official are shown.</small>
      </div>
      <div class="field"><label for="official-name">Full Name</label><input type="text" id="official-name" name="name" value="{{ old_text('name') }}" required></div>
      <div class="field"><label for="official-email">Email</label><input type="email" id="official-email" name="email" value="{{ old_text('email') }}" required></div>
      <div class="field"><label for="official-password">First password (8+ characters)</label><input type="password" id="official-password" name="password" minlength="8" autocomplete="new-password" required></div>
      <div class="cell-right"><button class="btn primary">Create Account</button></div>
    </form>
  </div>
</div>
<div class="modal-bg @if(old('_form') === 'collector') open @endif" id="addCollector">
  <div class="modal">
    <button class="close" data-close type="button" aria-label="Close">&times;</button>
    <h2>Create Account — Collector</h2>
    @include('partials.modal-errors', ['show' => old('_form') === 'collector'])
    <form method="POST" action="{{ route('cgso.collectors.store') }}">
      @csrf <input type="hidden" name="_form" value="collector">
      <div class="field"><label for="collector-name">Full Name</label><input type="text" id="collector-name" name="name" value="{{ old_text('name') }}" required></div>
      <div class="field"><label for="collector-email">Email</label><input type="email" id="collector-email" name="email" value="{{ old_text('email') }}" required></div>
      <div class="two-columns">
        <div class="field"><label for="collector-phone">Phone</label><input type="text" id="collector-phone" name="phone" value="{{ old_text('phone') }}"></div>
        <div class="field"><label for="collector-password">First password (8+ characters)</label><input type="password" id="collector-password" name="password" minlength="8" autocomplete="new-password" required></div>
      </div>
      <div class="cell-right"><button class="btn primary">Create Account</button></div>
    </form>
  </div>
</div>
@endsection
