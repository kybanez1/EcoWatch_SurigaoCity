<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    public const CATEGORIES = ['Schedule Change', 'Truck Maintenance', 'Cleanup Drive', 'General Notice'];

    protected $fillable = ['category', 'title', 'body', 'published_on'];

    protected $casts = ['published_on' => 'date'];
}
