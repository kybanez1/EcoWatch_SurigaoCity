<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\Barangay;
use App\Models\Schedule;
use App\Models\Truck;
use App\Models\TruckRoute;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class PublicController extends Controller
{
    public function home()
    {
        return view('public.home');
    }

    public function schedule(Request $request)
    {
        // Check the filters first, so wrong input (for example day[]=x) gives a clear message instead of an error.
        $filters = Validator::make($request->query(), [
            'barangay' => 'nullable|integer|min:1',
            'day' => ['nullable', 'string', Rule::in(array_keys(Schedule::DAYS))],
        ], [
            'barangay.integer' => 'Please choose a barangay from the list.',
            'day.in' => 'Please choose a day from the list.',
            'day.string' => 'Please choose a day from the list.',
        ]);
        if ($filters->fails()) {
            return redirect()->route('schedule')->withErrors($filters);
        }

        // Only active schedules whose truck, barangay and route still exist are shown to the public.
        $query = Schedule::usable()->with(['truck', 'barangay', 'route'])
            ->join('barangays', 'barangays.id', '=', 'schedules.barangay_id')
            ->select('schedules.*')
            ->where('schedules.is_active', true)
            ->orderBy('barangays.name');

        if ($request->filled('barangay')) {
            $query->where('schedules.barangay_id', $request->barangay);
        }
        if ($request->filled('day')) {
            $query->whereJsonContains('schedules.days', $request->day);
        }

        $schedules = $query->get();
        // Trucks under maintenance: their collections may be delayed or cancelled.
        $maintenanceTrucks = Truck::where('status', 'Maintenance')->orderBy('truck_code')->get();

        return view('public.schedule', [
            'maintenanceTrucks' => $maintenanceTrucks,
            'schedules' => $schedules,
            'byTruck' => $schedules->groupBy('truck_id'),
            'barangays' => Barangay::orderBy('name')->get(),
        ]);
    }

    public function trucksRoutes()
    {
        $routes = TruckRoute::usable()->with(['truck', 'barangay'])->get();
        $mapRoutes = [];
        foreach ($routes as $route) {
            $mapRoutes[] = $route->toMap();
        }

        // Text lines shown after choosing a barangay: "Truck #01 — Monday, 7:00 AM"
        $scheduleLines = [];
        $activeSchedules = Schedule::usable()->with('truck')->where('is_active', true)->get();
        foreach ($activeSchedules as $schedule) {
            $line = $schedule->truck->label . ' — ' . $schedule->days_label . ', ' . $schedule->pickup_time_label;
            if ($schedule->service_interrupted) {
                $line = $line . ' (service interrupted: truck under maintenance)';
            }
            $scheduleLines[$schedule->barangay_id][] = $line;
        }

        return view('public.trucks-routes', [
            'maintenanceTrucks' => Truck::where('status', 'Maintenance')->orderBy('truck_code')->get(),
            'routes' => $mapRoutes,
            'barangays' => Barangay::orderBy('name')->get(),
            'trucks' => Truck::orderBy('truck_code')->get(),
            'scheduleLines' => $scheduleLines,
        ]);
    }

    public function announcements()
    {
        $announcements = Announcement::orderByDesc('published_on')->orderByDesc('id')->paginate(10)->withQueryString();
        return view('public.announcements', compact('announcements'));
    }
}
