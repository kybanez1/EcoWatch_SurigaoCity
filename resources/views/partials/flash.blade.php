@if(session('success'))
  <div class="alert ok">{{ session('success') }}</div>
@endif
@if(session('error'))
  <div class="alert bad" role="alert">{{ session('error') }}</div>
@endif
@if($errors->any())
  <div class="alert bad">
    <b>Please fix the following:</b>
    <ul class="error-list">
      @foreach($errors->all() as $message)
        <li>{{ $message }}</li>
      @endforeach
    </ul>
  </div>
@endif
