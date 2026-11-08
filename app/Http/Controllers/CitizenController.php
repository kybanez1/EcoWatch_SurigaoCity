<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\Barangay;
use App\Models\Report;
use App\Models\Schedule;
use App\Models\TruckRoute;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Shared by the Resident and Barangay Official portals (same screens in the design). */
class CitizenController extends Controller
{
    public function dashboard(Request $request)
    {
        $user = $request->user();

        // Only schedules whose truck, barangay and route still exist are shown.
        $schedules = Schedule::usable()->with(['truck.collector', 'route'])
            ->where('barangay_id', $user->barangay_id)
            ->where('is_active', true)
            ->get();

        $recentReports = Report::where('user_id', $user->id)->latest('id')->take(5)->get();
        $myReportCount = Report::where('user_id', $user->id)->count();
        $myResolvedCount = Report::where('user_id', $user->id)->whereIn('status', ['Resolved', 'Closed'])->count();
        $announcements = Announcement::orderByDesc('published_on')->orderByDesc('id')->take(2)->get();

        // Only the barangay official sees the barangay reports and collection progress.
        $barangayReports = collect();
        $barangayRoutes = collect();
        if ($user->role === 'official') {
            $barangayReports = Report::with(['user', 'barangay'])->where('barangay_id', $user->barangay_id)->latest('id')->take(10)->get();
            $barangayRoutes = TruckRoute::usable()->with(['truck', 'areas'])->where('barangay_id', $user->barangay_id)->get();
        }

        return view('citizen.dashboard', [
            'role' => $user->role,
            'schedules' => $schedules,
            'recentReports' => $recentReports,
            'myReportCount' => $myReportCount,
            'myResolvedCount' => $myResolvedCount,
            'announcements' => $announcements,
            'barangayReports' => $barangayReports,
            'barangayRoutes' => $barangayRoutes,
        ]);
    }

    public function reports(Request $request)
    {
        $user = $request->user();
        $relations = ['logs', 'truck', 'barangay', 'user', 'collector', 'latestSubmission'];

        // 10 reports per page, newest first.
        $reports = Report::with($relations)->where('user_id', $user->id)->latest('id')->paginate(10)->withQueryString();

        // A barangay official also sees the other reports of the barangay (own list and this list have separate pages).
        $barangayReports = null;
        if ($user->role === 'official') {
            $barangayReports = Report::with($relations)
                ->where('barangay_id', $user->barangay_id)
                ->where('user_id', '!=', $user->id)
                ->latest('id')
                ->paginate(10, ['*'], 'barangay_page')
                ->withQueryString();
        }

        return view('citizen.reports', [
            'role' => $user->role,
            'reports' => $reports,
            'barangayReports' => $barangayReports,
        ]);
    }

    public function create(Request $request)
    {
        return view('citizen.create', [
            'role' => $request->user()->role,
            'barangays' => Barangay::orderBy('name')->get(),
            'selected' => $request->user()->barangay_id,
            // One token for every opened form. The same token cannot be saved twice.
            'submissionToken' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'category' => 'required|in:' . implode(',', Report::CATEGORIES),
            'barangay_id' => 'required|exists:barangays,id',
            'location_text' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'photo' => 'required|' . self::PHOTO_RULE,
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'submission_token' => 'required|uuid',
        ], [
            'lat.required' => 'Please click the map to drop a pin on the problem location.',
            'photo.required' => 'Please upload a photo of the waste problem.',
            'photo.image' => 'The report photo must be a real image (JPG, PNG or WEBP).',
        ]);

        // A report form can only be saved once. Double clicks and refreshes use the same token.
        $existing = Report::where('submission_token', $data['submission_token'])->first();
        if ($existing) {
            return redirect()->route($user->role . '.reports')->with('success', 'Report ' . $existing->code . ' was already submitted.');
        }

        $photoPath = $this->storePhoto($request->file('photo'), 'reports');

        DB::beginTransaction();
        try {
            // The system fills in the report code, resident, date and first status.
            $report = Report::create([
                'code' => 'TEMP-' . uniqid(),
                'category' => $data['category'],
                'barangay_id' => $data['barangay_id'],
                'user_id' => $user->id,
                'location_text' => $data['location_text'],
                'description' => $data['description'] ?? null,
                'lat' => $data['lat'],
                'lng' => $data['lng'],
                'photo_path' => $photoPath,
                'status' => 'Pending',
                'submission_token' => $data['submission_token'],
            ]);

            // The database id is always unique, so the report code is always unique too.
            $code = 'RPT-' . (1000 + $report->id);
            if (Report::where('code', $code)->exists()) {
                $code = $code . '-' . $report->id;
            }
            $report->update(['code' => $code]);

            if ($user->role === 'official') {
                $report->addLog('Pending', 'Report submitted by barangay official.');
            } else {
                $report->addLog('Pending', 'Report submitted by resident.');
            }
            DB::commit();
        } catch (\Exception $error) {
            DB::rollBack();
            $this->deletePhoto($photoPath);

            // Two requests with the same token at the same time: the second one hits the unique index.
            $saved = Report::where('submission_token', $data['submission_token'])->first();
            if ($saved) {
                return redirect()->route($user->role . '.reports')->with('success', 'Report ' . $saved->code . ' was already submitted.');
            }
            return back()->withInput()->withErrors(['category' => 'The report could not be saved. Please try again.']);
        }

        return redirect()->route($user->role . '.reports')->with('success', 'Report ' . $report->code . ' submitted. The CGSO will review it.');
    }

