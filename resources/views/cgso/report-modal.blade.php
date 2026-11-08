@php
  // Keep what the administrator typed only for the report whose form had an error.
  $formIsOpen = (session('open') == $report->id);
  $selectedCollector = $report->collector_id;
  $selectedTruck = $report->assigned_truck_id;
  $targetDate = '';
  if ($report->target_date) {
      $targetDate = $report->target_date->format('Y-m-d');
  }
  $instructions = $report->instructions;
  $reasonValue = '';
  $rejectionValue = '';
  $reopenValue = '';
  $followupValue = '';
  if ($formIsOpen) {
      if (old_text('collector_id')) {
          $selectedCollector = old_text('collector_id');
          $selectedTruck = old_text('assigned_truck_id');
          $targetDate = old_text('target_date');
          $instructions = old_text('instructions');
      }
      $reasonValue = old_text('reason');
      $rejectionValue = old_text('rejection_reason');
      $reopenValue = old_text('reopen_reason');
      $followupValue = old_text('followup_note');
  }
  $canAssign = $report->canBeAssigned();
  $currentFeedback = $report->current_feedback;
@endphp
<div class="modal-bg @if($formIsOpen) open @endif" id="rep{{ $report->id }}">
  <div class="modal wide">
    <button class="close" data-close type="button" aria-label="Close">&times;</button>
    <h2>{{ $report->code }} — {{ $report->category }}</h2>
    @include('partials.modal-errors', ['show' => $formIsOpen])
    @if($formIsOpen && session('success'))
      <div class="alert ok" role="status">{{ session('success') }}</div>
    @endif
    <p><span class="badge {{ $report->badge_class }}">{{ $report->status }}</span></p>
    @if($report->status === 'Reopened')
      <div class="notice-box">
        <b>What happens next:</b>
        @if($report->collector)
          {{ $report->collector->name }} sees this report in "My Assigned Reports" with a "Resume Cleanup" button. After the new cleanup photos are sent, the report returns to "For Verification" and you can approve or reject it again.
        @else
          No collector is assigned. Assign one below.
        @endif
      </div>
    @elseif($report->status === 'Not Resolved')
      <div class="notice-box"><b>What to do:</b> The resident says the problem is still there. Write a follow-up note, or use "Reopen this Report" to send the collector back to clean again.</div>
    @endif
    <p> <b>Location:</b> {{ $report->location_text }}, {{ $report->barangay->name ?? 'Unknown barangay' }}<br> <b>Reported by:</b> {{ $report->user->name ?? 'Removed user' }}@if($report->user) ({{ $report->user->roleLabel() }})@endif<br> <b>Submitted:</b> {{ $report->created_at->format('Y-m-d g:i A') }} </p>
    @if($report->description)
      <p>{{ $report->description }}</p>
    @endif
    @if($report->photo_path) <b>Original Photo Submitted by the Reporter</b><br> <img class="photo" src="{{ asset($report->photo_path) }}" alt="Original report photo"><br><br> @endif
    <div class="detail-grid">
      <div><small>Assigned Collector</small>@if($report->collector){{ $report->collector->name }}@else Not assigned yet @endif</div>
      <div><small>Assigned Truck</small>@if($report->truck){{ $report->truck->label }}@else None @endif</div>
      <div><small>Target Completion Date</small>@if($report->target_date){{ $report->target_date->format('Y-m-d') }}@else — @endif</div>
      <div><small>Resident Feedback (newest cleanup)</small>@if($currentFeedback){{ $currentFeedback }}@else — @endif</div>
    </div>
    @if($report->instructions)
      <p><small class="sub">Instructions</small><br>{{ $report->instructions }}</p>
    @endif
    @if($report->needs_collector)
      <div class="notice-box">This older report has no responsible collector. Assign one below so the work can continue.</div>
    @endif
    @if($report->rejection_reason && $report->status === 'Reopened')
      <div class="alert bad"><b>Reopened / rejected because:</b> {{ $report->rejection_reason }}</div>
    @endif
    {{-- Assign or reassign the responsible collector --}}
    @if($canAssign)
      <hr class="divider">
      <form method="POST" action="{{ route('cgso.reports.assign', $report) }}">
        @csrf
        <h3>@if($report->collector) Reassign Collector @else Assign Collector @endif</h3>
        <div class="two-columns">
          <div class="field">
            <label for="collector-{{ $report->id }}">Responsible Collector</label>
            <select id="collector-{{ $report->id }}" name="collector_id" required>
              <option value="">— Select collector —</option>
              @foreach($collectors as $collector)
                <option value="{{ $collector->id }}" @selected($selectedCollector == $collector->id)>{{ $collector->name }}</option>
              @endforeach
            </select>
            <small class="sub">Select the collector responsible for this report.</small>
          </div>
          <div class="field">
            <label for="truck-{{ $report->id }}">Truck (optional)</label>
            <select id="truck-{{ $report->id }}" name="assigned_truck_id">
              <option value="">— None —</option>
              @foreach($trucks as $truck)
                <option value="{{ $truck->id }}" @selected($selectedTruck == $truck->id)>{{ $truck->select_label }}</option>
              @endforeach
            </select>
            <small class="sub">The truck is separate from the collector.</small>
          </div>
        </div>
        <div class="two-columns">
          <div class="field"> <label for="target-{{ $report->id }}">Target Completion Date (optional)</label> <input type="date" id="target-{{ $report->id }}" name="target_date" value="{{ $targetDate }}"> </div>
          <div class="field"> <label for="instructions-{{ $report->id }}">Instructions (optional)</label> <input type="text" id="instructions-{{ $report->id }}" name="instructions" maxlength="500" value="{{ $instructions }}" placeholder="Anything the collector should know"> </div>
        </div>
        @if($report->collector)
          <div class="field"> <label for="reason-{{ $report->id }}">Reason for changing the collector</label> <input type="text" id="reason-{{ $report->id }}" name="reason" maxlength="255" value="{{ $reasonValue }}" placeholder="Required only when you choose a different collector"> </div>
        @endif
        <div class="cell-right"> <button class="btn primary">@if($report->collector) Save Reassignment @else Assign Collector @endif</button> </div>
      </form>
    @endif
    {{-- Cleanup evidence from the collector --}}
    @if($report->before_photo && $report->after_photo)
      <hr class="divider"> <b>Cleanup Evidence from the Collector</b>
      @if($report->submitted_at)
        <p class="sub no-top-margin">Submitted {{ $report->submitted_at->format('Y-m-d g:i A') }}</p>
      @endif
      <div class="two-columns photo-row">
        <div><small>Before</small><br><img class="photo" src="{{ asset($report->before_photo) }}" alt="Before photo"></div>
        <div><small>After</small><br><img class="photo" src="{{ asset($report->after_photo) }}" alt="After photo"></div>
      </div>
      @if($report->collector_remarks)
        <p><small class="sub">Collector remarks</small><br>{{ $report->collector_remarks }}</p>
      @endif
    @endif
    {{-- Approve or reject the cleanup --}}
    @if($report->status === 'For Verification')
      <hr class="divider">
      <h3>Verify the Cleanup</h3>
      <div class="two-columns">
        <form method="POST" action="{{ route('cgso.reports.approve', $report) }}">
          @csrf
          <div class="field"> <label for="approve-remarks-{{ $report->id }}">Remarks (optional)</label> <input type="text" id="approve-remarks-{{ $report->id }}" name="remarks" maxlength="500"> </div>
          <button class="btn primary">✓ Approve Cleanup</button><br> <small class="sub">Confirm that the cleanup has been completed.</small>
        </form>
        <form method="POST" action="{{ route('cgso.reports.reject', $report) }}">
          @csrf
          <div class="field"> <label for="reject-reason-{{ $report->id }}">Reason for rejection</label> <input type="text" id="reject-reason-{{ $report->id }}" name="rejection_reason" maxlength="255" value="{{ $rejectionValue }}" required> </div>
          <button class="btn danger">✕ Reject Cleanup</button><br> <small class="sub">Explain why additional cleanup is needed.</small>
        </form>
      </div>
    @endif
    {{-- Resident feedback "Not Resolved": review it and/or reopen the report --}}
    @if($currentFeedback === 'Not Resolved' && ! $report->feedback_reviewed_at)
      <hr class="divider">
      <h3>Resident said: "Not Resolved"</h3>
      <form method="POST" action="{{ route('cgso.reports.feedback-review', $report) }}">
        @csrf
        <div class="field"> <label for="followup-{{ $report->id }}">Follow-up note</label> <input type="text" id="followup-{{ $report->id }}" name="followup_note" maxlength="500" value="{{ $followupValue }}" required> <small class="sub">Write what the CGSO checked or decided. This marks the feedback as reviewed.</small> </div>
        <button class="btn">Mark Feedback as Reviewed</button>
      </form>
    @elseif($currentFeedback === 'Not Resolved' && $report->feedback_followup_note)
      <p class="sub">CGSO follow-up: {{ $report->feedback_followup_note }}</p>
    @endif
    @if($report->status === 'Resolved' || $report->status === 'Not Resolved')
      <hr class="divider">
      <form method="POST" action="{{ route('cgso.reports.reopen', $report) }}">
        @csrf
        <h3>Reopen this Report</h3>
        <div class="field"> <label for="reopen-{{ $report->id }}">Reason for reopening</label> <input type="text" id="reopen-{{ $report->id }}" name="reopen_reason" maxlength="255" value="{{ $reopenValue }}" required> <small class="sub">Use this when the problem came back or the resident reported it is not solved. The collector will be asked to clean again.</small> </div>
        <button class="btn danger">↺ Reopen Report</button>
      </form>
    @endif
    {{-- History --}}
    @if($report->assignments->count() > 0)
      <hr class="divider"> <b>Assignment History</b>
      <div class="timeline">
        @foreach($report->assignments as $assignment)
          <div> {{ $assignment->created_at->format('Y-m-d g:i A') }} — {{ $assignment->collector->name ?? 'Removed user' }} @if($assignment->reason)<span class="sub">(Reason: {{ $assignment->reason }})</span>@endif </div>
        @endforeach
      </div>
    @endif
    @if($report->reviews->count() > 0)
      <br> <b>Verification History</b>
      <div class="timeline">
        @foreach($report->reviews as $review)
          <div> <span class="badge badge-{{ $review->action }}">{{ $review->action }}</span> {{ $review->created_at->format('Y-m-d g:i A') }} — {{ $review->user->name ?? 'Removed user' }} @if($review->remarks)<span class="sub">{{ $review->remarks }}</span>@endif @if($review->before_photo && $review->after_photo) <span class="log-date"> <a href="{{ asset($review->before_photo) }}" target="_blank" rel="noopener">Before</a> · <a href="{{ asset($review->after_photo) }}" target="_blank" rel="noopener">After</a> </span> @endif </div>
        @endforeach
      </div>
    @endif
    <br> <b>Report Progress</b>
    @include('partials.timeline')
  </div>
</div>
