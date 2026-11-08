<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportLog extends Model
{
    protected $fillable = ['report_id', 'status', 'message', 'logged_on'];

    protected $casts = ['logged_on' => 'date'];

    /** CSS class of the status badge, for example badge-InProgress. */
    public function getBadgeClassAttribute()
    {
        return 'badge-' . str_replace(' ', '', $this->status);
    }
}
