<?php

namespace Tests\Feature;

use App\Models\CollectionArea;
use App\Models\CollectionNote;
use App\Models\Report;
use App\Models\Schedule;
use App\Models\Truck;
use App\Models\User;
use Tests\Concerns\FreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** End-to-end tests of the report workflow, collection notes, progress, schedules and security. */
class WorkflowTest extends TestCase
{
    use FreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    // Delete the photos these tests saved in public/uploads (only the ones listed in the test database).
    protected function tearDown(): void
    {
        $paths = [];
        foreach (DB::table('reports')->get() as $row) {
            $paths[] = $row->photo_path;
            $paths[] = $row->before_photo;
            $paths[] = $row->after_photo;
        }
        foreach (DB::table('report_reviews')->get() as $row) {
            $paths[] = $row->before_photo;
            $paths[] = $row->after_photo;
        }
        foreach (DB::table('collection_notes')->get() as $row) {
            $paths[] = $row->photo_path;
        }
        foreach ($paths as $path) {
            if ($path && str_starts_with($path, 'uploads/') && file_exists(public_path($path))) {
                unlink(public_path($path));
            }
        }
        parent::tearDown();
    }

    private function loginAs($email)
    {
        return $this->actingAs(User::where('email', $email)->firstOrFail());
    }

