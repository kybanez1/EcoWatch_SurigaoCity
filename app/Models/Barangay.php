<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Barangay extends Model
{
    protected $fillable = ['name'];

    public function users()
    {
        return $this->hasMany(User::class);
    }
    public function schedules()
    {
        return $this->hasMany(Schedule::class);
    }
    public function reports()
    {
        return $this->hasMany(Report::class);
    }
    public function routes()
    {
        return $this->hasMany(TruckRoute::class);
    }
    public function official()
    {
        return $this->hasOne(User::class)->where('role', 'official');
    }
}
