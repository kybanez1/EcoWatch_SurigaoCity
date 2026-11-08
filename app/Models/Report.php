<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Report extends Model
{
    public const CATEGORIES = ['Overflowing Garbage', 'Illegal Dumping', 'Missed Collection', 'Uncollected Garbage', 'Missing Bin / Sack'];
    public const STATUSES = ['Pending', 'Assigned', 'In Progress', 'For Verification', 'Resolved', 'Reopened', 'Closed', 'Not Resolved'];

    // Allowed status changes: current status => statuses it can move to.
    public const NEXT_STATUSES = [
        'Pending' => ['Assigned'],
        'Assigned' => ['In Progress'],
        'In Progress' => ['For Verification'],
        'For Verification' => ['Resolved', 'Reopened'],
        'Reopened' => ['In Progress'],
        'Resolved' => ['Reopened', 'Closed', 'Not Resolved'], // the CGSO reopens it, or the resident confirms and it is Closed
        'Not Resolved' => ['Reopened', 'Closed'], // resident said the problem is still there
        'Closed' => [], // final status
    ];

    protected $fillable = [
        'code',
        'category',
        'barangay_id',
        'user_id',
        'location_text',
        'description',
        'lat',
        'lng',
        'photo_path',
        'before_photo',
        'after_photo',
        'status',
        'assigned_truck_id',
        'collector_id',
        'target_date',
        'instructions',
        'assigned_at',
        'started_at',
        'submitted_at',
        'verified_at',
        'verified_by',
        'collector_remarks',
        'rejection_reason',
        'rejected_at',
        'resident_feedback',
        'feedback_at',
        'submission_token',
        'feedback_review_id',
        'feedback_reviewed_at',
        'feedback_followup_note',
    ];

    protected $casts = [
        'target_date' => 'date',
        'assigned_at' => 'datetime',
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
        'verified_at' => 'datetime',
        'rejected_at' => 'datetime',
        'feedback_at' => 'datetime',
        'feedback_reviewed_at' => 'datetime',
    ];

    public function barangay()
    {
        return $this->belongsTo(Barangay::class);
    }
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function truck()
    {
        return $this->belongsTo(Truck::class, 'assigned_truck_id');
    }
    public function collector()
    {
        return $this->belongsTo(User::class, 'collector_id');
    }
    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
    public function logs()
    {
        return $this->hasMany(ReportLog::class)->orderBy('id');
    }
    public function assignments()
    {
        return $this->hasMany(ReportAssignment::class)->orderBy('id');
    }
    public function reviews()
    {
        return $this->hasMany(ReportReview::class)->orderBy('id');
    }

    /** The newest cleanup submission of this report (collector evidence). */
    public function latestSubmission()
    {
        // The newest review row whose action is "Submitted" (the constraint must be inside ofMany).
        return $this->hasOne(ReportReview::class)->ofMany(['id' => 'max'], function ($query) {
            $query->where('action', 'Submitted');
        });
    }

    /**
     * "Not Resolved" feedback the CGSO has not reviewed yet, about the newest cleanup.
     * Older reports that never had a saved cleanup submission count too.
     * Feedback written about an older cleanup does not count once a newer cleanup was submitted.
     */
    public function scopeNeedsFeedbackReview($query)
    {
        return $query->where('resident_feedback', 'Not Resolved')
            ->whereNull('feedback_reviewed_at')
            ->where(function ($inner) {
                $inner->whereRaw("feedback_review_id = (select max(id) from report_reviews where report_reviews.report_id = reports.id and report_reviews.action = 'Submitted')")
                    ->orWhere(function ($old) {
                        $old->whereNull('feedback_review_id')
                            ->whereRaw("not exists (select 1 from report_reviews where report_reviews.report_id = reports.id and report_reviews.action = 'Submitted')");
                    });
            });
    }

    /**
     * Resident feedback is only valid for the cleanup attempt it was written about.
     * After a new cleanup is submitted, older feedback is no longer shown as current.
     */
    public function getCurrentFeedbackAttribute()
    {
        if (! $this->resident_feedback) {
            return null;
        }
        $latest = $this->latestSubmission;
        // Older reports have no saved cleanup submission. Their feedback counts as current.
        if (! $latest && ! $this->feedback_review_id) {
            return $this->resident_feedback;
        }
        if (! $this->feedback_review_id) {
            return null;
        }
        if (! $latest || (int) $latest->id !== (int) $this->feedback_review_id) {
            return null;
        }
        return $this->resident_feedback;
    }

    /**
     * True when the CGSO may choose or change the collector now:
     * before the cleanup starts, or after the CGSO rejected the cleanup. Never while it is In Progress,
     * except for an old In Progress report that has no collector yet.
     */
    public function canBeAssigned()
    {
        if (in_array($this->status, ['Pending', 'Assigned', 'Reopened'])) {
            return true;
        }
        return $this->status === 'In Progress' && $this->collector_id === null;
    }

    /** True for older reports that are in progress but have no responsible collector. */
    public function getNeedsCollectorAttribute()
    {
        $statuses = ['Assigned', 'In Progress', 'For Verification', 'Reopened'];
        return $this->collector_id === null && in_array($this->status, $statuses);
    }

    /** CSS class of the status badge, for example badge-ForVerification. */
    public function getBadgeClassAttribute()
    {
        return 'badge-' . str_replace(' ', '', $this->status);
    }

    /** True when the report may move from its current status to $newStatus. */
    public function canChangeTo($newStatus)
    {
        if (! array_key_exists($this->status, self::NEXT_STATUSES)) {
            return false;
        }
        return in_array($newStatus, self::NEXT_STATUSES[$this->status]);
    }

    public function addLog($status, $message)
    {
        $this->logs()->create(['status' => $status, 'message' => $message, 'logged_on' => now()->toDateString()]);
    }
}