    // A real 1x1 PNG image (works without the GD extension).
    private function photo($name = 'photo.png')
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        return UploadedFile::fake()->createWithContent($name, $png);
    }

    private function submitResidentReport()
    {
        $resident = User::where('email', 'resident@ecowatch.com')->first();
        $this->actingAs($resident)->post('/resident/reports', [
            'category' => 'Missed Collection', 'barangay_id' => $resident->barangay_id, 'location_text' => 'Test Street',
            'description' => 'Not collected', 'lat' => 9.79, 'lng' => 125.49, 'photo' => $this->photo(),
            'submission_token' => (string) \Illuminate\Support\Str::uuid(),
        ])->assertRedirect('/resident/reports');
        return Report::where('location_text', 'Test Street')->firstOrFail();
    }

    private function assignToJuan($report)
    {
        $juan = User::where('email', 'collector@ecowatch.com')->first();
        $this->loginAs('admin@ecowatch.com')->post("/cgso/reports/{$report->id}/assign", [
            'collector_id' => $juan->id, 'assigned_truck_id' => Truck::first()->id, 'instructions' => 'Bring a shovel',
        ])->assertSessionHasNoErrors();
        return $juan;
    }

    private function submitCleanup($report, $email = 'collector@ecowatch.com')
    {
        $this->loginAs($email)->post("/collector/reports/{$report->id}/start")->assertSessionHasNoErrors();
        $this->loginAs($email)->post("/collector/reports/{$report->id}/evidence", [
            'before_photo' => $this->photo('before.png'), 'after_photo' => $this->photo('after.png'), 'remarks' => 'All clear',
        ])->assertSessionHasNoErrors();
    }

    public function test_full_workflow_from_resident_report_to_resolved(): void
    {
        // 1. Resident submits. The system fills in code, owner, status and date.
        $report = $this->submitResidentReport();
        $this->assertSame('Pending', $report->status);
        $this->assertStringStartsWith('RPT-', $report->code);
        $this->assertNotNull($report->photo_path);
        $this->assertSame(User::where('email', 'resident@ecowatch.com')->value('id'), $report->user_id);

        // 2. It appears in the CGSO reports page.
        $this->loginAs('admin@ecowatch.com')->get('/cgso/reports')->assertSee($report->code)->assertSee('Assign Collector');

        // 3. Admin assigns an individual collector (not the truck driver automatically).
        $juan = $this->assignToJuan($report);
        $report->refresh();
        $this->assertSame('Assigned', $report->status);
        $this->assertSame($juan->id, $report->collector_id);
        $this->assertDatabaseCount("report_assignments", 4); // 3 seeded + this one

        // 4. The collector sees it with a Start Cleanup button.
        $this->loginAs('collector@ecowatch.com')->get('/collector/dashboard')
            ->assertSee($report->code)->assertSee('Start Cleanup')->assertSee('Bring a shovel');

        // 5. Start, then submit evidence.
        $this->submitCleanup($report);
        $report->refresh();
        $this->assertSame('For Verification', $report->status);
        $this->assertNotNull($report->started_at);
        $this->assertNotNull($report->before_photo);
        $this->assertNotNull($report->after_photo);
        $this->loginAs('collector@ecowatch.com')->get('/collector/dashboard')
            ->assertSee('Waiting for the CGSO Administrator to verify your cleanup.');

        // 6. Admin sees the evidence and both buttons.
        $this->loginAs('admin@ecowatch.com')->get('/cgso/reports')
            ->assertSee('Approve Cleanup')->assertSee('Reject Cleanup')->assertSee('All clear')->assertSee($report->before_photo);

        // 7. Approve.
        $this->loginAs('admin@ecowatch.com')->post("/cgso/reports/{$report->id}/approve")->assertSessionHasNoErrors();
        $report->refresh();
        $this->assertSame('Resolved', $report->status);
        $this->assertSame(User::where('email', 'admin@ecowatch.com')->value('id'), $report->verified_by);
        $this->assertNotNull($report->verified_at);
        $this->assertDatabaseHas('report_reviews', ['report_id' => $report->id, 'action' => 'Approved']);

        // 8. Resident and collector dashboards show the result.
        $this->loginAs('resident@ecowatch.com')->get('/resident/reports')->assertSee('Resolved')->assertSee('Cleanup verified by the CGSO');
        $this->loginAs('collector@ecowatch.com')->get('/collector/dashboard')->assertSee('approved this cleanup');
    }

    public function test_rejection_then_correction_then_approval(): void
    {
        $report = $this->submitResidentReport();
        $this->assignToJuan($report);
        $this->submitCleanup($report);

        // A reason is required.
        $this->loginAs('admin@ecowatch.com')->post("/cgso/reports/{$report->id}/reject", [])->assertSessionHasErrors('rejection_reason');
        $this->assertSame('For Verification', $report->fresh()->status);

        $this->loginAs('admin@ecowatch.com')->post("/cgso/reports/{$report->id}/reject", ['rejection_reason' => 'Plastic bags still on the road'])->assertSessionHasNoErrors();
        $this->assertSame('Reopened', $report->fresh()->status);

        // Collector sees the reason, resubmits.
        $this->loginAs('collector@ecowatch.com')->get('/collector/dashboard')->assertSee('Plastic bags still on the road')->assertSee('Resume Cleanup');
        $this->loginAs('resident@ecowatch.com')->get('/resident/reports')->assertSee('Plastic bags still on the road');
        $this->submitCleanup($report);
        $this->assertSame('For Verification', $report->fresh()->status);

        $this->loginAs('admin@ecowatch.com')->post("/cgso/reports/{$report->id}/approve")->assertSessionHasNoErrors();
        $this->assertSame('Resolved', $report->fresh()->status);

        // History is kept: 2 submissions, 1 rejection, 1 approval, with all 4 photos still saved.
        $this->assertSame(2, $report->reviews()->where('action', 'Submitted')->count());
        $this->assertSame(1, $report->reviews()->where('action', 'Rejected')->count());
        $this->assertSame(1, $report->reviews()->where('action', 'Approved')->count());
        foreach ($report->reviews()->where('action', 'Submitted')->get() as $review) {
            $this->assertFileExists(public_path($review->before_photo));
            $this->assertFileExists(public_path($review->after_photo));
        }
    }

    public function test_evidence_needs_both_photos_and_safe_image_files(): void
    {
        $report = $this->submitResidentReport();
        $this->assignToJuan($report);
        $this->loginAs('collector@ecowatch.com')->post("/collector/reports/{$report->id}/start");

        $collector = $this->loginAs('collector@ecowatch.com');
        $collector->post("/collector/reports/{$report->id}/evidence", ['before_photo' => $this->photo()])->assertSessionHasErrors('after_photo');
        $collector->post("/collector/reports/{$report->id}/evidence", [
            'before_photo' => $this->photo(), 'after_photo' => UploadedFile::fake()->createWithContent('evil.php', '<?php echo 1;'),
        ])->assertSessionHasErrors('after_photo');
        $this->assertSame('In Progress', $report->fresh()->status);

        // An image uploaded under a .php file name is refused too.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        $collector->post("/collector/reports/{$report->id}/evidence", [
            'before_photo' => UploadedFile::fake()->createWithContent('trick.php', $png), 'after_photo' => $this->photo(),
        ])->assertSessionHasErrors('before_photo');

        // A real image whose name has a wrong extension is saved with the correct extension from its content.
        $collector->post("/collector/reports/{$report->id}/evidence", [
            'before_photo' => UploadedFile::fake()->createWithContent('wrong.jpg', $png), 'after_photo' => $this->photo(),
        ])->assertSessionHasNoErrors();
        $this->assertMatchesRegularExpression('/^uploads\/cleanup\/[A-Za-z0-9]+\.(jpg|jpeg|png|webp)$/', $report->fresh()->before_photo);
    }

    public function test_invalid_status_changes_are_blocked(): void
    {
        $report = $this->submitResidentReport();
        $juan = User::where('email', 'collector@ecowatch.com')->first();

        // Pending cannot be approved, rejected, started or submitted.
        $admin = $this->loginAs('admin@ecowatch.com');
        $admin->post("/cgso/reports/{$report->id}/approve")->assertSessionHasErrors('report');
        $admin->post("/cgso/reports/{$report->id}/reject", ['rejection_reason' => 'Not good enough'])->assertSessionHasErrors('report');
        $report->update(['collector_id' => $juan->id]);
        $col = $this->loginAs('collector@ecowatch.com');
        $col->post("/collector/reports/{$report->id}/start")->assertSessionHasErrors('report');
        $col->post("/collector/reports/{$report->id}/evidence", ['before_photo' => $this->photo(), 'after_photo' => $this->photo()])->assertSessionHasErrors('report');
        $this->assertSame('Pending', $report->fresh()->status);

        // A Resolved report cannot be assigned again.
        $report->update(['status' => 'Resolved']);
        $this->loginAs('admin@ecowatch.com')->post("/cgso/reports/{$report->id}/assign", ['collector_id' => $juan->id])->assertSessionHasErrors('collector_id');
    }

    public function test_only_the_right_people_can_act_on_a_report(): void
    {
        $report = $this->submitResidentReport();
        $juan = $this->assignToJuan($report);

        // Another collector cannot start or submit it.
        $this->loginAs('pedro@ecowatch.com')->post("/collector/reports/{$report->id}/start")->assertForbidden();
        $this->loginAs('collector@ecowatch.com')->post("/collector/reports/{$report->id}/start");
        $this->loginAs('pedro@ecowatch.com')->post("/collector/reports/{$report->id}/evidence", ['before_photo' => $this->photo(), 'after_photo' => $this->photo()])->assertForbidden();

        // Residents, officials and collectors cannot assign, approve or reject.
        foreach (['resident@ecowatch.com', 'official.sanjuan@ecowatch.com', 'collector@ecowatch.com'] as $email) {
            $this->loginAs($email)->post("/cgso/reports/{$report->id}/assign", ['collector_id' => $juan->id])->assertForbidden();
            $this->loginAs($email)->post("/cgso/reports/{$report->id}/approve")->assertForbidden();
            $this->loginAs($email)->post("/cgso/reports/{$report->id}/reject", ['rejection_reason' => 'nope nope'])->assertForbidden();
        }
        // Guests are sent to the login page.
        $this->app['auth']->forgetGuards();
        $this->post("/cgso/reports/{$report->id}/approve")->assertRedirect('/login');
        // The collector cannot use the resident feedback route either.
        $this->loginAs('collector@ecowatch.com')->post("/resident/reports/{$report->id}/feedback", ['resolved' => 'yes'])->assertForbidden();
        // The admin cannot use collector actions.
        $this->loginAs('admin@ecowatch.com')->post("/collector/reports/{$report->id}/start")->assertForbidden();
    }

    public function test_reassignment_needs_a_reason_and_only_new_collector_continues(): void
    {
        $report = $this->submitResidentReport();
        $this->assignToJuan($report);
        $this->assertSame('Assigned', $report->fresh()->status);

        // Before the cleanup starts, the CGSO can change the collector (a reason is needed).
        $pedro = User::where('email', 'pedro@ecowatch.com')->first();
        $admin = $this->loginAs('admin@ecowatch.com');
        $admin->post("/cgso/reports/{$report->id}/assign", ['collector_id' => $pedro->id])->assertSessionHasErrors('reason');
        $admin->post("/cgso/reports/{$report->id}/assign", ['collector_id' => $pedro->id, 'reason' => 'Juan is sick'])->assertSessionHasNoErrors();

        $report->refresh();
        $this->assertSame($pedro->id, $report->collector_id);
        $this->assertSame('Assigned', $report->status);
        $this->assertSame(2, $report->assignments()->count());
        $this->assertDatabaseHas('report_assignments', ['report_id' => $report->id, 'reason' => 'Juan is sick']);

        // Juan can no longer continue; Pedro can.
        $photos = ['before_photo' => $this->photo(), 'after_photo' => $this->photo()];
        $this->loginAs('collector@ecowatch.com')->post("/collector/reports/{$report->id}/evidence", $photos)->assertForbidden();
        $pedroSession = $this->loginAs('pedro@ecowatch.com');
        $pedroSession->post("/collector/reports/{$report->id}/start")->assertSessionHasNoErrors();
        $pedroSession->post("/collector/reports/{$report->id}/evidence", ['before_photo' => $this->photo(), 'after_photo' => $this->photo()])->assertSessionHasNoErrors();
        $this->assertSame('For Verification', $report->fresh()->status);
    }

    public function test_collector_cannot_be_changed_while_cleanup_is_in_progress(): void
    {
        $report = $this->submitResidentReport();
        $this->assignToJuan($report);
        $this->loginAs('collector@ecowatch.com')->post("/collector/reports/{$report->id}/start");
        $this->assertSame('In Progress', $report->fresh()->status);

        $pedro = User::where('email', 'pedro@ecowatch.com')->first();
        $juan = User::where('email', 'collector@ecowatch.com')->first();
        $this->loginAs('admin@ecowatch.com')->post("/cgso/reports/{$report->id}/assign", ['collector_id' => $pedro->id, 'reason' => 'Direct request'])->assertSessionHasErrors('collector_id');
        $this->assertSame($juan->id, $report->fresh()->collector_id);
        $this->assertSame(1, $report->assignments()->count());
    }

    public function test_collector_can_be_changed_after_cgso_rejects_the_cleanup(): void
    {
        $report = $this->submitResidentReport();
        $this->assignToJuan($report);
        $this->submitCleanup($report);

        $admin = $this->loginAs('admin@ecowatch.com');
        $admin->post("/cgso/reports/{$report->id}/reject", ['rejection_reason' => 'Photos do not show the cleanup'])->assertSessionHasNoErrors();
        $this->assertSame('Reopened', $report->fresh()->status);

        $pedro = User::where('email', 'pedro@ecowatch.com')->first();
        $admin->post("/cgso/reports/{$report->id}/assign", ['collector_id' => $pedro->id, 'reason' => 'Another crew is closer'])->assertSessionHasNoErrors();
        $this->assertSame($pedro->id, $report->fresh()->collector_id);
    }

    public function test_report_photo_is_required(): void
    {
        $resident = User::where('email', 'resident@ecowatch.com')->first();
        $data = ['category' => 'Illegal Dumping', 'barangay_id' => 1, 'location_text' => 'No Photo Street', 'lat' => 9.79, 'lng' => 125.49, 'submission_token' => (string) \Illuminate\Support\Str::uuid()];
        $this->actingAs($resident)->post('/resident/reports', $data)->assertSessionHasErrors('photo');
        $this->assertSame(0, Report::where('location_text', 'No Photo Street')->count());
        $this->actingAs($resident)->get('/resident/reports/create')->assertSee('name="photo"', false)->assertSee('required', false);
    }

    public function test_assignment_validation(): void
    {
        $report = $this->submitResidentReport();
        $admin = $this->loginAs('admin@ecowatch.com');
        $resident = User::where('email', 'resident@ecowatch.com')->first();

        $admin->post("/cgso/reports/{$report->id}/assign", [])->assertSessionHasErrors('collector_id');
        $admin->post("/cgso/reports/{$report->id}/assign", ['collector_id' => $resident->id])->assertSessionHasErrors('collector_id'); // not a collector
        $juan = User::where('email', 'collector@ecowatch.com')->first();
        $admin->post("/cgso/reports/{$report->id}/assign", ['collector_id' => $juan->id, 'target_date' => '2000-01-01'])->assertSessionHasErrors('target_date');
        $maintenanceTruck = Truck::where('status', 'Maintenance')->first();
        $admin->post("/cgso/reports/{$report->id}/assign", ['collector_id' => $juan->id, 'assigned_truck_id' => $maintenanceTruck->id])->assertSessionHasErrors('assigned_truck_id');
        $this->assertSame('Pending', $report->fresh()->status);
    }

    public function test_resident_feedback_does_not_change_the_official_status(): void
    {
        $report = $this->submitResidentReport();
        $this->assignToJuan($report);
        $this->submitCleanup($report);

        $this->loginAs('resident@ecowatch.com')->post("/resident/reports/{$report->id}/feedback", ['resolved' => 'yes'])->assertSessionHasNoErrors();
        $report->refresh();
        $this->assertSame('For Verification', $report->status);
        $this->assertSame('Resolved', $report->resident_feedback);

        // Another resident cannot give feedback on it, and feedback is not possible too early.
        $other = User::factory()->create(['role' => 'resident', 'barangay_id' => 1]);
        $this->actingAs($other)->post("/resident/reports/{$report->id}/feedback", ['resolved' => 'yes'])->assertForbidden();
        $pending = Report::where('code', 'RPT-1003')->first();
        $this->loginAs('official.sanjuan@ecowatch.com')->post("/official/reports/{$pending->id}/feedback", ['resolved' => 'yes'])->assertSessionHas('error');
    }

    public function test_duplicate_submissions_use_a_form_token_and_codes_stay_unique(): void
    {
        $resident = User::where('email', 'resident@ecowatch.com')->first();
        $token = (string) \Illuminate\Support\Str::uuid();
        $data = ['category' => 'Illegal Dumping', 'barangay_id' => 1, 'location_text' => 'Dup Street', 'lat' => 9.79, 'lng' => 125.49, 'submission_token' => $token];

        // Same form sent twice (double click / refresh): only one report.
        $this->actingAs($resident)->post('/resident/reports', ['photo' => $this->photo()] + $data);
        $this->actingAs($resident)->post('/resident/reports', ['photo' => $this->photo()] + $data);
        $this->assertSame(1, Report::where('location_text', 'Dup Street')->count());

        // A different report at the same place (new form, new token) is NOT a duplicate.
        $this->actingAs($resident)->post('/resident/reports', ['photo' => $this->photo(), 'submission_token' => (string) \Illuminate\Support\Str::uuid()] + $data);
        $this->assertSame(2, Report::where('location_text', 'Dup Street')->count());

        // The token is required.
        unset($data['submission_token']);
        $this->actingAs($resident)->post('/resident/reports', $data)->assertSessionHasErrors('submission_token');

        for ($i = 1; $i <= 4; $i++) {
            $this->actingAs($resident)->post('/resident/reports', ['photo' => $this->photo(), 'location_text' => 'Street ' . $i, 'submission_token' => (string) \Illuminate\Support\Str::uuid()] + $data);
        }
        $this->assertSame(Report::count(), Report::distinct()->count('code'));
    }

    public function test_collection_notes_are_saved_and_visible_to_cgso(): void
    {
        $collector = $this->loginAs('collector@ecowatch.com');
        $collector->post('/collector/notes', ['truck_route_id' => 1, 'note' => 'Road blocked at Luna St.', 'photo' => $this->photo('note.png')])->assertSessionHasNoErrors();
        $note = CollectionNote::where('note', 'Road blocked at Luna St.')->firstOrFail();
        $this->assertSame(User::where('email', 'collector@ecowatch.com')->value('id'), $note->user_id);
        $this->assertSame(1, $note->truck_id);
        $this->assertNotNull($note->truck_route_id);
        $this->assertNotNull($note->photo_path);

        $collector->post('/collector/notes', ['truck_route_id' => 1, 'note' => ''])->assertSessionHasErrors('note');
        $collector->post('/collector/notes', ['truck_route_id' => 1, 'note' => 'x', 'photo' => UploadedFile::fake()->createWithContent('a.txt', 'hello')])->assertSessionHasErrors('photo');

        $collector->get('/collector/dashboard')->assertSee('Road blocked at Luna St.');
        $this->loginAs('admin@ecowatch.com')->get('/cgso/collections')->assertSee('Road blocked at Luna St.')->assertSee('Juan Dela Cruz');
        $this->loginAs('resident@ecowatch.com')->get('/cgso/collections')->assertForbidden();
    }

    public function test_collection_progress_transitions_persist(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-11-02 09:00:00'); // Monday: Route A (truck 1) is scheduled
        $collector = $this->loginAs('collector@ecowatch.com');
        $area = CollectionArea::whereHas('route', fn ($q) => $q->where('truck_id', 1))->first();

        // Pending -> Done directly is not allowed.
        $collector->post("/collector/areas/{$area->id}", ['status' => 'Done'])->assertSessionHasErrors('status');
        $collector->post("/collector/areas/{$area->id}", ['status' => 'Collecting'])->assertSessionHasNoErrors();
        $this->assertSame('Collecting', CollectionArea::find($area->id)->today_status);
        $collector->post("/collector/areas/{$area->id}", ['status' => 'Done'])->assertSessionHasNoErrors();
        // "Refresh": the value is read from the database again.
        $this->assertSame('Done', CollectionArea::find($area->id)->today_status);
        $collector->get('/collector/dashboard')->assertSee('1 of 4 areas completed');
        // The resident report statuses are not touched.
        $this->assertSame(0, Report::where('status', 'Resolved')->where('code', '!=', 'RPT-1004')->count());
        // Monitoring pages
        $this->loginAs('admin@ecowatch.com')->get('/cgso/collections')->assertSee('Done');
        $this->loginAs('official.sanjuan@ecowatch.com')->get('/official/dashboard')->assertSee('Collection Progress');
        // Another truck's collector is blocked.
        $this->loginAs('pedro@ecowatch.com')->post("/collector/areas/{$area->id}", ['status' => 'Collecting'])->assertForbidden();
    }

    public function test_pickup_times_are_exact_and_validated(): void
    {
        $admin = $this->loginAs('admin@ecowatch.com');
        $base = ['truck_id' => 3, 'barangay_id' => 8, 'days' => ['Tue']];

        $admin->post('/cgso/schedules', $base)->assertSessionHasErrors('pickup_time');
        $admin->post('/cgso/schedules', $base + ['pickup_time' => '7:00 AM - 12:00 PM'])->assertSessionHasErrors('pickup_time');
        $admin->post('/cgso/schedules', $base + ['pickup_time' => '07:00'])->assertSessionHasNoErrors();

        $schedule = Schedule::where('barangay_id', 8)->firstOrFail();
        $this->assertSame('7:00 AM', $schedule->pickup_time_label);
        $this->get('/schedule')->assertSee('7:00 AM');
        $this->get('/trucks-routes')->assertOk();
        $this->loginAs('collector@ecowatch.com')->get('/collector/dashboard')->assertSee('8:00 AM'); // Route A at 08:00

        // Edit changes the time.
        $admin = $this->loginAs('admin@ecowatch.com');
        $admin->put("/cgso/schedules/{$schedule->id}", $base + ['pickup_time' => '13:30'])->assertSessionHasNoErrors();
        $this->assertSame('1:30 PM', $schedule->fresh()->pickup_time_label);

        // A truck cannot have two active schedules at the same time on the same day.
        $admin->post('/cgso/schedules', ['truck_id' => 3, 'barangay_id' => 9, 'days' => ['Tue', 'Wed'], 'pickup_time' => '13:30'])->assertSessionHasErrors('pickup_time');
        $admin->post('/cgso/schedules', ['truck_id' => 3, 'barangay_id' => 9, 'days' => ['Thu'], 'pickup_time' => '13:30'])->assertSessionHasNoErrors();
    }

    public function test_inactive_and_old_schedules_are_handled(): void
    {
        // Truck #04's schedule is inactive: not shown publicly.
        $this->get('/schedule')->assertDontSee('1:00 PM'); // Truck #04 / Taft is inactive

        // An old schedule that only has a time range is flagged, never guessed.
        $old = Schedule::create(['truck_id' => 2, 'barangay_id' => 8, 'days' => ['Sat'], 'time_range' => '8:00 AM - 12:00 PM', 'is_active' => true]);
        $this->assertNull($old->pickup_time);
        $this->loginAs('admin@ecowatch.com')->get('/cgso/schedules')->assertSee('Needs pickup time')->assertSee('Old range: 8:00 AM - 12:00 PM');
        $this->get('/schedule')->assertSee('Time to be set');

        $old->update(['is_active' => false]);
        $this->loginAs('admin@ecowatch.com')->patch("/cgso/schedules/{$old->id}/toggle")->assertSessionHasErrors('pickup_time'); // cannot activate without a time
        $this->assertFalse($old->fresh()->is_active);
        $this->loginAs('admin@ecowatch.com')->put("/cgso/schedules/{$old->id}", ['truck_id' => 2, 'barangay_id' => 8, 'days' => ['Sat'], 'pickup_time' => '08:00'])->assertSessionHasNoErrors();
        $this->assertSame('8:00 AM', $old->fresh()->pickup_time_label);
        $this->assertNull($old->fresh()->time_range);
    }

    public function test_login_is_rate_limited(): void
    {
        RateLimiter::clear('admin@ecowatch.com|127.0.0.1');
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'admin@ecowatch.com', 'password' => 'wrong'])->assertSessionHasErrors('email');
        }
        // Even the correct password is refused while the limit is active.
        $this->post('/login', ['email' => 'admin@ecowatch.com', 'password' => 'admin123'])->assertSessionHasErrors('email');
        $this->assertGuest();
        RateLimiter::clear('admin@ecowatch.com|127.0.0.1');
    }

    public function test_collector_with_open_reports_cannot_be_deleted(): void
    {
        $report = $this->submitResidentReport();
        $juan = $this->assignToJuan($report);
        $this->loginAs('admin@ecowatch.com')->delete("/cgso/users/{$juan->id}")->assertSessionHasErrors('user');
        $this->assertNotNull(User::find($juan->id));
    }

    public function test_every_page_renders_for_every_role(): void
    {
        $this->seed(); // safe to run twice
        $pages = [
            'admin@ecowatch.com' => ['/cgso/dashboard', '/cgso/reports', '/cgso/schedules', '/cgso/routes', '/cgso/trucks', '/cgso/collections', '/cgso/users', '/cgso/announcements'],
            'resident@ecowatch.com' => ['/resident/dashboard', '/resident/reports', '/resident/reports/create', '/resident/route-map'],
            'official.sanjuan@ecowatch.com' => ['/official/dashboard', '/official/reports', '/official/reports/create', '/official/route-map'],
            'collector@ecowatch.com' => ['/collector/dashboard'],
        ];
        foreach ($pages as $email => $urls) {
            foreach ($urls as $url) {
                $this->loginAs($email)->get($url)->assertOk();
            }
        }
        // The seeded For Verification report shows the Approve / Reject buttons to the admin.
        $this->loginAs('admin@ecowatch.com')->get('/cgso/reports')->assertSee('Approve Cleanup')->assertSee('Reject Cleanup');
    }
}
