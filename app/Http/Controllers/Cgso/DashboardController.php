<?php

namespace App\Http\Controllers\Cgso;

use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Models\Schedule;
use App\Models\Truck;
use App\Models\TruckRoute;
use App\Models\User;

class DashboardController extends Controller
{
    public function index()
    {
        // Active schedules that run today, and how many of them are completely Done.
        $todaySchedules = [];
        $activeSchedules = Schedule::with('route.areas')->where('is_active', true)->get();
        foreach ($activeSchedules as $schedule) {
            if ($schedule->runsToday()) {
                $todaySchedules[] = $schedule;
            }
        }

        $completed = 0;
        foreach ($todaySchedules as $schedule) {
            if (! $schedule->route || $schedule->route->areas->isEmpty()) {
                continue;
            }
            $allDone = true;
            foreach ($schedule->route->areas as $area) {
                if ($area->today_status !== 'Done') {
                    $allDone = false;
                }
            }
            if ($allDone) {
                $completed++;
            }
        }

        $byCategory = Report::selectRaw('category, count(*) as total')->groupBy('category')->pluck('total', 'category');
        $byBarangay = Report::join('barangays', 'barangays.id', '=', 'reports.barangay_id')
            ->selectRaw('barangays.name as name, count(*) as total')
            ->groupBy('barangays.name')
            ->pluck('total', 'name');

        $activeRoutes = TruckRoute::usable()->whereHas('schedules', function ($query) {
            $query->where('is_active', true);
        })->count();

        $stats = [
            'Total Residents' => User::where('role', 'resident')->count(),
            'Total Collectors' => User::where('role', 'collector')->count(),
            'Total Trucks' => Truck::count(),
            'Active Routes' => $activeRoutes,
            "Today's Collections" => count($todaySchedules),
            'Completed Collections' => $completed,
            'Unresolved Reports' => Report::whereNotIn('status', ['Resolved', 'Closed'])->count(),
            'Resolved Reports' => Report::whereIn('status', ['Resolved', 'Closed'])->count(),
        ];

        // Things the CGSO still has to do.
        $needsCollectorCount = Report::whereNull('collector_id')->whereIn('status', ['Assigned', 'In Progress', 'For Verification', 'Reopened'])->count();
        $waitingVerificationCount = Report::where('status', 'For Verification')->count();
        $pendingCount = Report::where('status', 'Pending')->count();
        $feedbackCount = Report::needsFeedbackReview()->count();
        $todoCounts = [
            'pending' => $pendingCount,
            'verification' => $waitingVerificationCount,
            'needsCollector' => $needsCollectorCount,
            'feedback' => $feedbackCount,
        ];

        return view('cgso.dashboard', compact('stats', 'byCategory', 'byBarangay', 'todoCounts'));
    }
}
