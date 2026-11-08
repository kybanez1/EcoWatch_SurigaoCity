<?php

namespace App\Http\Controllers\Cgso;

use App\Http\Controllers\Controller;
use App\Models\Barangay;
use App\Models\Schedule;
use App\Models\Truck;
use App\Models\TruckRoute;
use App\Support\FormInput;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * A route is the drawn path + its streets. It can have several schedules (see the Schedules page).
 * This page creates the first schedule of a new route and keeps all linked schedules in step
 * when the route's truck or barangay changes.
 */
class RouteAssignmentController extends Controller
{
    private const MAX_POINTS = 500;
    private const MAX_STREETS = 50;

    public function index(?TruckRoute $route = null)
    {
        // Every route is listed (so the CGSO can fix the ones that need a truck), but only valid routes are drawn on the map.
        $routes = TruckRoute::with(['truck.collector', 'barangay', 'schedules', 'areas'])->get();
        if ($route) {
            $route->load(['areas', 'schedules.barangay']);
        }

        $mapRoutes = [];
        foreach ($routes as $oneRoute) {
            if ($oneRoute->is_usable) {
                $mapRoutes[] = $oneRoute->toMap();
            }
        }

        // Values for the drawing form. After a validation error the typed values come back (cleaned, so odd input cannot break the page).
        $pageData = ['routes' => $mapRoutes, 'currentId' => null, 'path' => [], 'streets' => []];
        if (session()->hasOldInput('path')) {
            $pageData['path'] = FormInput::path(old('path'));
        } elseif ($route && is_array($route->path)) {
            $pageData['path'] = FormInput::path(json_encode($route->path));
        }
        if (session()->hasOldInput('streets')) {
            $pageData['streets'] = FormInput::streets(old('streets'));
        } elseif ($route) {
            $pageData['streets'] = $route->areas->pluck('name')->all();
        }
        if ($route) {
            $pageData['currentId'] = $route->id;
        }

        return view('cgso.routes', [
            'route' => $route,
            'routes' => $routes,
            'pageData' => $pageData,
            'trucks' => Truck::with('collector')->orderBy('truck_code')->get(),
            'barangays' => Barangay::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $route = $this->save($request, new TruckRoute);
        return redirect()->route('cgso.routes.edit', $route)->with('success', $route->name . ' saved and linked to its schedule.');
    }

    public function update(Request $request, TruckRoute $route)
    {
        $this->save($request, $route);
        return redirect()->route('cgso.routes.edit', $route)->with('success', $route->name . ' updated.');
    }

    public function destroy(TruckRoute $route)
    {
        $route->delete();
        return redirect()->route('cgso.routes')->with('success', 'Route deleted. Its schedules were kept without a drawn path.');
    }

    /** Sends the user back to the form with one error message. */
    private function fail($field, $message)
    {
        back()->withErrors([$field => $message])->withInput()->throwResponse();
    }

    /** Reads the drawn path. Every point must be [latitude, longitude] inside the valid ranges. */
    private function readPath($json)
    {
        $points = json_decode($json, true);
        if (! is_array($points)) {
            $this->fail('path', 'The drawn route is not valid. Please draw it again.');
        }
        if (count($points) === 1) {
            $this->fail('path', 'A route needs at least 2 points. Click one more point or clear the route.');
        }
        if (count($points) > self::MAX_POINTS) {
            $this->fail('path', 'The route has too many points (maximum ' . self::MAX_POINTS . ').');
        }

        $cleanPath = [];
        foreach ($points as $point) {
            $isPair = is_array($point) && count($point) === 2 && array_key_exists(0, $point) && array_key_exists(1, $point);
            if (! $isPair || ! is_numeric($point[0]) || ! is_numeric($point[1])) {
                $this->fail('path', 'Every route point must be a latitude and longitude pair.');
            }
            $latitude = (float) $point[0];
            $longitude = (float) $point[1];
            if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
                $this->fail('path', 'A route point is outside the valid latitude/longitude range.');
            }
            $cleanPath[] = [round($latitude, 6), round($longitude, 6)];
        }
        return $cleanPath;
    }

    /** Reads the street names. Names must be text, 1 to 80 characters, and not repeated. */
    private function readStreets($json)
    {
        $names = json_decode($json, true);
        if (! is_array($names)) {
            $this->fail('streets', 'The street list is not valid. Please add the streets again.');
        }
        if (count($names) > self::MAX_STREETS) {
            $this->fail('streets', 'Too many streets (maximum ' . self::MAX_STREETS . ').');
        }

        $cleanNames = [];
        foreach ($names as $name) {
            if (! is_string($name)) {
                $this->fail('streets', 'Every street name must be text.');
            }
            $name = trim($name);
            if ($name === '' || mb_strlen($name) > 80) {
                $this->fail('streets', 'Street names must be 1 to 80 characters long.');
            }
            if (in_array($name, $cleanNames)) {
                $this->fail('streets', 'The street "' . $name . '" is listed twice.');
            }
            $cleanNames[] = $name;
        }
        return $cleanNames;
    }

    private function save(Request $request, TruckRoute $route)
    {
        // A new route (or an old route without any schedule) also needs its first schedule.
        $needsFirstSchedule = true;
        if ($route->exists && $route->schedules()->exists()) {
            $needsFirstSchedule = false;
        }

        // The unique-name rule needs the truck id as a plain number (a broken request may send an array).
        $truckIdForRule = $request->input('truck_id');
        if (! is_scalar($truckIdForRule)) {
            $truckIdForRule = null;
        }

        $rules = [
            'truck_id' => 'required|integer|exists:trucks,id',
            'name' => ['required', 'string', 'max:40', Rule::unique('truck_routes', 'name')->where('truck_id', $truckIdForRule)->ignore($route->id)],
            'barangay_id' => 'required|integer|exists:barangays,id',
            'path' => 'nullable|string|max:60000',
            'streets' => 'nullable|string|max:10000',
        ];
        if ($needsFirstSchedule) {
            $rules['days'] = 'required|array|min:1';
            $rules['days.*'] = 'string|in:' . implode(',', array_keys(Schedule::DAYS));
            $rules['pickup_time'] = 'required|string|date_format:H:i';
        }
        $data = $request->validate($rules, [
            'truck_id.required' => 'Choose a truck for this route.',
            'truck_id.exists' => 'The selected truck no longer exists. Choose another truck.',
            'barangay_id.required' => 'Choose a barangay for this route.',
            'days.required' => 'Pick at least one collection day.',
            'pickup_time.required' => 'Select the scheduled pickup time, such as 7:00 AM.',
        ]);

        // A blank path or street field means "the page did not send it" (for example the map script failed).
        // Then the saved path and streets are kept. Clearing on purpose sends the text [] (the Clear button does this).
        $path = null;
        if (isset($data['path']) && trim($data['path']) !== '') {
            $path = $this->readPath($data['path']);
        } elseif (! $route->exists) {
            $path = [];
        }
        $streets = null;
        if (isset($data['streets']) && trim($data['streets']) !== '') {
            $streets = $this->readStreets($data['streets']);
        } elseif (! $route->exists) {
            $streets = [];
        }

        // Keep the days of the first schedule in Monday-to-Sunday order.
        $orderedDays = [];
        if ($needsFirstSchedule) {
            foreach (array_keys(Schedule::DAYS) as $dayKey) {
                if (in_array($dayKey, $data['days'])) {
                    $orderedDays[] = $dayKey;
                }
            }
            $conflict = Schedule::findConflict($data['truck_id'], $data['pickup_time'], $orderedDays, null);
            if ($conflict) {
                $this->fail('pickup_time', 'This truck already has a schedule at ' . $conflict->pickup_time_label . ' for ' . $conflict->barangay_name . ' on the same day.');
            }
        }

        // If the truck or barangay changes, every linked schedule changes too. Check conflicts BEFORE saving.
        $linkedSchedules = collect();
        if ($route->exists) {
            $linkedSchedules = $route->schedules()->get();
        }
        $truckChanged = $route->exists && $route->truck_id != $data['truck_id'];
        foreach ($linkedSchedules as $linkedSchedule) {
            if ($truckChanged && $linkedSchedule->is_active && $linkedSchedule->pickup_time) {
                $conflict = Schedule::findConflict($data['truck_id'], $linkedSchedule->pickup_time, $linkedSchedule->days, $linkedSchedule->id);
                if ($conflict) {
                    $this->fail('truck_id', 'The new truck already has a schedule at ' . $conflict->pickup_time_label . ' on the same day (' . $conflict->barangay_name . '). The route was not changed.');
                }
            }
        }

        DB::beginTransaction();
        try {
            $route->fill([
                'truck_id' => $data['truck_id'],
                'barangay_id' => $data['barangay_id'],
                'name' => $data['name'],
            ]);
            if ($path !== null) {
                $route->path = $path;
            }
            $route->save();

            // Keep the progress of streets that stay, add new ones, remove deleted ones.
            // When no street list was sent, the saved streets are not touched.
            if ($streets !== null) {
                $route->areas()->whereNotIn('name', $streets)->delete();
                foreach ($streets as $position => $streetName) {
                    $route->areas()->updateOrCreate(['name' => $streetName], ['sort' => $position]);
                }
            }

            // All existing schedules of this route follow the route's truck and barangay.
            foreach ($linkedSchedules as $linkedSchedule) {
                $linkedSchedule->update(['truck_id' => $data['truck_id'], 'barangay_id' => $data['barangay_id']]);
            }

            if ($needsFirstSchedule) {
                Schedule::create([
                    'truck_id' => $data['truck_id'],
                    'barangay_id' => $data['barangay_id'],
                    'truck_route_id' => $route->id,
                    'days' => $orderedDays,
                    'pickup_time' => $data['pickup_time'],
                    'time_range' => null,
                    'is_active' => true,
                ]);
            }
            DB::commit();
        } catch (\Exception $error) {
            DB::rollBack();
            $this->fail('name', 'The route could not be saved. Please try again.');
        }

        return $route;
    }
}
