<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One row per cleanup submission (Submitted) and per administrator decision (Approved / Rejected). */
class ReportReview extends Model
{
    protected $fillable = ['report_id', 'action', 'user_id', 'remarks', 'before_photo', 'after_photo'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
