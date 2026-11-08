<div class="grid grid-two align-start">
  <form class="card" method="POST" action="{{ route('account.profile') }}">
    @csrf @method('PUT')
    <h3>My Details</h3>
    <div class="field"><label for="account-name">Full name</label><input type="text" id="account-name" name="name" value="{{ old_text('name', $user->name) }}" required></div>
    <div class="field"><label for="account-email">Email address</label><input type="email" id="account-email" name="email" value="{{ old_text('email', $user->email) }}" required></div>
    <div class="field"><label for="account-phone">Phone (optional)</label><input type="text" id="account-phone" name="phone" value="{{ old_text('phone', $user->phone) }}"></div>
    @if($user->role === 'resident')
      <div class="field">
        <label for="account-barangay">Barangay</label>
        <select id="account-barangay" name="barangay_id" required>
          @foreach($barangays as $barangay)
            <option value="{{ $barangay->id }}" @selected(old_text('barangay_id', $user->barangay_id) == $barangay->id)>{{ $barangay->name }}</option>
          @endforeach
        </select>
      </div>
    @endif
    <p class="sub no-top-margin">Role: {{ $user->roleLabel() }}</p>
    <button class="btn primary">Save Details</button>
  </form>
  <form class="card" method="POST" action="{{ route('account.password') }}">
    @csrf @method('PUT')
    <h3>Change Password</h3>
    @if($user->must_change_password)
      <div class="alert bad">You are using a temporary password. Please choose your own password now.</div>
    @endif
    <div class="field"><label for="current_password">Current password</label><input type="password" id="current_password" name="current_password" required></div>
    <div class="field"><label for="new_password">New password (8+ characters)</label><input type="password" id="new_password" name="password" minlength="8" required></div>
    <div class="field"><label for="new_password_confirmation">Confirm new password</label><input type="password" id="new_password_confirmation" name="password_confirmation" minlength="8" required></div>
    <button class="btn primary">Change Password</button>
  </form>
</div>
