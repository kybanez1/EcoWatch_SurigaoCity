<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportAssignment extends Model
{
    protected $fillable = ['report_id', 'collector_id', 'truck_id', 'assigned_by', 'target_date', 'instructions', 'reason'];

    protected $casts = ['target_date' => 'date'];

    public function collector()
    {
        return $this->belongsTo(User::class, 'collector_id');
    }
    public function truck()
    {
        return $this->belongsTo(Truck::class);
    }
    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
