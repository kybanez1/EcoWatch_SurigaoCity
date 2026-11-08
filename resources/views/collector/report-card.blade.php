<div class="item-box spaced">
  <div class="report-head"> <b>{{ $report->code }} — {{ $report->category }}</b> <span class="badge {{ $report->badge_class }}">{{ $report->status }}</span> </div>
  <div class="detail-grid detail-top">
    <div><small>Barangay</small>{{ $report->barangay->name ?? 'Unknown barangay' }}</div>
    <div><small>Location</small>{{ $report->location_text }}</div>
    <div> <small>Assigned Truck</small> @if($report->truck) {{ $report->truck->label }} @else None @endif </div>
    <div> <small>Target Completion Date</small> @if($report->target_date) {{ $report->target_date->format('Y-m-d') }} @else — @endif </div>
  </div>
  @if($report->description)
    <p><small class="sub">Description</small><br>{{ $report->description }}</p>
  @endif
  @if($report->instructions)
    <p><small class="sub">Administrator Instructions</small><br>{{ $report->instructions }}</p>
  @endif
  @if($report->photo_path)
    <p> <small class="sub">Original photo from the resident</small><br> <img class="photo" src="{{ asset($report->photo_path) }}" alt="Original report photo"> </p>
  @endif
  @if($report->status === 'Assigned')
    <form method="POST" action="{{ route('collector.reports.start', $report) }}"> @csrf <button class="btn primary">▶ Start Cleanup</button> <small class="sub">Click this when you begin cleaning. You will then upload before and after photos.</small> </form>
  @elseif($report->status === 'Reopened')
    <div class="alert bad"> <b>The CGSO Administrator reopened this report.</b><br> Reason: {{ $report->rejection_reason }} </div>
    <form method="POST" action="{{ route('collector.reports.start', $report) }}"> @csrf <button class="btn primary">▶ Resume Cleanup</button> <small class="sub">Correct the cleanup, then upload new before and after photos.</small> </form>
  @elseif($report->status === 'In Progress')
    <form method="POST" action="{{ route('collector.reports.evidence', $report) }}" enctype="multipart/form-data">
      @csrf
      <div class="two-columns">
        <div class="field"> <label for="before-{{ $report->id }}">Before Photo</label> <input type="file" id="before-{{ $report->id }}" name="before_photo" accept="image/jpeg,image/png,image/webp" required> <small class="sub">Upload a photo before starting cleanup.</small> </div>
        <div class="field"> <label for="after-{{ $report->id }}">After Photo</label> <input type="file" id="after-{{ $report->id }}" name="after_photo" accept="image/jpeg,image/png,image/webp" required> <small class="sub">Upload a photo after cleanup.</small> </div>
      </div>
      <div class="field"> <label for="remarks-{{ $report->id }}">Remarks (optional)</label> <input type="text" id="remarks-{{ $report->id }}" name="remarks" maxlength="500" placeholder="Anything the administrator should know"> </div>
      <button class="btn primary">Submit for Verification</button> <small class="sub">Send your cleanup evidence to the administrator for review.</small>
    </form>
  @elseif($report->status === 'For Verification')
    <div class="alert ok">Waiting for the CGSO Administrator to verify your cleanup.</div>
    @if($report->before_photo && $report->after_photo)
      <div class="two-columns">
        <div><small>Before</small><br><img class="photo" src="{{ asset($report->before_photo) }}" alt="Before photo"></div>
        <div><small>After</small><br><img class="photo" src="{{ asset($report->after_photo) }}" alt="After photo"></div>
      </div>
    @endif
  @endif
</div>
