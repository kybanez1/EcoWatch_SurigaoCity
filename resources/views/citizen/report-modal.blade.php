<div class="modal-bg" id="rep{{ $report->id }}">
  <div class="modal wide">
    <button class="close" data-close type="button">&times;</button>
    <h2>{{ $report->code }} — {{ $report->category }}</h2>
    <p><span class="badge {{ $report->badge_class }}">{{ $report->status }}</span></p>
    <p><b>Location:</b> {{ $report->location_text }}, {{ $report->barangay->name ?? 'Unknown barangay' }}</p>
    @if($report->description)
      <p>{{ $report->description }}</p>
    @endif
    @if($report->user_id !== auth()->id())
      <p class="muted-text">Reported by {{ $report->user->name ?? 'Removed user' }}@if($report->user) ({{ $report->user->roleLabel() }})@endif</p>
    @endif
    @if($report->photo_path)<b>Your Submitted Proof</b><br><img class="photo" src="{{ asset($report->photo_path) }}" alt="proof"><br><br>@endif
    @if($report->collector)
      <p><b>Assigned collector:</b> {{ $report->collector->name }}</p>
    @endif
    @if($report->status === 'Reopened')
      <div class="alert bad"><b>This report was reopened.</b><br>Reason: {{ $report->rejection_reason }}<br>The collector will do the cleanup again and submit it for verification.</div>
    @endif
    <b>Report Progress</b>
    @include('partials.timeline')
    @if($report->status === 'Resolved' || $report->status === 'Closed' || $report->status === 'Not Resolved')
      <div class="alert ok verification-result"><b>Cleanup verified by the CGSO.</b> This report was resolved @if($report->verified_at) on {{ $report->verified_at->format('Y-m-d') }}@endif.</div>
      @if($report->before_photo && $report->after_photo)
        <b>Before / After (uploaded by the collector)</b>
        <div class="two-columns photo-row">
          <div><small>Before</small><br><img class="photo" src="{{ asset($report->before_photo) }}" alt="before"></div>
          <div><small>After</small><br><img class="photo" src="{{ asset($report->after_photo) }}" alt="after"></div>
        </div>
      @endif
    @endif
    @if($report->status === 'For Verification')
      <div class="alert ok verification-result">The collector finished the cleanup. The CGSO Administrator is now verifying it.</div>
    @endif
    @if($report->status === 'Not Resolved')
      <div class="alert bad verification-result"><b>Not Resolved.</b> The CGSO Administrator will review this report and may send the collector back.</div>
    @endif
    @if($report->status === 'Closed')
      <div class="alert ok verification-result"><b>This report is closed.</b> Thank you!</div>
    @endif
    {{-- Only after the CGSO approved the cleanup (Resolved), a "Yes" from the resident closes the report. --}}
    @if($report->user_id === auth()->id() && ($report->status === 'For Verification' || $report->status === 'Resolved' || $report->status === 'Not Resolved'))
      <br><b>Has your reported garbage problem been resolved?</b>
      <p class="sub no-top-margin">Your answer is feedback for the CGSO. It does not change the report status: the CGSO Administrator decides whether the report is resolved or reopened.</p>
      @if($report->current_feedback)
        <p>Your feedback for this cleanup: <b>{{ $report->current_feedback }}</b></p>
        @if($report->feedback_followup_note)
          <p class="sub">CGSO follow-up: {{ $report->feedback_followup_note }}</p>
        @endif
      @endif
      <div class="button-row">
        <form method="POST" action="{{ route(auth()->user()->role.'.reports.feedback', $report) }}">@csrf<input type="hidden" name="resolved" value="yes"><button class="btn primary sm">✓ Yes, it is resolved</button></form>
        <form method="POST" action="{{ route(auth()->user()->role.'.reports.feedback', $report) }}">@csrf<input type="hidden" name="resolved" value="no"><button class="btn danger sm">✕ No, Still Not Resolved</button></form>
      </div>
    @endif
  </div>
</div>
