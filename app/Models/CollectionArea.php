<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CollectionArea extends Model
{
    public const STATUSES = ['Pending', 'Collecting', 'Done'];

    // Allowed changes: current status => statuses it can move to.
    public const NEXT_STATUSES = [
        'Pending' => ['Collecting'],
        'Collecting' => ['Done', 'Pending'],
        'Done' => ['Collecting'],
    ];

    protected $fillable = ['truck_route_id', 'name', 'sort', 'status', 'status_date'];

    protected $casts = ['status_date' => 'date'];

    public function route() { return $this->belongsTo(TruckRoute::class, 'truck_route_id'); }

    /** A status only counts for the day it was set; a new day starts as Pending. */
    public function getTodayStatusAttribute()
    {
        if ($this->status_date && $this->status_date->isToday() && in_array($this->status, self::STATUSES)) {
            return $this->status;
        }
        return 'Pending';
    }

    public function canChangeTo($newStatus)
    {
        // An unknown status in an old row counts as Pending (see today_status), never as an error.
        $allowed = self::NEXT_STATUSES[$this->today_status] ?? [];
        return in_array($newStatus, $allowed);
    }
}
