<?php

namespace App\Models;

use App\Support\FormInput;
use Illuminate\Database\Eloquent\Model;

class TruckRoute extends Model
{
    protected $fillable = ['truck_id', 'barangay_id', 'name', 'path'];

    protected $casts = ['path' => 'array'];

    public function truck() { return $this->belongsTo(Truck::class); }
    public function barangay() { return $this->belongsTo(Barangay::class); }
    public function areas() { return $this->hasMany(CollectionArea::class)->orderBy('sort'); }
    public function schedules() { return $this->hasMany(Schedule::class); }

    /** Routes whose truck and barangay both still exist. Only these go to maps, public pages and dropdowns. */
    public function scopeUsable($query)
    {
        return $query->whereHas('truck')->whereHas('barangay');
    }

    /** Routes that need the CGSO to choose a truck or barangay again. */
    public function scopeNeedingReassignment($query)
    {
        return $query->where(function ($inner) {
            $inner->whereDoesntHave('truck')->orWhereDoesntHave('barangay');
        });
    }

    public function getIsUsableAttribute()
    {
        return $this->truck !== null && $this->barangay !== null;
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

    /** Short text that tells the CGSO what to do, or null when the route is fine. */
    public function getProblemAttribute()
    {
        if (! $this->truck && ! $this->barangay) {
            return 'This route has no truck and no barangay. Open it and choose both.';
        }
        if (! $this->truck) {
            return 'The truck of this route was removed. Open it and choose a replacement truck.';
        }
        if (! $this->barangay) {
            return 'The barangay of this route was removed. Open it and choose a barangay.';
        }
        return null;
    }

    /** Data used by the Leaflet maps. A missing truck or barangay gives safe text instead of an error. */
    public function toMap()
    {
        $path = $this->path;
        if (! is_array($path)) {
            $path = [];
        }
        // Keep only real [latitude, longitude] pairs so a damaged saved path cannot break a map.
        $cleanPath = FormInput::path(json_encode($path));

        return [
            'id' => $this->id,
            'name' => $this->name,
            'truck' => $this->truck_label,
            'truck_id' => $this->truck_id,
            'color' => $this->truck_color,
            'barangay' => $this->barangay_name,
            'barangay_id' => $this->barangay_id,
            'path' => $cleanPath,
        ];
    }
}
