<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CollectionNote extends Model
{
    protected $fillable = ['truck_id', 'truck_route_id', 'user_id', 'note', 'photo_path', 'truck_label', 'route_name', 'barangay_name'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function truck()
    {
        return $this->belongsTo(Truck::class);
    }
    public function route()
    {
        return $this->belongsTo(TruckRoute::class, 'truck_route_id');
    }

    /** The truck as it was when the note was written (falls back to the current truck for very old notes). */
    public function getTruckTextAttribute()
    {
        if ($this->truck_label) {
            return $this->truck_label;
        }
        return $this->truck ? $this->truck->label : 'Removed truck';
    }

    /** The route as it was when the note was written. */
    public function getRouteTextAttribute()
    {
        if ($this->route_name) {
            return $this->route_name;
        }
        return $this->route ? $this->route->name : null;
    }
}
