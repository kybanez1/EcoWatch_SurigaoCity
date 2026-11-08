<?php

namespace App\Http\Controllers;

use App\Models\CollectionArea;
use App\Models\CollectionNote;
use App\Models\Report;
use App\Models\ReportReview;
use App\Models\TruckRoute;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CollectorController extends Controller
{
    /** Ids of the routes this truck must collect today (active schedule that runs today). */
    private function routeIdsScheduledToday($truck)
    {
        $routeIds = [];
        $schedules = $truck->schedules()->where('is_active', true)->whereNotNull('truck_route_id')->get();
        foreach ($schedules as $schedule) {
            if ($schedule->runsToday() && ! in_array($schedule->truck_route_id, $routeIds)) {
                $routeIds[] = $schedule->truck_route_id;
            }
        }
        return $routeIds;
    }

    public function dashboard(Request $request)
    {
        $user = $request->user();
        $truck = $user->truck;

        $schedules = collect();
        $allRoutes = collect();
        $todayRouteIds = [];
        $route = null;
        $routeIsToday = false;
        $notes = null;

        if ($truck) {
            $schedules = $truck->schedules()->with(['barangay', 'route'])->where('is_active', true)->get();
            $allRoutes = $truck->routes()->with('barangay')->orderBy('name')->get();
            $todayRouteIds = $this->routeIdsScheduledToday($truck);

            // The collector can choose any route of the truck with ?route=ID.
            $chosenId = (int) $request->query('route');
            foreach ($allRoutes as $oneRoute) {
                if ($oneRoute->id === $chosenId) {
                    $route = $oneRoute;
                }
            }
            // Without a valid choice, start with a route that is scheduled today.
            if ($route === null) {
                foreach ($allRoutes as $oneRoute) {
                    if ($route === null && in_array($oneRoute->id, $todayRouteIds)) {
                        $route = $oneRoute;
                    }
                }
            }
            // No route today: show the first assigned route, but it is labelled "not scheduled today".
            if ($route === null && $allRoutes->count() > 0) {
                $route = $allRoutes->first();
            }
            if ($route) {
                $route->load(['areas', 'barangay', 'truck']);
                $routeIsToday = in_array($route->id, $todayRouteIds);
            }
            $notes = CollectionNote::with('route')->where('user_id', $user->id)->latest('id')->paginate(8, ['*'], 'notes_page')->withQueryString();
        }

        // Reports assigned to this collector (the person, not the truck). Unfinished reports first.
        $activeReports = Report::with(['barangay', 'truck', 'latestSubmission'])
            ->where('collector_id', $user->id)
            ->whereNotIn('status', ['Resolved', 'Closed'])
            ->latest('id')
            ->get();
        $finishedReports = Report::with(['barangay', 'truck'])
            ->where('collector_id', $user->id)
            ->whereIn('status', ['Resolved', 'Closed'])
            ->latest('id')
            ->take(10)
            ->get();

        $routeMap = null;
        $areaCount = 0;
        $doneCount = 0;
        $progressPercent = 0;
        if ($route) {
            $routeMap = $route->toMap();
            $areaCount = $route->areas->count();
            foreach ($route->areas as $area) {
                if ($area->today_status === 'Done') {
                    $doneCount++;
                }
            }
            if ($areaCount > 0) {
                $progressPercent = round($doneCount / $areaCount * 100);
            }
        }

        return view('collector.dashboard', [
            'truck' => $truck,
            'schedules' => $schedules,
            'allRoutes' => $allRoutes,
            'todayRouteIds' => $todayRouteIds,
            'route' => $route,
            'routeIsToday' => $routeIsToday,
            'routeMap' => $routeMap,
            'areaCount' => $areaCount,
            'doneCount' => $doneCount,
            'progressPercent' => $progressPercent,
            'notes' => $notes,
            'activeReports' => $activeReports,
            'finishedReports' => $finishedReports,
        ]);
    }

    /** Assigned (or Reopened) -> In Progress */
    public function startCleanup(Request $request, Report $report)
    {
        abort_unless($report->collector_id === $request->user()->id, 403);

        DB::beginTransaction();
        try {
            // Lock the row so two requests at the same time cannot both change the status.
            $locked = Report::lockForUpdate()->find($report->id);
            if (! $locked->canChangeTo('In Progress')) {
                DB::rollBack();
                return back()->withErrors(['report' => $report->code . ' cannot be started because its status is ' . $locked->status . '.']);
            }

            $wasReopened = ($locked->status === 'Reopened');
            $locked->status = 'In Progress';
            if (! $wasReopened) {
                $locked->started_at = now();
            }
            $locked->save();

            if ($wasReopened) {
                $locked->addLog('In Progress', 'Collector resumed the cleanup after the report was reopened.');
            } else {
                $locked->addLog('In Progress', 'Collector started the cleanup.');
            }
            DB::commit();
        } catch (\Exception $error) {
            DB::rollBack();
            return back()->withErrors(['report' => 'The cleanup could not be started. Please try again.']);
        }

        return back()->with('success', $report->code . ' is now In Progress.');
    }

    /** Before and after photos are uploaded and the report goes to the administrator (In Progress -> For Verification). */
    public function submitEvidence(Request $request, Report $report)
    {
        abort_unless($report->collector_id === $request->user()->id, 403);

        if (! $report->canChangeTo('For Verification')) {
            return back()->withErrors(['report' => $report->code . ' is not in progress, so evidence cannot be submitted.']);
        }

        $data = $request->validate([
            'before_photo' => 'required|' . self::PHOTO_RULE,
            'after_photo' => 'required|' . self::PHOTO_RULE,
            'remarks' => 'nullable|string|max:500',
        ], [
            'before_photo.required' => 'Please upload the before photo.',
            'after_photo.required' => 'Please upload the after photo.',
        ]);

        $beforePath = null;
        $afterPath = null;
        DB::beginTransaction();
        try {
            $locked = Report::lockForUpdate()->find($report->id);
            if ($locked->collector_id !== $request->user()->id || ! $locked->canChangeTo('For Verification')) {
                DB::rollBack();
                return back()->withErrors(['report' => $report->code . ' changed in the meantime, so the evidence was not saved.']);
            }

            // Photos are saved only after the status was checked, so a rejected request leaves no files.
            $beforePath = $this->storePhoto($request->file('before_photo'), 'cleanup');
            $afterPath = $this->storePhoto($request->file('after_photo'), 'cleanup');

            $locked->update([
                'status' => 'For Verification',
                'before_photo' => $beforePath,
                'after_photo' => $afterPath,
                'collector_remarks' => $data['remarks'] ?? null,
                'submitted_at' => now(),
            ]);
            ReportReview::create([
                'report_id' => $locked->id,
                'action' => 'Submitted',
                'user_id' => $request->user()->id,
                'remarks' => $data['remarks'] ?? null,
                'before_photo' => $beforePath,
                'after_photo' => $afterPath,
            ]);
            $locked->addLog('For Verification', 'Cleanup submitted. Waiting for the CGSO Administrator to verify.');
            DB::commit();
        } catch (\Exception $error) {
            DB::rollBack();
            $this->deletePhoto($beforePath);
            $this->deletePhoto($afterPath);
            return back()->withErrors(['report' => 'The cleanup evidence could not be saved. Please try again.']);
        }

        return back()->with('success', $report->code . ' was sent to the CGSO Administrator for verification.');
    }

    public function updateArea(Request $request, CollectionArea $area)
    {
        $truck = $request->user()->truck;
        // The street must belong to a route of this collector's truck. A street whose route is gone belongs to nobody.
        $areaRoute = $area->route;
        abort_unless($truck && $areaRoute && $areaRoute->truck_id === $truck->id, 403);

        $data = $request->validate(['status' => 'required|in:' . implode(',', CollectionArea::STATUSES)]);

        // Collection updates are blocked while the truck is under maintenance.
        if ($truck->status === 'Maintenance') {
            return back()->withErrors(['status' => $truck->label . ' is under maintenance. Collection updates are disabled until the CGSO sets the truck to Available.']);
        }

        // Only routes that are scheduled today can be collected today.
        $todayRouteIds = $this->routeIdsScheduledToday($truck);
        if (! in_array($area->truck_route_id, $todayRouteIds)) {
            return back()->withErrors(['status' => $areaRoute->name . ' is not scheduled today, so its progress cannot be changed.']);
        }

        if (! $area->canChangeTo($data['status'])) {
            return back()->withErrors(['status' => $area->name . ' cannot change from ' . $area->today_status . ' to ' . $data['status'] . '.']);
        }

        $area->update(['status' => $data['status'], 'status_date' => now()->toDateString()]);
        $this->updateTruckStatus($truck, $todayRouteIds);

        return back()->with('success', $area->name . ': ' . $data['status']);
    }

    /**
     * Keeps the truck status in step with ALL of today's routes of the truck:
     * - any area still being collected -> On Route
     * - every area of every route scheduled today is Done -> Available
     * - some work is done but not all -> On Route
     */
    private function updateTruckStatus($truck, $todayRouteIds)
    {
        $areas = CollectionArea::whereIn('truck_route_id', $todayRouteIds)->get();

        $collectingCount = 0;
        $doneCount = 0;
        foreach ($areas as $oneArea) {
            if ($oneArea->today_status === 'Collecting') {
                $collectingCount++;
            }
            if ($oneArea->today_status === 'Done') {
                $doneCount++;
            }
        }

        if ($collectingCount > 0) {
            $truck->update(['status' => 'On Route']);
        } elseif ($areas->count() > 0 && $doneCount === $areas->count()) {
            $truck->update(['status' => 'Available']);
        } elseif ($doneCount > 0) {
            $truck->update(['status' => 'On Route']);
        }
    }

    public function addNote(Request $request)
    {
        $user = $request->user();
        $truck = $user->truck;
        abort_unless($truck, 403, 'No truck is assigned to you yet.');

        $data = $request->validate([
            'truck_route_id' => 'nullable|integer',
            'note' => 'required|string|max:255',
            'photo' => 'nullable|' . self::PHOTO_RULE,
        ]);

        // The note is saved against the chosen route. The route must belong to this collector's truck.
        $routeId = null;
        $routeName = null;
        $barangayName = null;
        if (! empty($data['truck_route_id'])) {
            $route = TruckRoute::where('id', $data['truck_route_id'])->where('truck_id', $truck->id)->first();
            abort_unless($route, 403, 'That route is not assigned to your truck.');
            $routeId = $route->id;
            $routeName = $route->name;
            $barangayName = $route->barangay ? $route->barangay->name : null;
        } elseif ($truck->routes()->exists()) {
            return back()->withInput()->withErrors(['truck_route_id' => 'Please choose the route this note is about.']);
        }

        $photoPath = $this->storePhoto($request->file('photo'), 'notes');
        try {
            CollectionNote::create([
                'truck_id' => $truck->id,
                'truck_route_id' => $routeId,
                'user_id' => $user->id,
                'note' => $data['note'],
                'photo_path' => $photoPath,
                // The note keeps the truck and route details from the day it was written.
                'truck_label' => $truck->label,
                'route_name' => $routeName,
                'barangay_name' => $barangayName,
            ]);
        } catch (\Exception $error) {
            $this->deletePhoto($photoPath);
            return back()->withInput()->withErrors(['note' => 'The note could not be saved. Please try again.']);
        }

        return back()->with('success', 'Collection note added.');
    }
}
