@extends('layouts.admin')
@section('title', 'Announcements')
@section('content')
<div class="page-head">
  <h1>Announcements</h1>
  <button class="btn primary sm" data-open="addAnn" type="button">+ New Announcement</button>
</div>
<div class="grid grid-two">
  @forelse($announcements as $announcement)
    <div class="card">
      <div class="card-header-row">
        <span class="badge">{{ $announcement->category }}</span>
        <div class="actions">
          <button class="btn sm icon" data-open="editAnn{{ $announcement->id }}" type="button" title="Edit" aria-label="Edit announcement">✎</button>
          <form method="POST" action="{{ route('cgso.announcements.destroy', $announcement) }}" data-confirm="Delete this announcement?"> @csrf @method('DELETE') <button class="btn sm danger icon" title="Delete" aria-label="Delete announcement">🗑</button> </form>
        </div>
      </div>
      <h3 class="announcement-title">{{ $announcement->title }}</h3>
      <div class="sub announcement-date">{{ $announcement->published_on->format('Y-m-d') }}</div>
      <p class="no-margin">{{ $announcement->body }}</p>
    </div>
  @empty
    <div class="card empty">No announcements yet.</div>
  @endforelse
</div>
{{ $announcements->links() }}
{{-- Edit modals (one for each announcement on this page) --}}
@foreach($announcements as $announcement)
  @php
    $editIsOpen = (old('_form') === 'a' . $announcement->id);
    $categoryValue = $announcement->category;
    $titleValue = $announcement->title;
    $bodyValue = $announcement->body;
    if ($editIsOpen) {
        $categoryValue = old_text('category');
        $titleValue = old_text('title');
        $bodyValue = old_text('body');
    }
  @endphp
  <div class="modal-bg @if($editIsOpen) open @endif" id="editAnn{{ $announcement->id }}">
    <div class="modal">
      <button class="close" data-close type="button" aria-label="Close">&times;</button>
      <h2>Edit Announcement</h2>
      @include('partials.modal-errors', ['show' => $editIsOpen])
      <form method="POST" action="{{ route('cgso.announcements.update', $announcement) }}">
        @csrf @method('PUT') <input type="hidden" name="_form" value="a{{ $announcement->id }}">
        <div class="field">
          <label for="ann-category-{{ $announcement->id }}">Category</label>
          <select id="ann-category-{{ $announcement->id }}" name="category">
            @foreach(\App\Models\Announcement::CATEGORIES as $category)
              <option @selected($categoryValue === $category)>{{ $category }}</option>
            @endforeach
          </select>
        </div>
        <div class="field"><label for="ann-title-{{ $announcement->id }}">Title</label><input type="text" id="ann-title-{{ $announcement->id }}" name="title" value="{{ $titleValue }}" required></div>
        <div class="field"><label for="ann-body-{{ $announcement->id }}">Message</label><textarea id="ann-body-{{ $announcement->id }}" name="body" rows="4" required>{{ $bodyValue }}</textarea></div>
        <div class="cell-right"><button class="btn primary">Save Changes</button></div>
      </form>
    </div>
  </div>
@endforeach
<div class="modal-bg @if(old('_form') === 'newAnn') open @endif" id="addAnn">
  <div class="modal">
    <button class="close" data-close type="button" aria-label="Close">&times;</button>
    <h2>New Announcement</h2>
    @include('partials.modal-errors', ['show' => old('_form') === 'newAnn'])
    <form method="POST" action="{{ route('cgso.announcements.store') }}">
      @csrf <input type="hidden" name="_form" value="newAnn">
      <div class="field">
        <label for="new-ann-category">Category</label>
        <select id="new-ann-category" name="category">
          @foreach(\App\Models\Announcement::CATEGORIES as $category)
            <option @selected(old_text('category') === $category)>{{ $category }}</option>
          @endforeach
        </select>
      </div>
      <div class="field"><label for="new-ann-title">Title</label><input type="text" id="new-ann-title" name="title" value="{{ old_text('title') }}" required></div>
      <div class="field"><label for="new-ann-body">Message</label><textarea id="new-ann-body" name="body" rows="4" required>{{ old_text('body') }}</textarea></div>
      <div class="cell-right"><button class="btn primary">Post Announcement</button></div>
    </form>
  </div>
</div>
@endsection
