<?php

namespace App\Http\Controllers\Cgso;

use App\Http\Controllers\Controller;
use App\Models\Barangay;
use App\Models\Schedule;
use App\Models\Truck;
use App\Models\TruckRoute;
use Illuminate\Http\Request;

class ScheduleController extends Controller
{
    public function index()
    {
        // A left join keeps schedules whose truck was removed, so the CGSO can see and fix them.
        $schedules = Schedule::with(['truck', 'barangay', 'route.barangay'])
            ->leftJoin('trucks', 'trucks.id', '=', 'schedules.truck_id')
            ->select('schedules.*')
            ->orderBy('trucks.truck_code')
            ->orderBy('schedules.id')
            ->get();

        $needsTimeCount = 0;
        foreach ($schedules as $schedule) {
            if ($schedule->needs_pickup_time) {
                $needsTimeCount++;
            }
        }

        return view('cgso.schedules', [
            'schedules' => $schedules,
            'newSchedule' => new Schedule,
            'byTruck' => $schedules->groupBy('truck_id'),
            'needsTimeCount' => $needsTimeCount,
            'trucks' => Truck::with('collector')->orderBy('truck_code')->get(),
            'barangays' => Barangay::orderBy('name')->get(),
            // Only valid routes can be linked.
            'routes' => TruckRoute::usable()->with(['truck', 'barangay'])->get(),
        ]);
    }

    /** Validates the form. Returns the clean data, or sends the user back with an error message. */
    private function validateSchedule(Request $request, $scheduleId = null, $isActive = true)
    {
        $data = $request->validate([
            'truck_id' => 'required|integer|exists:trucks,id',
            'barangay_id' => 'required|integer|exists:barangays,id',
            'truck_route_id' => 'nullable|integer|exists:truck_routes,id',
            'days' => 'required|array|min:1',
            'days.*' => 'string|in:' . implode(',', array_keys(Schedule::DAYS)),
            'pickup_time' => 'required|string|date_format:H:i',
        ], [
            'truck_id.required' => 'Choose a truck for this schedule.',
            'barangay_id.required' => 'Choose a barangay for this schedule.',
            'days.required' => 'Pick at least one collection day.',
            'pickup_time.required' => 'Select the scheduled pickup time, such as 7:00 AM.',
            'pickup_time.date_format' => 'The pickup time must be a valid time.',
        ]);

        // The linked route must belong to the same truck and the same barangay.
        if (! empty($data['truck_route_id'])) {
            $route = TruckRoute::find($data['truck_route_id']);
            if (! $route) {
                back()->withErrors(['truck_route_id' => 'That route no longer exists.'])->withInput()->throwResponse();
            }
            if ($route->truck_id != $data['truck_id']) {
                back()->withErrors(['truck_route_id' => 'That route belongs to a different truck.'])->withInput()->throwResponse();
            }
            if ($route->barangay_id != $data['barangay_id']) {
                back()->withErrors(['truck_route_id' => 'That route belongs to a different barangay.'])->withInput()->throwResponse();
            }
        }

        // Keep the days in Monday-to-Sunday order.
        $orderedDays = [];
        foreach (array_keys(Schedule::DAYS) as $dayKey) {
            if (in_array($dayKey, $data['days'])) {
                $orderedDays[] = $dayKey;
            }
        }
        $data['days'] = $orderedDays;

        // One truck cannot be at two places at the same time.
        if ($isActive) {
            $conflict = Schedule::findConflict($data['truck_id'], $data['pickup_time'], $orderedDays, $scheduleId);
            if ($conflict) {
                $message = 'This truck already has a schedule at ' . $conflict->pickup_time_label . ' for ' . $conflict->barangay_name . ' on the same day.';
                back()->withErrors(['pickup_time' => $message])->withInput()->throwResponse();
            }
        }

        // The old time range is not needed anymore once an exact time is saved.
        $data['time_range'] = null;
        return $data;
    }

    public function store(Request $request)
    {
        $data = $this->validateSchedule($request);
        $data['is_active'] = true;
        Schedule::create($data);

        $truck = Truck::find($data['truck_id']);
        if ($truck->status === 'Maintenance') {
            return back()->with('success', 'Schedule added. Note: ' . $truck->label . ' is under maintenance, so the public sees "Service interrupted" until the truck is Available.');
        }
        return back()->with('success', 'Schedule added.');
    }

    public function update(Request $request, Schedule $schedule)
    {
        $data = $this->validateSchedule($request, $schedule->id, $schedule->is_active);
        $schedule->update($data);
        return back()->with('success', 'Schedule updated.');
    }

    public function toggle(Schedule $schedule)
    {
        $willBeActive = ! $schedule->is_active;

        if ($willBeActive) {
            if ($schedule->needs_pickup_time) {
                return back()->withErrors(['pickup_time' => 'Set an exact pickup time before activating this schedule.']);
            }
            $conflict = Schedule::findConflict($schedule->truck_id, $schedule->pickup_time, $schedule->days, $schedule->id);
            if ($conflict) {
                return back()->withErrors(['pickup_time' => 'Another active schedule of this truck already uses the same time and day.']);
            }
        }

        $schedule->update(['is_active' => $willBeActive]);
        if ($willBeActive) {
            return back()->with('success', 'Schedule is now active.');
        }
        return back()->with('success', 'Schedule is now inactive.');
    }

    public function destroy(Schedule $schedule)
    {
        $schedule->delete();
        return back()->with('success', 'Schedule deleted.');
    }
}
