{{-- Shows the validation errors inside an open modal. Use: @include('partials.modal-errors', ['show' => $formIsOpen]) --}}
@if($show && $errors->any())
  <div class="alert bad" role="alert">
    <b>Please fix the following:</b>
    <ul class="error-list">
      @foreach($errors->all() as $message)
        <li>{{ $message }}</li>
      @endforeach
    </ul>
  </div>
@endif