    /**
     * The resident answers: "Has your reported garbage problem been resolved?"
     * Yes and No are FEEDBACK ONLY. They are saved for the CGSO and never change the report status.
     * The CGSO controls the status (approve, reject, reopen).
     */
    public function feedback(Request $request, Report $report)
    {
        abort_unless($report->user_id === $request->user()->id, 403);

        $feedbackStatuses = ['For Verification', 'Resolved', 'Not Resolved'];
        if (! in_array($report->status, $feedbackStatuses)) {
            return back()->with('error', 'You can only give feedback after the collector submits the cleanup.');
        }

        $data = $request->validate(['resolved' => 'required|in:yes,no']);

        // Older reports may have no saved cleanup submission. Then there is nothing to link the feedback to.
        $latestSubmission = $report->latestSubmission;
        $submissionId = null;
        if ($latestSubmission) {
            $submissionId = $latestSubmission->id;
        }

        $answer = 'Not Resolved';
        if ($data['resolved'] === 'yes') {
            $answer = 'Resolved';
        }

        DB::beginTransaction();
        try {
            $locked = Report::lockForUpdate()->find($report->id);
            if (! in_array($locked->status, $feedbackStatuses)) {
                DB::rollBack();
                return back()->with('error', $report->code . ' changed in the meantime. Please open it again.');
            }

            $locked->update([
                'resident_feedback' => $answer,
                'feedback_at' => now(),
                'feedback_review_id' => $submissionId,
                'feedback_reviewed_at' => null,
                'feedback_followup_note' => null,
            ]);

            if ($answer === 'Resolved') {
                $locked->addLog($locked->status, 'Resident feedback: the problem is resolved.');
            } else {
                $locked->addLog($locked->status, 'Resident feedback: the problem is still not resolved.');
            }
            DB::commit();
        } catch (\Exception $error) {
            DB::rollBack();
            return back()->with('error', 'Your feedback could not be saved. Please try again.');
        }

        if ($answer === 'Not Resolved') {
            return back()->with('success', 'Thank you. Your feedback for ' . $report->code . ' was sent to the CGSO Administrator, who will review it.');
        }
        return back()->with('success', 'Thank you for your feedback on ' . $report->code . '. The CGSO Administrator decides the report status.');
    }

    public function routeMap(Request $request)
    {
        $user = $request->user();
        $routes = TruckRoute::usable()->with(['truck', 'barangay'])->get();

        $mapRoutes = [];
        $myRouteIds = [];
        foreach ($routes as $route) {
            $mapRoutes[] = $route->toMap();
            if ($route->barangay_id === $user->barangay_id) {
                $myRouteIds[] = $route->id;
            }
        }

        return view('citizen.route-map', [
            'role' => $user->role,
            'routes' => $mapRoutes,
            'myRouteIds' => $myRouteIds,
            'barangay' => $user->barangay,
        ]);
    }
}
