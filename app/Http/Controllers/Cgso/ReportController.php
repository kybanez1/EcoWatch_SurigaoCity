<?php

namespace App\Http\Controllers\Cgso;

use App\Http\Controllers\Controller;
use App\Models\Barangay;
use App\Models\Report;
use App\Models\ReportAssignment;
use App\Models\ReportReview;
use App\Models\Truck;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        // Check the type of every filter first. Wrong input (for example search[]=x) gives a clear message.
        $filters = Validator::make($request->query(), [
            'search' => 'nullable|string|max:100',
            'status' => ['nullable', 'string', Rule::in(Report::STATUSES)],
            'category' => ['nullable', 'string', Rule::in(Report::CATEGORIES)],
            'barangay_id' => 'nullable|integer|min:1',
            'date' => 'nullable|date_format:Y-m-d',
            'needs_collector' => 'nullable|in:1',
            'feedback' => 'nullable|in:not_resolved',
        ], [
            'search.string' => 'The search text must be plain text.',
            'date.date_format' => 'The date must look like 2026-11-08.',
            'barangay_id.integer' => 'The barangay filter is not valid.',
        ]);
        if ($filters->fails()) {
            return redirect()->route('cgso.reports')->withErrors($filters);
        }

        $query = Report::with(['user', 'barangay', 'truck', 'collector', 'verifier', 'logs', 'assignments.collector', 'reviews.user', 'latestSubmission'])->latest('id');

        $search = $request->query('search');
        if ($search) {
            $query->where(function ($inner) use ($search) {
                $inner->where('code', 'like', '%' . $search . '%')
                    ->orWhere('location_text', 'like', '%' . $search . '%')
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('name', 'like', '%' . $search . '%');
                    });
            });
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('category')) {
            $query->where('category', $request->query('category'));
        }
        if ($request->filled('barangay_id')) {
            $query->where('barangay_id', $request->query('barangay_id'));
        }
        if ($request->filled('date')) {
            $query->whereDate('created_at', $request->query('date'));
        }

        // Older reports that are in progress but have no responsible collector.
        if ($request->query('needs_collector') === '1') {
            $query->whereNull('collector_id')->whereIn('status', ['Assigned', 'In Progress', 'For Verification', 'Reopened']);
        }
        // "Not Resolved" feedback of the resident that the CGSO has not reviewed yet (only feedback about the newest cleanup).
        if ($request->query('feedback') === 'not_resolved') {
            $query->needsFeedbackReview();
        }

        // 15 reports per page. The filters stay in the page links.
        $reports = $query->paginate(15)->withQueryString();

        $pins = [];
        foreach ($reports as $report) {
            $pins[] = [
                'code' => $report->code,
                'category' => $report->category,
                'status' => $report->status,
                'lat' => (float) $report->lat,
                'lng' => (float) $report->lng,
            ];
        }

        return view('cgso.reports', [
            'reports' => $reports,
            'trucks' => Truck::with('collector')->orderBy('truck_code')->get(),
            'collectors' => User::where('role', 'collector')->where('is_active', true)->orderBy('name')->get(),
            'barangays' => Barangay::orderBy('name')->get(),
            'pins' => $pins,
        ]);
    }

    /** Assign (or reassign) the responsible collector. Pending -> Assigned. */
    public function assign(Request $request, Report $report)
    {
        // The collector can be chosen or changed before the cleanup starts (Pending, Assigned) and after the CGSO
        // rejected a cleanup (Reopened). It cannot change while the cleanup is In Progress.
        // Only an old In Progress report that has no collector at all can still get one, otherwise nobody could finish it.
        if (! $report->canBeAssigned()) {
            if ($report->status === 'In Progress') {
                $message = $report->code . ' is In Progress. The collector cannot be changed while the cleanup is running.';
            } else {
                $message = $report->code . ' is ' . $report->status . ' and cannot be assigned now.';
            }
            return back()->withErrors(['collector_id' => $message])->with('open', $report->id);
        }

        $data = $request->validate([
            'collector_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', 'collector')->where('is_active', true)],
            'assigned_truck_id' => 'nullable|integer|exists:trucks,id',
            'target_date' => 'nullable|date|after_or_equal:today',
            'instructions' => 'nullable|string|max:500',
            'reason' => 'nullable|string|max:255',
        ], [
            'collector_id.required' => 'Select the collector responsible for this report.',
            'collector_id.exists' => 'The selected person is not an active garbage collector.',
        ]);

        $isReassignment = ($report->collector_id !== null && $report->collector_id != $data['collector_id']);
        if ($isReassignment && empty($data['reason'])) {
            return back()->withErrors(['reason' => 'Please give a reason for changing the collector.'])->withInput()->with('open', $report->id);
        }

        if (! empty($data['assigned_truck_id'])) {
            $truck = Truck::find($data['assigned_truck_id']);
            if ($truck->status === 'Maintenance') {
                return back()->withErrors(['assigned_truck_id' => $truck->label . ' is under maintenance. Choose another truck.'])->withInput()->with('open', $report->id);
            }
        }

        $newCollector = User::find($data['collector_id']);
        $oldCollectorName = null;
        if ($report->collector) {
            $oldCollectorName = $report->collector->name;
        }

        DB::beginTransaction();
        try {
            // Lock the report so two administrators cannot assign it at the same moment.
            $report = Report::lockForUpdate()->find($report->id);
            if (! $report->canBeAssigned()) {
                DB::rollBack();
                return back()->withErrors(['collector_id' => $report->code . ' changed in the meantime. Please open it again.'])->with('open', $report->id);
            }
            if ($report->status === 'Pending') {
                $report->status = 'Assigned';
            }
            $report->collector_id = $newCollector->id;
            $report->assigned_truck_id = $data['assigned_truck_id'] ?? null;
            $report->target_date = $data['target_date'] ?? null;
            $report->instructions = $data['instructions'] ?? null;
            $report->assigned_at = now();
            $report->save();

            ReportAssignment::create([
                'report_id' => $report->id,
                'collector_id' => $newCollector->id,
                'truck_id' => $data['assigned_truck_id'] ?? null,
                'assigned_by' => $request->user()->id,
                'target_date' => $data['target_date'] ?? null,
                'instructions' => $data['instructions'] ?? null,
                'reason' => $data['reason'] ?? null,
            ]);

            if ($isReassignment) {
                $report->addLog($report->status, 'Reassigned from ' . $oldCollectorName . ' to ' . $newCollector->name . '. Reason: ' . $data['reason']);
            } else {
                $report->addLog($report->status, 'Assigned to collector ' . $newCollector->name . '.');
            }
            DB::commit();
        } catch (\Exception $error) {
            DB::rollBack();
            return back()->withErrors(['collector_id' => 'The assignment could not be saved. Please try again.'])->with('open', $report->id);
        }

        return back()->with('success', $report->code . ' is assigned to ' . $newCollector->name . '.')->with('open', $report->id);
    }

    /** For Verification -> Resolved */
    public function approve(Request $request, Report $report)
    {
        if (! $report->canChangeTo('Resolved') || $report->status !== 'For Verification') {
            return back()->withErrors(['report' => $report->code . ' is not waiting for verification.'])->with('open', $report->id);
        }

        // Both cleanup photos must exist before the cleanup can be approved.
        $hasBefore = $report->before_photo && file_exists(public_path($report->before_photo));
        $hasAfter = $report->after_photo && file_exists(public_path($report->after_photo));
        if (! $hasBefore || ! $hasAfter) {
            return back()->withErrors(['report' => 'The before and after photos are missing, so this cleanup cannot be approved.'])->with('open', $report->id);
        }

        $data = $request->validate(['remarks' => 'nullable|string|max:500']);

        DB::beginTransaction();
        try {
            $report = Report::lockForUpdate()->find($report->id);
            if ($report->status !== 'For Verification') {
                DB::rollBack();
                return back()->withErrors(['report' => $report->code . ' changed in the meantime. Please open it again.'])->with('open', $report->id);
            }
            $report->update([
                'status' => 'Resolved',
                'verified_by' => $request->user()->id,
                'verified_at' => now(),
                'rejection_reason' => null,
            ]);
            ReportReview::create([
                'report_id' => $report->id,
                'action' => 'Approved',
                'user_id' => $request->user()->id,
                'remarks' => $data['remarks'] ?? null,
            ]);
            $report->addLog('Resolved', 'Cleanup approved by the CGSO Administrator.');
            DB::commit();
        } catch (\Exception $error) {
            DB::rollBack();
            return back()->withErrors(['report' => 'The approval could not be saved. Please try again.'])->with('open', $report->id);
        }

        return back()->with('success', $report->code . ' is now Resolved.');
    }

    /** For Verification -> Reopened (a reason is required) */
    public function reject(Request $request, Report $report)
    {
        if ($report->status !== 'For Verification') {
            return back()->withErrors(['report' => $report->code . ' is not waiting for verification.'])->with('open', $report->id);
        }

        $data = $request->validate(
            ['rejection_reason' => 'required|string|min:5|max:255'],
            ['rejection_reason.required' => 'Please explain why additional cleanup is needed.']
        );

        DB::beginTransaction();
        try {
            $report = Report::lockForUpdate()->find($report->id);
            if ($report->status !== 'For Verification') {
                DB::rollBack();
                return back()->withErrors(['report' => $report->code . ' changed in the meantime. Please open it again.'])->with('open', $report->id);
            }
            $report->update([
                'status' => 'Reopened',
                'rejection_reason' => $data['rejection_reason'],
                'rejected_at' => now(),
                'verified_by' => $request->user()->id,
                'verified_at' => now(),
            ]);
            ReportReview::create([
                'report_id' => $report->id,
                'action' => 'Rejected',
                'user_id' => $request->user()->id,
                'remarks' => $data['rejection_reason'],
            ]);
            $report->addLog('Reopened', 'Cleanup rejected. Reason: ' . $data['rejection_reason']);
            DB::commit();
        } catch (\Exception $error) {
            DB::rollBack();
            return back()->withErrors(['report' => 'The rejection could not be saved. Please try again.'])->with('open', $report->id);
        }

        return back()->with('success', $report->code . ' was reopened. The collector can correct the cleanup and submit again.');
    }

    /** Resolved -> Reopened. Only the CGSO Administrator can do this (for example after "Not Resolved" feedback). */
    public function reopen(Request $request, Report $report)
    {
        if ($report->status !== 'Resolved' && $report->status !== 'Not Resolved') {
            return back()->withErrors(['reopen_reason' => 'Only a Resolved or Not Resolved report can be reopened.'])->with('open', $report->id);
        }

        $data = $request->validate(
            ['reopen_reason' => 'required|string|min:5|max:255'],
            ['reopen_reason.required' => 'Please explain why this report is being reopened.']
        );

        DB::beginTransaction();
        try {
            $report = Report::lockForUpdate()->find($report->id);
            if ($report->status !== 'Resolved' && $report->status !== 'Not Resolved') {
                DB::rollBack();
                return back()->withErrors(['reopen_reason' => $report->code . ' changed in the meantime. Please open it again.'])->with('open', $report->id);
            }
            $report->update([
                'status' => 'Reopened',
                'rejection_reason' => $data['reopen_reason'],
                'rejected_at' => now(),
            ]);
            ReportReview::create([
                'report_id' => $report->id,
                'action' => 'Reopened',
                'user_id' => $request->user()->id,
                'remarks' => $data['reopen_reason'],
            ]);
            $report->addLog('Reopened', 'Report reopened by the CGSO Administrator. Reason: ' . $data['reopen_reason']);
            DB::commit();
        } catch (\Exception $error) {
            DB::rollBack();
            return back()->withErrors(['reopen_reason' => 'The report could not be reopened. Please try again.'])->with('open', $report->id);
        }

        $message = $report->code . ' was reopened and is now "Reopened".';
        if ($report->collector_id) {
            $message = $message . ' ' . $report->collector->name . ' can now press "Resume Cleanup", upload new photos and send it for verification again.';
        } else {
            $message = $message . ' Assign a collector below so the cleanup can continue.';
        }
        return back()->with('success', $message)->with('open', $report->id);
    }

    /** The CGSO marks the resident's "Not Resolved" feedback as reviewed and writes a follow-up note. */
    public function reviewFeedback(Request $request, Report $report)
    {
        if ($report->current_feedback !== 'Not Resolved') {
            return back()->withErrors(['followup_note' => 'There is no current "Not Resolved" feedback on this report.'])->with('open', $report->id);
        }

        $data = $request->validate(['followup_note' => 'required|string|max:500'], ['followup_note.required' => 'Please write what the CGSO did or decided.']);

        // The review mark and its history line are saved together. If one fails, neither is kept.
        DB::beginTransaction();
        try {
            $locked = Report::lockForUpdate()->find($report->id);
            if ($locked->current_feedback !== 'Not Resolved' || $locked->feedback_reviewed_at) {
                DB::rollBack();
                return back()->withErrors(['followup_note' => 'This feedback was already reviewed or changed. Please open the report again.'])->with('open', $report->id);
            }
            $locked->update(['feedback_reviewed_at' => now(), 'feedback_followup_note' => $data['followup_note']]);
            $locked->addLog($locked->status, 'CGSO reviewed the resident feedback: ' . $data['followup_note']);
            DB::commit();
        } catch (\Exception $error) {
            DB::rollBack();
            return back()->withErrors(['followup_note' => 'The review could not be saved. Please try again.'])->withInput()->with('open', $report->id);
        }

        return back()->with('success', 'The resident feedback of ' . $report->code . ' was marked as reviewed.')->with('open', $report->id);
    }
}
