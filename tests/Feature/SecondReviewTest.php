<?php

namespace Tests\Feature;

use App\Models\CollectionArea;
use App\Models\Report;
use App\Models\Schedule;
use App\Models\Truck;
use App\Models\TruckRoute;
use App\Models\User;
use Tests\Concerns\FreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Auth\Notifications\ResetPassword;
use Tests\TestCase;

/** Regression tests for the second review fixes. */
class SecondReviewTest extends TestCase
{
    use FreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        foreach (DB::table('reports')->get() as $row) {
            foreach ([$row->photo_path, $row->before_photo, $row->after_photo] as $path) {
                if ($path && str_starts_with($path, 'uploads/') && file_exists(public_path($path))) {
                    unlink(public_path($path));
                }
            }
        }
        parent::tearDown();
    }

    private function loginAs($email)
    {
        return $this->actingAs(User::where('email', $email)->firstOrFail());
    }

    // The server renders the modal with the "open" class after a validation error.
    private function assertModalIsOpen($response)
    {
        $this->assertMatchesRegularExpression('/modal-bg\s+open/', $response->getContent());
    }

    private function photo($name = 'photo.png')
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        return UploadedFile::fake()->createWithContent($name, $png);
    }

    // Report 1 (RPT-1001) is seeded as "For Verification" with a collector. Make it Resolved with an attempt.
    private function resolvedReport()
    {
        $report = Report::where('code', 'RPT-1001')->first();
        $this->loginAs('admin@ecowatch.com')->post("/cgso/reports/{$report->id}/approve")->assertSessionHasNoErrors();
        return $report->fresh();
    }

    public function test_timezone_is_manila_and_days_follow_philippine_time(): void
    {
        $this->assertSame('Asia/Manila', config('app.timezone'));
        // 23:30 UTC on Sunday is already Monday in Manila.
        Carbon::setTestNow(Carbon::parse('2026-11-01 23:30:00', 'UTC'));
        $this->assertSame('Mon', now()->format('D'));
    }

    public function test_login_page_shows_no_demo_passwords(): void
    {
        $this->get('/login')->assertOk()->assertDontSee('admin123')->assertDontSee('collector123')->assertDontSee('data-fill');
        $this->assertStringNotContainsString('data-fill', file_get_contents(public_path('js/ecowatch.js')));
    }

    public function test_deactivated_accounts_cannot_log_in_and_lose_open_sessions(): void
    {
        $admin = $this->loginAs('admin@ecowatch.com');
        $resident = User::where('email', 'resident@ecowatch.com')->first();
        $admin->patch("/cgso/users/{$resident->id}/deactivate")->assertSessionHasNoErrors();
        $this->assertFalse($resident->fresh()->is_active);

        // Login is refused.
        $this->app['auth']->forgetGuards();
        $this->post('/login', ['email' => 'resident@ecowatch.com', 'password' => 'resident123'])->assertSessionHasErrors('email');
        $this->assertGuest();

        // An old session is logged out on the next request.
        $this->actingAs($resident->fresh())->get('/resident/dashboard')->assertRedirect('/login');

        // Reactivate.
        $this->loginAs('admin@ecowatch.com')->patch("/cgso/users/{$resident->id}/activate");
        $this->app['auth']->forgetGuards();
        $this->post('/login', ['email' => 'resident@ecowatch.com', 'password' => 'resident123'])->assertRedirect('/resident/dashboard');
    }

    public function test_accounts_with_records_cannot_be_deleted_and_reports_survive(): void
    {
        $admin = $this->loginAs('admin@ecowatch.com');
        $resident = User::where('email', 'resident@ecowatch.com')->first();
        $reportCount = Report::count();

        $admin->delete("/cgso/users/{$resident->id}")->assertSessionHasErrors('user');
        $this->assertNotNull(User::find($resident->id));
        $this->assertSame($reportCount, Report::count());

        // The database itself also refuses to delete a user that owns reports.
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('users')->where('id', $resident->id)->delete();
    }

    public function test_account_without_records_can_be_deleted_and_truck_is_freed(): void
    {
        $this->loginAs('admin@ecowatch.com');
        $new = User::factory()->create(['role' => 'collector']);
        Truck::where('id', 5)->update(['collector_id' => null]);
        Truck::where('id', 5)->update(['collector_id' => $new->id]);
        $this->loginAs('admin@ecowatch.com')->delete("/cgso/users/{$new->id}")->assertSessionHasNoErrors();
        $this->assertNull(User::find($new->id));
        $this->assertNull(Truck::find(5)->collector_id);
    }

    public function test_deactivating_a_collector_with_open_reports_is_blocked(): void
    {
        $juan = User::where('email', 'collector@ecowatch.com')->first(); // has RPT-1001 For Verification
        $this->loginAs('admin@ecowatch.com')->patch("/cgso/users/{$juan->id}/deactivate")->assertSessionHasErrors('user');
        $this->assertTrue($juan->fresh()->is_active);
    }

    public function test_one_driver_per_truck_is_enforced_in_the_database(): void
    {
        $juan = User::where('email', 'collector@ecowatch.com')->first();
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('trucks')->where('id', 2)->update(['collector_id' => $juan->id]);
    }

    public function test_user_can_change_own_details_and_password(): void
    {
        $this->loginAs('resident@ecowatch.com')->put('/account/profile', ['name' => 'Maria C.', 'email' => 'resident@ecowatch.com', 'phone' => '0917', 'barangay_id' => 2])->assertSessionHasNoErrors();
        $this->assertSame('Maria C.', User::where('email', 'resident@ecowatch.com')->value('name'));
        $this->loginAs('resident@ecowatch.com')->put('/account/profile', ['name' => 'X', 'email' => 'admin@ecowatch.com', 'barangay_id' => 2])->assertSessionHasErrors('email');

        $user = $this->loginAs('resident@ecowatch.com');
        $user->put('/account/password', ['current_password' => 'wrong', 'password' => 'newpass123', 'password_confirmation' => 'newpass123'])->assertSessionHasErrors('current_password');
        $user->put('/account/password', ['current_password' => 'resident123', 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $user->put('/account/password', ['current_password' => 'resident123', 'password' => 'newpass123', 'password_confirmation' => 'newpass123'])->assertSessionHasNoErrors();
        $this->app['auth']->forgetGuards();
        $this->post('/login', ['email' => 'resident@ecowatch.com', 'password' => 'newpass123'])->assertRedirect('/resident/dashboard');
        $this->get('/account')->assertOk();
    }

    public function test_admin_password_reset_forces_a_new_password(): void
    {
        $collector = User::where('email', 'ana@ecowatch.com')->first();
        $this->loginAs('admin@ecowatch.com')->post("/cgso/users/{$collector->id}/reset-password", ['password' => 'short'])->assertSessionHasErrors('password');
        $this->loginAs('admin@ecowatch.com')->post("/cgso/users/{$collector->id}/reset-password", ['password' => 'Temp12345'])->assertSessionHasNoErrors();
        $this->assertTrue($collector->fresh()->must_change_password);

        // After login every page sends the user to My Account first.
        $this->app['auth']->forgetGuards();
        $this->post('/login', ['email' => 'ana@ecowatch.com', 'password' => 'Temp12345'])->assertRedirect();
        $this->actingAs($collector->fresh())->get('/collector/dashboard')->assertRedirect('/account');
        $this->actingAs($collector->fresh())->put('/account/password', ['current_password' => 'Temp12345', 'password' => 'MyOwn12345', 'password_confirmation' => 'MyOwn12345'])->assertSessionHasNoErrors();
        $this->assertFalse($collector->fresh()->must_change_password);
        $this->actingAs($collector->fresh())->get('/collector/dashboard')->assertOk();

        // The CGSO cannot reset another CGSO account here.
        $admin = User::where('email', 'admin@ecowatch.com')->first();
        $this->loginAs('admin@ecowatch.com')->post("/cgso/users/{$admin->id}/reset-password", ['password' => 'Temp12345'])->assertSessionHasErrors('user');
    }

    public function test_forgot_password_flow_uses_the_laravel_broker(): void
    {
        Notification::fake();
        $this->get('/forgot-password')->assertOk();
        $resident = User::where('email', 'resident@ecowatch.com')->first();

        // Same answer for a known and an unknown email.
        $this->post('/forgot-password', ['email' => 'nobody@x.com'])->assertSessionHas('success');
        $this->post('/forgot-password', ['email' => 'resident@ecowatch.com'])->assertSessionHas('success');
        Notification::assertSentTo($resident, ResetPassword::class);
        Notification::assertNothingSentTo(User::where('email', 'admin@ecowatch.com')->first());

        $token = Password::createToken($resident);
        $this->get("/reset-password/{$token}?email=resident@ecowatch.com")->assertOk();
        $this->post('/reset-password', ['token' => 'bad', 'email' => 'resident@ecowatch.com', 'password' => 'Brandnew123', 'password_confirmation' => 'Brandnew123'])->assertSessionHasErrors('email');
        $this->post('/reset-password', ['token' => $token, 'email' => 'resident@ecowatch.com', 'password' => 'Brandnew123', 'password_confirmation' => 'Brandnew123'])->assertRedirect('/login');
        $this->post('/login', ['email' => 'resident@ecowatch.com', 'password' => 'Brandnew123'])->assertRedirect('/resident/dashboard');
    }

    public function test_announcements_can_be_edited_and_are_paginated(): void
    {
        $admin = $this->loginAs('admin@ecowatch.com');
        $announcement = \App\Models\Announcement::first();
        $admin->put("/cgso/announcements/{$announcement->id}", ['category' => 'General Notice', 'title' => 'New title', 'body' => 'New body'])->assertSessionHasNoErrors();
        $this->assertSame('New title', $announcement->fresh()->title);

        // Validation error: the edit modal opens again with the typed values.
        $response = $admin->followingRedirects()->from('/cgso/announcements')->put("/cgso/announcements/{$announcement->id}", ['category' => 'General Notice', 'title' => '', 'body' => 'Typed body', '_form' => 'a' . $announcement->id]);
        $this->assertModalIsOpen($response);
        $response->assertSee('Typed body')->assertSee('The title field is required.');

        for ($i = 1; $i <= 12; $i++) {
            \App\Models\Announcement::create(['category' => 'General Notice', 'title' => 'Item ' . $i, 'body' => 'x', 'published_on' => '2026-01-' . str_pad($i, 2, '0', STR_PAD_LEFT)]);
        }
        $this->get('/announcements')->assertViewHas('announcements', fn ($page) => $page->count() === 10 && $page->total() === 15);
        $this->get('/announcements?page=2')->assertOk();
        $admin->get('/cgso/announcements?page=2')->assertOk();
    }

    public function test_cgso_can_edit_user_details_and_errors_reopen_the_modal(): void
    {
        $admin = $this->loginAs('admin@ecowatch.com');
        $resident = User::where('email', 'resident@ecowatch.com')->first();
        $admin->put("/cgso/users/{$resident->id}", ['name' => 'Maria Edited', 'email' => 'resident@ecowatch.com', 'barangay_id' => 3])->assertSessionHasNoErrors();
        $this->assertSame(3, $resident->fresh()->barangay_id);
        $this->assertSame('resident', $resident->fresh()->role); // the role cannot be changed here

        $response = $admin->followingRedirects()->from('/cgso/users')->put("/cgso/users/{$resident->id}", ['name' => 'Maria Typed', 'email' => 'admin@ecowatch.com', 'barangay_id' => 3, '_form' => 'u' . $resident->id]);
        $this->assertModalIsOpen($response);
        $response->assertSee('Maria Typed')->assertSee('The email has already been taken.');

        // An official cannot take a barangay that already has an active official.
        $official = User::where('email', 'official.sanjuan@ecowatch.com')->first();
        $other = User::factory()->create(['role' => 'official', 'barangay_id' => 4]);
        $admin->put("/cgso/users/{$other->id}", ['name' => 'O', 'email' => $other->email, 'barangay_id' => $official->barangay_id])->assertSessionHasErrors('barangay_id');
    }

    public function test_cgso_can_reopen_a_resolved_report_with_a_reason(): void
    {
        $report = $this->resolvedReport();
        $this->assertSame('Resolved', $report->status);
        $admin = $this->loginAs('admin@ecowatch.com');

        $admin->post("/cgso/reports/{$report->id}/reopen", [])->assertSessionHasErrors('reopen_reason');
        $this->assertSame('Resolved', $report->fresh()->status);
        $admin->post("/cgso/reports/{$report->id}/reopen", ['reopen_reason' => 'Garbage is back again'])->assertSessionHasNoErrors();
        $report->refresh();
        $this->assertSame('Reopened', $report->status);
        $this->assertSame('Garbage is back again', $report->rejection_reason);
        $this->assertDatabaseHas('report_reviews', ['report_id' => $report->id, 'action' => 'Reopened']);
        $this->assertDatabaseHas('report_reviews', ['report_id' => $report->id, 'action' => 'Approved']); // history kept

        // Other roles cannot reopen; a report that is not resolved cannot be reopened.
        $this->loginAs('resident@ecowatch.com')->post("/cgso/reports/{$report->id}/reopen", ['reopen_reason' => 'Because I say so'])->assertForbidden();
        $this->loginAs('admin@ecowatch.com')->post("/cgso/reports/{$report->id}/reopen", ['reopen_reason' => 'Again again'])->assertSessionHasErrors('reopen_reason');

        // The collector can continue.
        $this->loginAs('collector@ecowatch.com')->get('/collector/dashboard')->assertSee('Garbage is back again')->assertSee('Resume Cleanup');
    }

    public function test_feedback_belongs_to_one_cleanup_and_cgso_can_follow_up(): void
    {
        $report = $this->resolvedReport(); // cleanup attempt #1 (seeded submission)
        $resident = User::find($report->user_id);

        $this->actingAs($resident)->post("/resident/reports/{$report->id}/feedback", ['resolved' => 'no'])->assertSessionHasNoErrors();
        $report->refresh();
        $this->assertSame('Resolved', $report->status); // feedback only: the CGSO controls the status
        $this->assertSame('Not Resolved', $report->current_feedback);

        // It shows up in the CGSO follow-up list and dashboard.
        $admin = $this->loginAs('admin@ecowatch.com');
        $admin->get('/cgso/reports?feedback=not_resolved')->assertViewHas('reports', fn ($page) => $page->count() === 1);
        $admin->get('/cgso/dashboard')->assertViewHas('todoCounts', fn ($counts) => $counts['feedback'] === 1);
        $admin->post("/cgso/reports/{$report->id}/feedback-review", [])->assertSessionHasErrors('followup_note');
        $admin->post("/cgso/reports/{$report->id}/feedback-review", ['followup_note' => 'Visited the site'])->assertSessionHasNoErrors();
        $this->assertNotNull($report->fresh()->feedback_reviewed_at);
        $admin->get('/cgso/reports?feedback=not_resolved')->assertViewHas('reports', fn ($page) => $page->count() === 0);

        // Reopen, collector cleans again: the old feedback no longer describes the new cleanup.
        $admin->post("/cgso/reports/{$report->id}/reopen", ['reopen_reason' => 'Still dirty']);
        $collector = $this->loginAs('collector@ecowatch.com');
        $collector->post("/collector/reports/{$report->id}/start");
        $collector->post("/collector/reports/{$report->id}/evidence", ['before_photo' => $this->photo(), 'after_photo' => $this->photo()])->assertSessionHasNoErrors();
        $report = Report::find($report->id);
        $this->assertSame('For Verification', $report->status);
        $this->assertNull($report->current_feedback);
        $this->assertSame('Not Resolved', $report->resident_feedback); // kept in the database for history
    }

    public function test_reports_page_paginates_and_keeps_filters(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            Report::create(['code' => 'T-' . $i, 'category' => 'Illegal Dumping', 'barangay_id' => 1, 'user_id' => 2, 'location_text' => 'L' . $i, 'lat' => 9.7, 'lng' => 125.4, 'status' => 'Pending']);
        }
        $admin = $this->loginAs('admin@ecowatch.com');
        $admin->get('/cgso/reports?status=Pending')->assertViewHas('reports', fn ($page) => $page->count() === 15 && $page->total() === 21)->assertSee('status=Pending', false);
        $admin->get('/cgso/reports?status=Pending&page=2')->assertViewHas('reports', fn ($page) => $page->count() === 6);
        $this->loginAs('resident@ecowatch.com')->get('/resident/reports')->assertOk();
    }

    public function test_old_reports_without_a_collector_are_flagged(): void
    {
        $report = Report::where('code', 'RPT-1002')->first();
        $report->update(['collector_id' => null]); // like an old record from before collectors were assigned
        $admin = $this->loginAs('admin@ecowatch.com');
        $admin->get('/cgso/reports?needs_collector=1')->assertViewHas('reports', fn ($page) => $page->count() === 1)->assertSee('Needs collector');
        $admin->get('/cgso/dashboard')->assertViewHas('todoCounts', fn ($counts) => $counts['needsCollector'] === 1);
        // It can be fixed without deleting anything.
        $mario = User::where('email', 'mario@ecowatch.com')->first();
        $admin->post("/cgso/reports/{$report->id}/assign", ['collector_id' => $mario->id])->assertSessionHasNoErrors();
        $this->assertSame($mario->id, $report->fresh()->collector_id);
    }

    public function test_collector_can_switch_between_several_routes_of_the_truck(): void
    {
        Carbon::setTestNow('2026-11-02 09:00:00'); // Monday
        $route2 = TruckRoute::create(['truck_id' => 1, 'barangay_id' => 9, 'name' => 'Route Z', 'path' => []]);
        $route2->areas()->create(['name' => 'Coastal Road', 'sort' => 0]);
        Schedule::create(['truck_id' => 1, 'barangay_id' => 9, 'truck_route_id' => $route2->id, 'days' => ['Mon'], 'pickup_time' => '10:00', 'is_active' => true]);
        $idleRoute = TruckRoute::create(['truck_id' => 1, 'barangay_id' => 8, 'name' => 'Route Idle', 'path' => []]); // no schedule

        $collector = $this->loginAs('collector@ecowatch.com');
        $collector->get('/collector/dashboard')->assertSee('Route A')->assertSee('Route Z')->assertSee('Route Idle')->assertSee('is scheduled today');
        $collector->get("/collector/dashboard?route={$route2->id}")->assertViewHas('route', fn ($route) => $route->id === $route2->id)->assertSee('Coastal Road');
        // An unscheduled route is never shown as today's work.
        $collector->get("/collector/dashboard?route={$idleRoute->id}")->assertSee('is not scheduled today')->assertViewHas('routeIsToday', false);
        // A route of another truck is ignored.
        $otherRoute = TruckRoute::where('truck_id', 2)->first();
        $collector->get("/collector/dashboard?route={$otherRoute->id}")->assertViewHas('route', fn ($route) => $route->truck_id === 1);

        // Progress can only change on a route that is scheduled today.
        $idleRoute->areas()->create(['name' => 'Idle Street', 'sort' => 0]);
        $idleArea = $idleRoute->areas()->first();
        $collector->post("/collector/areas/{$idleArea->id}", ['status' => 'Collecting'])->assertSessionHasErrors('status');

        // Truck status: finishing Route A while Route Z is still collecting keeps the truck On Route.
        $areaA = TruckRoute::where('name', 'Route A')->first()->areas;
        $areaZ = $route2->areas()->first();
        $collector->post("/collector/areas/{$areaZ->id}", ['status' => 'Collecting'])->assertSessionHasNoErrors();
        foreach ($areaA as $area) {
            $collector->post("/collector/areas/{$area->id}", ['status' => 'Collecting']);
            $collector->post("/collector/areas/{$area->id}", ['status' => 'Done']);
        }
        $this->assertSame('On Route', Truck::find(1)->status);
        $collector->post("/collector/areas/{$areaZ->id}", ['status' => 'Done']);
        $this->assertSame('Available', Truck::find(1)->status);
    }

    public function test_notes_are_saved_against_the_chosen_route_and_authorized(): void
    {
        $collector = $this->loginAs('collector@ecowatch.com');
        $ownRoute = TruckRoute::where('truck_id', 1)->first();
        $otherRoute = TruckRoute::where('truck_id', 2)->first();

        $collector->post('/collector/notes', ['truck_route_id' => $ownRoute->id, 'note' => 'Own route note'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('collection_notes', ['note' => 'Own route note', 'truck_route_id' => $ownRoute->id]);
        $collector->post('/collector/notes', ['truck_route_id' => $otherRoute->id, 'note' => 'Sneaky'])->assertForbidden();
        $collector->post('/collector/notes', ['note' => 'No route'])->assertSessionHasErrors('truck_route_id');
        $this->assertDatabaseMissing('collection_notes', ['note' => 'Sneaky']);

        // Older notes are paginated.
        for ($i = 1; $i <= 10; $i++) {
            $collector->post('/collector/notes', ['truck_route_id' => $ownRoute->id, 'note' => 'Bulk ' . $i]);
        }
        $collector->get('/collector/dashboard')->assertViewHas('notes', fn ($page) => $page->count() === 8 && $page->total() === 11);
        $collector->get('/collector/dashboard?notes_page=2')->assertSee('Own route note');
        $this->loginAs('admin@ecowatch.com')->get('/cgso/collections')->assertViewHas('notes', fn ($page) => $page->count() === 11);
    }

    public function test_maintenance_blocks_collection_updates_and_shows_service_interruption(): void
    {
        Carbon::setTestNow('2026-11-02 09:00:00');
        Truck::find(1)->update(['status' => 'Maintenance']);
        $area = TruckRoute::where('truck_id', 1)->first()->areas()->first();

        $collector = $this->loginAs('collector@ecowatch.com');
        $collector->post("/collector/areas/{$area->id}", ['status' => 'Collecting'])->assertSessionHasErrors('status');
        $collector->get('/collector/dashboard')->assertSee('under maintenance');
        $this->get('/schedule')->assertSee('Service interrupted');
        $this->get('/trucks-routes')->assertSee('Service interrupted');
        $this->loginAs('resident@ecowatch.com')->get('/resident/dashboard')->assertSee('Service interrupted');

        // A report cannot be assigned a truck that is in maintenance.
        $report = Report::where('code', 'RPT-1003')->first();
        $this->loginAs('admin@ecowatch.com')->post("/cgso/reports/{$report->id}/assign", ['collector_id' => User::where('email', 'pedro@ecowatch.com')->value('id'), 'assigned_truck_id' => 1])->assertSessionHasErrors('assigned_truck_id');
    }

    public function test_public_pages_do_not_promise_live_tracking(): void
    {
        $this->get('/trucks-routes')->assertSee('not the live position')->assertDontSee("Where's My Truck");
        $this->get('/')->assertDontSee("Where's My Truck");
    }

    public function test_route_json_is_validated_on_the_server(): void
    {
        $admin = $this->loginAs('admin@ecowatch.com');
        $base = ['truck_id' => 5, 'name' => 'Route J', 'barangay_id' => 1, 'days' => ['Sun'], 'pickup_time' => '09:00'];

        $bad = [
            'path' => ['not json', '{"a":1}', '[[1]]', '[["x","y"]]', '[[91,10],[10,10]]', '[[10,181],[10,10]]', '[[10,10]]'],
            'streets' => ['not json', '[1,2]', '["' . str_repeat('a', 81) . '"]', '["Same","Same"]', '[""]'],
        ];
        foreach ($bad['path'] as $path) {
            $admin->post('/cgso/routes', $base + ['path' => $path, 'streets' => '[]'])->assertSessionHasErrors('path');
        }
        foreach ($bad['streets'] as $streets) {
            $admin->post('/cgso/routes', $base + ['path' => '[]', 'streets' => $streets])->assertSessionHasErrors('streets');
        }
        $this->assertSame(0, TruckRoute::where('name', 'Route J')->count());

        $admin->post('/cgso/routes', $base + ['path' => '[[9.79,125.49],[9.80,125.50]]', 'streets' => '["First","Second"]'])->assertSessionHasNoErrors();
        $route = TruckRoute::where('name', 'Route J')->firstOrFail();
        $this->assertCount(2, $route->path);
        $this->assertSame(2, $route->areas()->count());
    }

    public function test_changing_a_route_keeps_all_linked_schedules_consistent(): void
    {
        $admin = $this->loginAs('admin@ecowatch.com');
        $route = TruckRoute::where('name', 'Route A')->first(); // truck 1, San Juan
        // A second schedule of the same route, valid multi-day / multi-time scheduling.
        $second = Schedule::create(['truck_id' => 1, 'barangay_id' => $route->barangay_id, 'truck_route_id' => $route->id, 'days' => ['Sat'], 'pickup_time' => '06:00', 'is_active' => true]);
        // Truck 2 already has a schedule at 06:00 on Saturday -> moving the route to truck 2 would conflict.
        Schedule::create(['truck_id' => 2, 'barangay_id' => 2, 'days' => ['Sat'], 'pickup_time' => '06:00', 'is_active' => true]);

        $payload = ['truck_id' => 2, 'name' => 'Route A', 'barangay_id' => $route->barangay_id, 'path' => '[]', 'streets' => '["Washington Street"]'];
        $admin->put("/cgso/routes/{$route->id}", $payload)->assertSessionHasErrors('truck_id');
        $this->assertSame(1, $route->fresh()->truck_id);
        $this->assertSame(1, $second->fresh()->truck_id);

        // Without a conflict every linked schedule moves together.
        $second->update(['pickup_time' => '05:00']);
        $admin->put("/cgso/routes/{$route->id}", $payload)->assertSessionHasNoErrors();
        foreach (Schedule::where('truck_route_id', $route->id)->get() as $schedule) {
            $this->assertSame(2, $schedule->truck_id);
        }
        $this->assertSame(2, Schedule::where('truck_route_id', $route->id)->count());
    }

    public function test_modal_errors_reopen_the_right_schedule_and_truck_forms(): void
    {
        $admin = $this->loginAs('admin@ecowatch.com');
        $schedule = Schedule::first();
        $response = $admin->followingRedirects()->from('/cgso/schedules')->put("/cgso/schedules/{$schedule->id}", ['truck_id' => 1, 'barangay_id' => 1, 'days' => ['Mon'], 'pickup_time' => 'bad', '_form' => 's' . $schedule->id]);
        $this->assertModalIsOpen($response);
        $response->assertSee('The pickup time must be a valid time.');

        $truck = Truck::first();
        $response = $admin->followingRedirects()->from('/cgso/trucks')->put("/cgso/trucks/{$truck->id}", ['truck_code' => 'BAD', 'plate_number' => 'Q', 'capacity_tons' => 1, 'status' => 'Available', '_form' => 't' . $truck->id]);
        $this->assertModalIsOpen($response);
        $response->assertSee('Truck code must look like TRUCK-001.');
    }

    public function test_every_page_still_renders_after_the_second_review(): void
    {
        $pages = [
            'admin@ecowatch.com' => ['/cgso/dashboard', '/cgso/reports', '/cgso/schedules', '/cgso/routes', '/cgso/routes/1/edit', '/cgso/trucks', '/cgso/collections', '/cgso/users', '/cgso/announcements', '/account'],
            'resident@ecowatch.com' => ['/resident/dashboard', '/resident/reports', '/resident/reports/create', '/resident/route-map', '/account'],
            'official.sanjuan@ecowatch.com' => ['/official/dashboard', '/official/reports', '/official/route-map', '/account'],
            'collector@ecowatch.com' => ['/collector/dashboard', '/account'],
        ];
        foreach ($pages as $email => $urls) {
            foreach ($urls as $url) {
                $this->loginAs($email)->get($url)->assertOk();
            }
        }
        foreach (['/', '/schedule', '/trucks-routes', '/announcements', '/login', '/register', '/forgot-password'] as $url) {
            $this->app['auth']->forgetGuards();
            $this->get($url)->assertOk();
        }
    }

    public function test_resident_yes_on_a_resolved_report_is_feedback_only(): void
    {
        $report = $this->resolvedReport();
        $resident = User::find($report->user_id);

        $this->actingAs($resident)->post("/resident/reports/{$report->id}/feedback", ['resolved' => 'yes'])->assertSessionHas('success');
        $report->refresh();
        $this->assertSame('Resolved', $report->status); // never Closed by the resident
        $this->assertSame('Resolved', $report->resident_feedback);
        $this->assertDatabaseMissing('report_logs', ['report_id' => $report->id, 'status' => 'Closed']);

        // The CGSO still controls the report and can reopen it.
        $this->loginAs('admin@ecowatch.com')->post("/cgso/reports/{$report->id}/reopen", ['reopen_reason' => 'Checked on site, still dirty'])->assertSessionHasNoErrors();
        $this->assertSame('Reopened', $report->fresh()->status);
    }

    public function test_old_closed_reports_accept_no_more_feedback(): void
    {
        $report = $this->resolvedReport();
        $report->update(['status' => 'Closed']); // an old report closed by the previous workflow
        $resident = User::find($report->user_id);
        $this->actingAs($resident)->post("/resident/reports/{$report->id}/feedback", ['resolved' => 'no'])->assertSessionHas('error');
    }

    public function test_resident_yes_before_cgso_approval_does_not_close_the_report(): void
    {
        $report = Report::where('code', 'RPT-1001')->first(); // For Verification
        $resident = User::find($report->user_id);
        $this->actingAs($resident)->post("/resident/reports/{$report->id}/feedback", ['resolved' => 'yes'])->assertSessionHas('success');
        $this->assertSame('For Verification', $report->fresh()->status);
    }

    public function test_resident_no_shows_a_red_alert_and_keeps_the_status(): void
    {
        $report = $this->resolvedReport();
        $resident = User::find($report->user_id);
        $response = $this->actingAs($resident)->followingRedirects()->from('/resident/reports')->post("/resident/reports/{$report->id}/feedback", ['resolved' => 'no']);
        $response->assertSee('sent to the CGSO Administrator');
        $this->assertSame('Resolved', $report->fresh()->status);
        $this->assertSame('Not Resolved', $report->fresh()->resident_feedback);
        // The CGSO finds it in the feedback list and can reopen it.
        $this->loginAs('admin@ecowatch.com')->get('/cgso/reports?feedback=not_resolved')->assertViewHas('reports', fn ($page) => $page->count() === 1);
        $this->loginAs('admin@ecowatch.com')->post("/cgso/reports/{$report->id}/reopen", ['reopen_reason' => 'Collector must clean again'])->assertSessionHasNoErrors();
        $this->assertSame('Reopened', $report->fresh()->status);
    }

    public function test_feedback_works_on_older_reports_without_a_saved_cleanup_submission(): void
    {
        // RPT-1004 is Resolved but has no cleanup submission record (like an old report).
        $report = Report::where('code', 'RPT-1004')->first();
        $this->assertSame(0, $report->reviews()->where('action', 'Submitted')->count());
        $resident = User::find($report->user_id);
        $this->actingAs($resident)->post("/resident/reports/{$report->id}/feedback", ['resolved' => 'no'])->assertSessionHas('success')->assertSessionHasNoErrors();
        $this->assertSame('Not Resolved', $report->fresh()->current_feedback);
        // It counts on the CGSO dashboard and in the filter even though there is no saved cleanup submission.
        $admin = $this->loginAs('admin@ecowatch.com');
        $admin->get('/cgso/dashboard')->assertViewHas('todoCounts', fn ($counts) => $counts['feedback'] === 1);
        $admin->get('/cgso/reports?feedback=not_resolved')->assertViewHas('reports', fn ($page) => $page->count() === 1);
        $this->actingAs($resident)->post("/resident/reports/{$report->id}/feedback", ['resolved' => 'yes'])->assertSessionHas('success');
        $this->assertSame('Resolved', $report->fresh()->status);
        $this->loginAs('admin@ecowatch.com')->get('/cgso/dashboard')->assertViewHas('todoCounts', fn ($counts) => $counts['feedback'] === 0);
    }

    public function test_not_resolved_report_can_be_reopened_and_finished_again(): void
    {
        // RPT-1004 (collector Carlo, truck 5) is an old report that is Resolved.
        $report = Report::where('code', 'RPT-1004')->first();
        $resident = User::find($report->user_id);
        $this->actingAs($resident)->post("/resident/reports/{$report->id}/feedback", ['resolved' => 'no']);
        $this->assertSame('Resolved', $report->fresh()->status);

        // The admin reopens it. The modal opens again and explains what happens next.
        $admin = $this->loginAs('admin@ecowatch.com');
        $response = $admin->followingRedirects()->from('/cgso/reports')->post("/cgso/reports/{$report->id}/reopen", ['reopen_reason' => 'Sacks are still there']);
        $response->assertSee('was reopened and is now', false)->assertSee('Resume Cleanup');
        $this->assertModalIsOpen($response);
        $this->assertSame('Reopened', $report->fresh()->status);

        // The collector continues and sends new photos.
        $carlo = $this->loginAs('carlo@ecowatch.com');
        $carlo->get('/collector/dashboard')->assertSee('Sacks are still there')->assertSee('Resume Cleanup');
        $carlo->post("/collector/reports/{$report->id}/start")->assertSessionHasNoErrors();
        $carlo->post("/collector/reports/{$report->id}/evidence", ['before_photo' => $this->photo(), 'after_photo' => $this->photo()])->assertSessionHasNoErrors();
        $this->assertSame('For Verification', $report->fresh()->status);

        // The admin approves; the resident's Yes is only feedback.
        $this->loginAs('admin@ecowatch.com')->post("/cgso/reports/{$report->id}/approve")->assertSessionHasNoErrors();
        $this->assertSame('Resolved', $report->fresh()->status);
        $this->actingAs($resident)->post("/resident/reports/{$report->id}/feedback", ['resolved' => 'yes']);
        $this->assertSame('Resolved', $report->fresh()->status);
    }
}
