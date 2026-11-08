<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Schedule extends Model
{
    public const DAYS = ['Mon' => 'Monday', 'Tue' => 'Tuesday', 'Wed' => 'Wednesday', 'Thu' => 'Thursday', 'Fri' => 'Friday', 'Sat' => 'Saturday', 'Sun' => 'Sunday'];

    protected $fillable = ['truck_id', 'barangay_id', 'truck_route_id', 'days', 'pickup_time', 'time_range', 'is_active'];

    protected $casts = ['days' => 'array', 'is_active' => 'boolean'];

    public function truck()
    {
        return $this->belongsTo(Truck::class);
    }
    public function barangay()
    {
        return $this->belongsTo(Barangay::class);
    }
    public function route()
    {
        return $this->belongsTo(TruckRoute::class, 'truck_route_id');
    }

    /** Schedules that are safe to show: the truck and barangay exist, and a linked route (if any) is still valid. */
    public function scopeUsable($query)
    {
        return $query->whereHas('truck')->whereHas('barangay')->where(function ($inner) {
            $inner->whereDoesntHave('route')->orWhereHas('route', function ($routeQuery) {
                $routeQuery->whereHas('truck')->whereHas('barangay');
            });
        });
    }

    public function getTruckLabelAttribute()
    {
        return $this->truck ? $this->truck->label : 'No truck assigned';
    }

    public function getTruckColorAttribute()
    {
        return $this->truck ? $this->truck->color : '#868E96';
    }

    public function getBarangayNameAttribute()
    {
        return $this->barangay ? $this->barangay->name : 'No barangay';
    }

    /** True when the CGSO must choose a truck or barangay for this schedule again. */
    public function getNeedsReassignmentAttribute()
    {
        return $this->truck === null || $this->barangay === null;
    }

    public function runsToday()
    {
        $today = now()->format('D');
        $days = $this->days;
        if (! $days) {
            $days = [];
        }
        return in_array($today, $days);
    }

    /** True when the truck is under maintenance, so this collection may be delayed or cancelled. */
    public function getServiceInterruptedAttribute()
    {
        return $this->truck && $this->truck->status === 'Maintenance';
    }

    public function getStatusLabelAttribute()
    {
        if ($this->service_interrupted) {
            return 'Service interrupted';
        }
        return 'Active';
    }

    public function getStatusBadgeClassAttribute()
    {
        if ($this->service_interrupted) {
            return 'badge-Maintenance';
        }
        return 'badge-Active';
    }

    /** True for old schedules that still have no exact pickup time. */
    public function getNeedsPickupTimeAttribute()
    {
        return empty($this->pickup_time);
    }

    /** "07:00:00" becomes "7:00 AM". */
    public function getPickupTimeLabelAttribute()
    {
        if (empty($this->pickup_time)) {
            return 'Time to be set';
        }
        return date('g:i A', strtotime($this->pickup_time));
    }

    /** "Monday, Wednesday, Friday" / "Monday - Saturday" / "Everyday" */
    public function getDaysLabelAttribute()
    {
        $allKeys = array_keys(self::DAYS);
        $savedDays = $this->days;
        if (! $savedDays) {
            $savedDays = [];
        }

        // Keep the days in Monday-to-Sunday order.
        $selected = [];
        $positions = [];
        foreach ($allKeys as $position => $key) {
            if (in_array($key, $savedDays)) {
                $selected[] = $key;
                $positions[] = $position;
            }
        }

        if (count($selected) === 7) {
            return 'Everyday';
        }
        if (count($selected) === 0) {
            return '—';
        }

        $firstPosition = $positions[0];
        $lastPosition = $positions[count($positions) - 1];
        $isOneBlock = ($lastPosition - $firstPosition) === (count($positions) - 1);
        if ($isOneBlock && count($selected) >= 3) {
            return self::DAYS[$selected[0]] . ' - ' . self::DAYS[$selected[count($selected) - 1]];
        }

        $names = [];
        foreach ($selected as $key) {
            $names[] = self::DAYS[$key];
        }
        return implode(', ', $names);
    }

    /**
     * Looks for another ACTIVE schedule of the same truck at the same pickup time on a shared day.
     * Returns the conflicting schedule, or null when there is no conflict.
     */
    public static function findConflict($truckId, $pickupTime, $days, $ignoreId = null)
    {
        $others = self::where('truck_id', $truckId)->where('is_active', true)->whereNotNull('pickup_time')->get();
        foreach ($others as $other) {
            if ($ignoreId && $other->id == $ignoreId) {
                continue;
            }
            if (date('H:i', strtotime($other->pickup_time)) !== date('H:i', strtotime($pickupTime))) {
                continue;
            }
            foreach ($days as $day) {
                if (in_array($day, $other->days ?: [])) {
                    return $other;
                }
            }
        }
        return null;
    }
}
