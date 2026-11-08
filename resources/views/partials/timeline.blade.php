<div class="timeline">
  @foreach($report->logs as $log)
    <div><span class="badge {{ $log->badge_class }}">{{ $log->status }}</span>{{ $log->message }}<span class="log-date">{{ $log->logged_on->format('Y-m-d') }}</span></div>
  @endforeach
</div>
