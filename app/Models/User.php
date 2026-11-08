<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    public const ROLES = [
        'cgso' => 'CGSO',
        'resident' => 'Resident',
        'official' => 'Barangay Official',
        'collector' => 'Collector',
    ];

    protected $fillable = ['name', 'email', 'password', 'role', 'barangay_id', 'phone', 'is_active', 'must_change_password', 'deactivated_at'];

    // Default values for a new account (the database has the same defaults).
    protected $attributes = ['is_active' => true, 'must_change_password' => false];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_active' => 'boolean',
        'must_change_password' => 'boolean',
        'deactivated_at' => 'datetime',
    ];

    public function barangay() { return $this->belongsTo(Barangay::class); }
    public function truck() { return $this->hasOne(Truck::class, 'collector_id'); }

    public function isRole(...$roles)
    {
        return in_array($this->role, $roles, true);
    }

    public function roleLabel()
    {
        if (array_key_exists($this->role, self::ROLES)) {
            return self::ROLES[$this->role];
        }
        return ucfirst($this->role);
    }

    /** Where each role lands after login. */
    public function homeRoute()
    {
        switch ($this->role) {
            case 'cgso':
                return route('cgso.dashboard');
            case 'official':
                return route('official.dashboard');
            case 'collector':
                return route('collector.dashboard');
            default:
                return route('resident.dashboard');
        }
    }

    /**
     * True when the account owns important records (reports, notes, assignments, verifications).
     * Such accounts are deactivated instead of deleted so the history stays.
     */
    public function hasImportantRecords()
    {
        if (\App\Models\Report::where('user_id', $this->id)->exists()) {
            return true;
        }
        if (\App\Models\Report::where('collector_id', $this->id)->orWhere('verified_by', $this->id)->exists()) {
            return true;
        }
        if (\App\Models\CollectionNote::where('user_id', $this->id)->exists()) {
            return true;
        }
        if (\App\Models\ReportAssignment::where('collector_id', $this->id)->orWhere('assigned_by', $this->id)->exists()) {
            return true;
        }
        if (\App\Models\ReportReview::where('user_id', $this->id)->exists()) {
            return true;
        }
        return false;
    }

    /** Reports of this collector that are not finished yet. */
    public function openReportCount()
    {
        $openStatuses = ['Assigned', 'In Progress', 'For Verification', 'Reopened'];
        return \App\Models\Report::where('collector_id', $this->id)->whereIn('status', $openStatuses)->count();
    }
}
