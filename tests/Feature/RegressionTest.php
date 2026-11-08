<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\CollectionArea;
use App\Models\CollectionNote;
use App\Models\Report;
use App\Models\Schedule;
use App\Models\Truck;
use App\Models\TruckRoute;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\FreshDatabase;
use Tests\TestCase;

/**
 * Tests for the bugs fixed after the third review: truck deletion, old records with missing relationships,
 * wrong input types, long report reasons and the report workflow.
 * Run it on MySQL too (see README): foreign keys and column sizes are real there.
 */
class RegressionTest extends TestCase
{
    use FreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function loginAs($email)
    {
        return $this->actingAs(User::where('email', $email)->firstOrFail());
    }

    private function photo($name = 'photo.png')
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        return UploadedFile::fake()->createWithContent($name, $png);
    }

    /**
     * Truck #06 is a display label. This makes a truck with code TRUCK-006 whose real database id is
     * NOT 6, so a test that mixes the label and the id fails.
     */
    private function makeTruckSix()
    {
        $spare = Truck::create(['truck_code' => 'TRUCK-099', 'plate_number' => 'SPARE-1', 'capacity_tons' => 5, 'status' => 'Available', 'color' => '#111111']);
        $spare->delete();
        $truck = Truck::create(['truck_code' => 'TRUCK-006', 'plate_number' => 'XYZ-0006', 'capacity_tons' => 7, 'status' => 'Available', 'color' => '#0CA678']);
        $this->assertSame('Truck #06', $truck->label);
        $this->assertNotSame(6, $truck->id);
        return $truck;
    }

    private function routeFor($truck, $barangayName = 'Taft', $name = 'Route Six', $withSchedule = true)
    {
        $barangay = Barangay::where('name', $barangayName)->firstOrFail();
        $route = TruckRoute::create(['truck_id' => $truck->id, 'barangay_id' => $barangay->id, 'name' => $name, 'path' => [[9.79, 125.49], [9.80, 125.50]]]);
        $route->areas()->create(['name' => 'Street Six', 'sort' => 0]);
        if ($withSchedule) {
            Schedule::create(['truck_id' => $truck->id, 'barangay_id' => $barangay->id, 'truck_route_id' => $route->id, 'days' => ['Mon', 'Tue'], 'pickup_time' => '07:30', 'is_active' => true]);
        }
        return $route;
    }

    /** What the old delete left behind: the truck row is gone but routes and schedules still point to it. */
    private function removeTruckRowOnly($truck)
    {
        Schema::disableForeignKeyConstraints();
        DB::table('trucks')->where('id', $truck->id)->delete();
        Schema::enableForeignKeyConstraints();
    }

    private function everyCgsoPage()
    {
        return ['/cgso/dashboard', '/cgso/routes', '/cgso/trucks', '/cgso/schedules', '/cgso/reports', '/cgso/collections', '/cgso/announcements', '/cgso/users'];
    }

    // ---------------------------------------------------------------- 1. Truck deletion

    public function test_a_truck_with_routes_or_schedules_cannot_be_deleted_by_its_real_id(): void
    {
        $truck = $this->makeTruckSix();
        $route = $this->routeFor($truck);

        $admin = $this->loginAs('admin@ecowatch.com');
        $admin->from('/cgso/trucks')->followingRedirects()->delete('/cgso/trucks/' . $truck->id)
            ->assertSee('Truck #06 cannot be deleted')->assertSee('1 route');

        $this->assertDatabaseHas('trucks', ['id' => $truck->id]);
        $this->assertDatabaseHas('truck_routes', ['id' => $route->id, 'truck_id' => $truck->id]);
        $this->assertSame(1, Schedule::where('truck_id', $truck->id)->count());
        $this->assertSame(1, CollectionArea::where('truck_route_id', $route->id)->count());

        foreach ($this->everyCgsoPage() as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_a_truck_with_notes_or_report_history_cannot_be_deleted(): void
    {
        $truck = $this->makeTruckSix();
        $collector = User::where('email', 'collector@ecowatch.com')->first();
        CollectionNote::create(['truck_id' => $truck->id, 'user_id' => $collector->id, 'note' => 'Old note', 'truck_label' => 'Truck #06']);

        $admin = $this->loginAs('admin@ecowatch.com');
        $admin->delete('/cgso/trucks/' . $truck->id)->assertSessionHasErrors('truck');
        $this->assertDatabaseHas('collection_notes', ['note' => 'Old note']);

        CollectionNote::where('truck_id', $truck->id)->delete();
        $report = Report::where('code', 'RPT-1003')->first();
        $report->update(['assigned_truck_id' => $truck->id]);
        $admin->delete('/cgso/trucks/' . $truck->id)->assertSessionHasErrors('truck');
        $this->assertSame($truck->id, $report->fresh()->assigned_truck_id);
    }

    public function test_a_truck_without_linked_records_can_be_deleted(): void
    {
        $truck = $this->makeTruckSix();
        $this->loginAs('admin@ecowatch.com')->delete('/cgso/trucks/' . $truck->id)->assertSessionHas('success');
        $this->assertDatabaseMissing('trucks', ['id' => $truck->id]);
        foreach ($this->everyCgsoPage() as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_the_database_itself_refuses_to_delete_a_truck_that_is_in_use(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Foreign key delete rules are checked on MySQL only.');
        }
        $truck = $this->makeTruckSix();
        $this->routeFor($truck);
        try {
            DB::table('trucks')->where('id', $truck->id)->delete();
            $this->fail('MySQL allowed the delete.');
        } catch (QueryException $error) {
            $this->assertDatabaseHas('truck_routes', ['truck_id' => $truck->id]);
        }
    }

    public function test_old_data_with_a_deleted_truck_never_breaks_a_page(): void
    {
        $truck = $this->makeTruckSix();
        $route = $this->routeFor($truck);
        $routeWithoutSchedule = $this->routeFor($truck, 'Luna', 'Route Six B', false);
        $collector = User::where('email', 'collector@ecowatch.com')->first();
        $note = CollectionNote::create(['truck_id' => $truck->id, 'truck_route_id' => $route->id, 'user_id' => $collector->id, 'note' => 'Written before', 'truck_label' => 'Truck #06', 'route_name' => 'Route Six', 'barangay_name' => 'Taft']);
        $this->removeTruckRowOnly($truck);

        // The three pages from the bug report, and every other CGSO page.
        $admin = $this->loginAs('admin@ecowatch.com');
        foreach ($this->everyCgsoPage() as $url) {
            $admin->get($url)->assertOk();
        }
        $admin->get('/cgso/routes')->assertSee('Route Six')->assertSee('need a replacement truck')->assertSee('No truck assigned');
        $admin->get('/cgso/schedules')->assertSee('Needs reassignment');
        $admin->get('/cgso/collections')->assertSee('Needs reassignment')->assertSee('Written before')->assertSee('Truck #06');
        $admin->get('/cgso/routes/' . $route->id . '/edit')->assertOk()->assertSee('— Select a truck —');

        // Public, resident, official and collector pages hide the invalid route and still open.
        $this->get('/schedule')->assertOk()->assertDontSee('Truck #06');
        $this->get('/trucks-routes')->assertOk()->assertDontSee('Route Six');
        foreach (['resident@ecowatch.com' => 'resident', 'official.sanjuan@ecowatch.com' => 'official'] as $email => $role) {
            $this->loginAs($email);
            foreach (['dashboard', 'reports', 'reports/create', 'route-map'] as $page) {
                $this->get("/$role/$page")->assertOk();
            }
        }
        $this->loginAs('collector@ecowatch.com')->get('/collector/dashboard')->assertOk();
    }

    public function test_cgso_chooses_a_replacement_truck_and_linked_schedules_follow(): void
    {
        $truck = $this->makeTruckSix();
        $route = $this->routeFor($truck);
        $this->removeTruckRowOnly($truck);
        $replacement = Truck::where('truck_code', 'TRUCK-003')->first();

        $admin = $this->loginAs('admin@ecowatch.com');
        // Nothing is chosen for the CGSO: an empty truck is rejected.
        $admin->put('/cgso/routes/' . $route->id, ['truck_id' => '', 'name' => 'Route Six', 'barangay_id' => $route->barangay_id, 'path' => json_encode($route->path), 'streets' => json_encode(['Street Six'])])
            ->assertSessionHasErrors('truck_id');

        $admin->put('/cgso/routes/' . $route->id, ['truck_id' => $replacement->id, 'name' => 'Route Six', 'barangay_id' => $route->barangay_id, 'path' => json_encode($route->path), 'streets' => json_encode(['Street Six'])])
            ->assertSessionHasNoErrors();

        $this->assertSame($replacement->id, $route->fresh()->truck_id);
        $this->assertSame($replacement->id, Schedule::where('truck_route_id', $route->id)->value('truck_id'));
        $this->assertSame(1, CollectionArea::where('truck_route_id', $route->id)->count()); // street progress kept
        $this->get('/cgso/routes')->assertDontSee('need a replacement truck');
        $this->get('/schedule')->assertOk()->assertSee('Taft');
    }

    public function test_schedule_with_a_missing_truck_is_listed_and_can_be_fixed(): void
    {
        $truck = $this->makeTruckSix();
        $schedule = Schedule::create(['truck_id' => $truck->id, 'barangay_id' => 1, 'days' => ['Wed'], 'pickup_time' => '09:00', 'is_active' => true]);
        $this->removeTruckRowOnly($truck);

        $admin = $this->loginAs('admin@ecowatch.com');
        $admin->get('/cgso/schedules')->assertOk()->assertSee('Needs reassignment');
        $this->get('/schedule')->assertOk();
        $this->assertSame(0, Schedule::usable()->where('id', $schedule->id)->count());

        $replacement = Truck::where('truck_code', 'TRUCK-005')->first();
        $admin->put('/cgso/schedules/' . $schedule->id, ['truck_id' => $replacement->id, 'barangay_id' => 1, 'days' => ['Wed'], 'pickup_time' => '09:00'])->assertSessionHasNoErrors();
        $this->assertSame($replacement->id, $schedule->fresh()->truck_id);
    }

    public function test_old_edit_url_of_a_deleted_route_is_404_but_sidebar_pages_open(): void
    {
        $truck = $this->makeTruckSix();
        $route = $this->routeFor($truck);
        $admin = $this->loginAs('admin@ecowatch.com');
        $admin->delete('/cgso/routes/' . $route->id)->assertSessionHasNoErrors();
        $this->get('/cgso/routes/' . $route->id . '/edit')->assertNotFound();
        foreach ($this->everyCgsoPage() as $url) {
            $this->get($url)->assertOk();
        }
        // The schedule was kept without a route and the truck can still not be deleted.
        $this->assertSame(1, Schedule::where('truck_id', $truck->id)->count());
        $this->delete('/cgso/trucks/' . $truck->id)->assertSessionHasErrors('truck');
    }

    public function test_dangling_route_and_street_records_do_not_crash_anything(): void
    {
        // A street whose route no longer exists, a schedule linked to a missing route, a note linked to a missing route.
        Schema::disableForeignKeyConstraints();
        $area = CollectionArea::create(['truck_route_id' => 99999, 'name' => 'Lost Street', 'sort' => 0]);
        $schedule = Schedule::create(['truck_id' => Truck::first()->id, 'barangay_id' => 1, 'truck_route_id' => 99999, 'days' => ['Mon'], 'pickup_time' => '08:00', 'is_active' => true]);
        $collector = User::where('email', 'collector@ecowatch.com')->first();
        DB::table('collection_notes')->insert(['truck_id' => Truck::first()->id, 'truck_route_id' => 99999, 'user_id' => $collector->id, 'note' => 'Lost route note', 'created_at' => now(), 'updated_at' => now()]);
        Schema::enableForeignKeyConstraints();

        $admin = $this->loginAs('admin@ecowatch.com');
        foreach ($this->everyCgsoPage() as $url) {
            $admin->get($url)->assertOk();
        }
        $this->get('/schedule')->assertOk();
        $this->get('/trucks-routes')->assertOk();
        $this->loginAs('collector@ecowatch.com')->get('/collector/dashboard')->assertOk();

        // The collector cannot change a street whose route is gone (and it does not crash).
        $this->post('/collector/areas/' . $area->id, ['status' => 'Collecting'])->assertForbidden();
    }

    public function test_unknown_street_status_is_handled(): void
    {
        $area = new CollectionArea(['name' => 'X', 'status' => 'Skipped', 'status_date' => now()]);
        $this->assertSame('Pending', $area->today_status);
        $this->assertTrue($area->canChangeTo('Collecting'));
        $this->assertFalse($area->canChangeTo('Done'));
        $this->assertFalse($area->canChangeTo('Nonsense'));
    }

    // ---------------------------------------------------------------- 2. Other bugs

    private function routePayload($overrides = [])
    {
        return array_merge([
            'truck_id' => Truck::where('truck_code', 'TRUCK-001')->value('id'),
            'name' => 'Test Route',
            'barangay_id' => 1,
            'days' => ['Mon'],
            'pickup_time' => '10:15',
            'path' => json_encode([[9.79, 125.49], [9.80, 125.50]]),
            'streets' => json_encode(['First Street', 'Second Street']),
        ], $overrides);
    }

    public function test_blank_map_fields_keep_the_saved_route_points_and_streets(): void
    {
        $admin = $this->loginAs('admin@ecowatch.com');
        $admin->post('/cgso/routes', $this->routePayload())->assertSessionHasNoErrors();
        $route = TruckRoute::where('name', 'Test Route')->firstOrFail();
        $this->assertCount(2, $route->path);
        $this->assertSame(2, $route->areas()->count());

        // The map script failed: both fields come back blank.
        $admin->put('/cgso/routes/' . $route->id, $this->routePayload(['name' => 'Test Route Renamed', 'path' => '', 'streets' => '']))->assertSessionHasNoErrors();
        $route->refresh();
        $this->assertSame('Test Route Renamed', $route->name);
        $this->assertCount(2, $route->path);
        $this->assertSame(2, $route->areas()->count());

        // Fields missing entirely: same result.
        $payload = $this->routePayload(['name' => 'Test Route Again']);
        unset($payload['path'], $payload['streets']);
        $admin->put('/cgso/routes/' . $route->id, $payload)->assertSessionHasNoErrors();
        $this->assertCount(2, $route->fresh()->path);
        $this->assertSame(2, $route->areas()->count());

        // The edit page puts the saved values into the form, so saving without the script keeps them too.
        $admin->get('/cgso/routes/' . $route->id . '/edit')->assertSee('id="path"', false)->assertSee('9.79', false)->assertSee('First Street');

        // Clearing on purpose ([]) still works.
        $admin->put('/cgso/routes/' . $route->id, $this->routePayload(['name' => 'Test Route Again', 'path' => '[]', 'streets' => '[]']))->assertSessionHasNoErrors();
        $route->refresh();
        $this->assertSame([], $route->path ?? []);
        $this->assertSame(0, $route->areas()->count());
    }

    public function test_barangay_with_a_route_cannot_be_deleted_even_without_a_schedule(): void
    {
        $barangay = Barangay::create(['name' => 'Routes Only']);
        $truck = Truck::where('truck_code', 'TRUCK-002')->first();
        $route = TruckRoute::create(['truck_id' => $truck->id, 'barangay_id' => $barangay->id, 'name' => 'No Schedule Route']);

        $this->loginAs('admin@ecowatch.com')->delete('/cgso/barangays/' . $barangay->id)->assertSessionHasErrors('barangay');
        $this->assertDatabaseHas('barangays', ['id' => $barangay->id]);
        $this->assertDatabaseHas('truck_routes', ['id' => $route->id]);

        $route->delete();
        $this->delete('/cgso/barangays/' . $barangay->id)->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('barangays', ['id' => $barangay->id]);
    }

    public function test_rejected_route_and_schedule_forms_redisplay_with_odd_input(): void
    {
        $admin = $this->loginAs('admin@ecowatch.com');
        $bad = [
            'name' => ['x'], 'truck_id' => ['1'], 'barangay_id' => ['1'], 'days' => 'Mon', 'pickup_time' => ['07:00'],
            'path' => ['a', 'b'], 'streets' => ['a'],
        ];
        $admin->from('/cgso/routes')->followingRedirects()->post('/cgso/routes', $bad)->assertOk();

        $bad2 = ['name' => 'Ok', 'truck_id' => '1', 'barangay_id' => '1', 'days' => ['Mon', ['x']], 'pickup_time' => '07:00', 'path' => '[["a","b"],[1]]', 'streets' => '[1,[2],"Real Street"]'];
        $admin->from('/cgso/routes')->followingRedirects()->post('/cgso/routes', $bad2)->assertOk();

        $badSchedule = ['truck_id' => ['1'], 'barangay_id' => 'abc', 'truck_route_id' => ['x'], 'days' => 'Mon', 'pickup_time' => ['x']];
        $admin->from('/cgso/schedules')->followingRedirects()->post('/cgso/schedules', $badSchedule)->assertOk();
        $badSchedule['days'] = [['Mon']];
        $admin->from('/cgso/schedules')->followingRedirects()->post('/cgso/schedules', $badSchedule)->assertOk();

        $schedule = Schedule::first();
        $admin->from('/cgso/schedules')->followingRedirects()->put('/cgso/schedules/' . $schedule->id, ['truck_id' => '1', 'barangay_id' => '1', 'days' => 'Tue', 'pickup_time' => '07:00'])->assertOk();

        // And the same on a route edit form.
        $route = TruckRoute::first();
        $admin->from('/cgso/routes/' . $route->id . '/edit')->followingRedirects()->put('/cgso/routes/' . $route->id, $bad)->assertOk();
    }

    public function test_long_reasons_and_notes_are_saved_in_the_report_history(): void
    {
        $long = str_repeat('é', 255);
        $report = Report::where('code', 'RPT-1001')->first(); // For Verification, Juan, truck 1
        $admin = $this->loginAs('admin@ecowatch.com');

        $admin->post("/cgso/reports/{$report->id}/reject", ['rejection_reason' => $long])->assertSessionHasNoErrors();
        $this->assertSame('Reopened', $report->fresh()->status);

        $pedro = User::where('email', 'pedro@ecowatch.com')->first();
        $admin->post("/cgso/reports/{$report->id}/assign", ['collector_id' => $pedro->id, 'reason' => $long])->assertSessionHasNoErrors();
        $this->assertSame($pedro->id, $report->fresh()->collector_id);
        $this->assertDatabaseHas('report_assignments', ['report_id' => $report->id, 'reason' => $long]);

        // Resolved report -> reopen with a long reason.
        $resolved = Report::where('code', 'RPT-1004')->first();
        $admin->post("/cgso/reports/{$resolved->id}/reopen", ['reopen_reason' => $long])->assertSessionHasNoErrors();
        $this->assertSame('Reopened', $resolved->fresh()->status);
        $this->assertTrue($resolved->logs()->where('message', 'like', '%' . $long . '%')->exists());
        $this->assertTrue($report->logs()->where('message', 'like', '%' . $long . '%')->exists());
    }

    public function test_feedback_review_with_a_long_note_saves_review_and_history_together(): void
    {
        $report = Report::where('code', 'RPT-1001')->first();
        $this->loginAs('admin@ecowatch.com')->post("/cgso/reports/{$report->id}/approve")->assertSessionHasNoErrors();
        $this->actingAs(User::find($report->user_id))->post("/resident/reports/{$report->id}/feedback", ['resolved' => 'no'])->assertSessionHasNoErrors();

        $note = str_repeat('N', 500);
        $this->loginAs('admin@ecowatch.com')->post("/cgso/reports/{$report->id}/feedback-review", ['followup_note' => $note])->assertSessionHasNoErrors();
        $report->refresh();
        $this->assertNotNull($report->feedback_reviewed_at);
        $this->assertSame($note, $report->feedback_followup_note);
        $this->assertTrue($report->logs()->where('message', 'like', 'CGSO reviewed the resident feedback: N%')->exists());
    }

    public function test_a_failing_history_write_leaves_the_feedback_review_unsaved(): void
    {
        $report = Report::where('code', 'RPT-1001')->first();
        $this->loginAs('admin@ecowatch.com')->post("/cgso/reports/{$report->id}/approve");
        $this->actingAs(User::find($report->user_id))->post("/resident/reports/{$report->id}/feedback", ['resolved' => 'no']);

        // Make the history insert fail: the review mark must roll back with it.
        $logCountBefore = $report->logs()->count();
        \App\Models\ReportLog::creating(function () {
            throw new \RuntimeException('history write failed');
        });
        try {
            $this->loginAs('admin@ecowatch.com')->post("/cgso/reports/{$report->id}/feedback-review", ['followup_note' => 'Checked'])->assertSessionHasErrors('followup_note');
        } finally {
            \App\Models\ReportLog::flushEventListeners();
        }
        $report->refresh();
        $this->assertNull($report->feedback_reviewed_at);
        $this->assertNull($report->feedback_followup_note);
        $this->assertSame($logCountBefore, $report->logs()->count());
    }

    public function test_old_reports_with_not_resolved_feedback_count_without_a_cleanup_submission(): void
    {
        $old = Report::where('code', 'RPT-1004')->first(); // Resolved, no saved cleanup submission
        $old->update(['resident_feedback' => 'Not Resolved', 'feedback_at' => now(), 'feedback_review_id' => null]);
        $admin = $this->loginAs('admin@ecowatch.com');
        $admin->get('/cgso/dashboard')->assertViewHas('todoCounts', fn ($counts) => $counts['feedback'] === 1);
        $admin->get('/cgso/reports?feedback=not_resolved')->assertViewHas('reports', fn ($page) => $page->count() === 1);

        // Older feedback (no cleanup link) must not count as feedback on a newer cleanup.
        $newer = Report::where('code', 'RPT-1001')->first(); // has a saved cleanup submission
        $newer->update(['resident_feedback' => 'Not Resolved', 'feedback_at' => now(), 'feedback_review_id' => null]);
        $this->assertNull($newer->fresh()->current_feedback);
        $admin->get('/cgso/dashboard')->assertViewHas('todoCounts', fn ($counts) => $counts['feedback'] === 1);
        $admin->get('/cgso/reports?feedback=not_resolved')->assertViewHas('reports', fn ($page) => $page->count() === 1);
    }

    public function test_login_goes_to_the_role_dashboard_when_the_requested_page_belongs_to_another_role(): void
    {
        $this->get('/cgso/users')->assertRedirect('/login');
        $this->post('/login', ['email' => 'resident@ecowatch.com', 'password' => 'resident123'])->assertRedirect('/resident/dashboard');
        $this->post('/logout');

        // A page this role may open is still used.
        $this->get('/resident/reports')->assertRedirect('/login');
        $this->post('/login', ['email' => 'resident@ecowatch.com', 'password' => 'resident123'])->assertRedirect('/resident/reports');
        $this->post('/logout');

        $this->get('/collector/dashboard')->assertRedirect('/login');
        $this->post('/login', ['email' => 'admin@ecowatch.com', 'password' => 'admin123'])->assertRedirect('/cgso/dashboard');
    }

    public function test_wrong_input_types_give_validation_messages_not_errors(): void
    {
        $admin = $this->loginAs('admin@ecowatch.com');
        $admin->get('/cgso/reports?search[]=x')->assertRedirect('/cgso/reports')->assertSessionHasErrors('search');
        $admin->get('/cgso/reports?date[]=x')->assertRedirect('/cgso/reports')->assertSessionHasErrors('date');
        $admin->get('/cgso/reports?date=not-a-date')->assertRedirect('/cgso/reports')->assertSessionHasErrors('date');
        $admin->get('/cgso/reports?status[]=x&category[]=y&barangay_id[]=1')->assertRedirect('/cgso/reports');
        $admin->get('/cgso/reports')->assertOk();
        $admin->get('/cgso/reports?search=RPT&date=2026-08-20')->assertOk();

        $this->get('/schedule?day[]=Mon')->assertRedirect('/schedule')->assertSessionHasErrors('day');
        $this->get('/schedule?day=Funday')->assertRedirect('/schedule')->assertSessionHasErrors('day');
        $this->get('/schedule?barangay[]=1')->assertRedirect('/schedule')->assertSessionHasErrors('barangay');
        $this->get('/schedule?day=Mon&barangay=1')->assertOk();
        $this->get('/schedule')->assertOk();
    }

    public function test_wrong_input_types_on_auth_forms(): void
    {
        auth()->logout();
        $this->post('/login', ['email' => 'resident@ecowatch.com', 'password' => ['x']])->assertSessionHasErrors('password');
        $this->post('/login', ['email' => ['a@b.c'], 'password' => 'x'])->assertSessionHasErrors('email');
        $this->from('/login')->followingRedirects()->post('/login', ['email' => ['a@b.c'], 'password' => ['x']])->assertOk();
        $this->post('/forgot-password', ['email' => ['a@b.c']])->assertSessionHasErrors('email');
        $this->get('/reset-password/sometoken?email[]=x')->assertOk();
        $this->post('/reset-password', ['token' => 't', 'email' => ['a@b.c'], 'password' => 'newpassword1', 'password_confirmation' => 'newpassword1'])->assertSessionHasErrors('email');

        $resident = User::where('email', 'resident@ecowatch.com')->first();
        $this->actingAs($resident)->put('/account/password', ['current_password' => ['x'], 'password' => 'newpassword1', 'password_confirmation' => 'newpassword1'])->assertSessionHasErrors('current_password');
        $this->put('/account/password', ['current_password' => 'resident123', 'password' => ['x'], 'password_confirmation' => ['x']])->assertSessionHasErrors('password');
        $this->from('/account')->followingRedirects()->put('/account/profile', ['name' => ['x'], 'email' => ['y'], 'phone' => ['z']])->assertOk();
    }

    public function test_collection_notes_keep_the_truck_and_route_details_from_when_they_were_written(): void
    {
        $collector = $this->loginAs('collector@ecowatch.com');
        $truck = Truck::where('collector_id', User::where('email', 'collector@ecowatch.com')->value('id'))->first();
        $route = TruckRoute::where('truck_id', $truck->id)->first();
        $originalRouteName = $route->name;
        $originalLabel = $truck->label;
        $collector->post('/collector/notes', ['truck_route_id' => $route->id, 'note' => 'Blocked by a parked truck'])->assertSessionHasNoErrors();
        $note = CollectionNote::where('note', 'Blocked by a parked truck')->firstOrFail();
        $this->assertSame($originalLabel, $note->truck_label);
        $this->assertSame($originalRouteName, $note->route_name);

        // Later the route is renamed and moved to another truck. The note keeps what it said.
        $other = Truck::where('truck_code', 'TRUCK-005')->first();
        $route->update(['name' => 'Renamed Route', 'truck_id' => $other->id]);
        $this->loginAs('admin@ecowatch.com')->get('/cgso/collections')->assertOk()->assertSee($originalRouteName)->assertSee($originalLabel);
        $this->assertSame($originalRouteName, $note->fresh()->route_text);

        // Even if the route is deleted, the note still shows it.
        $route->delete();
        $this->get('/cgso/collections')->assertOk()->assertSee($originalRouteName);
    }

    public function test_demo_seeder_can_run_again_after_deleting_all_trucks(): void
    {
        $reportCount = Report::count();
        $codesBefore = Report::pluck('code')->all();

        // Delete all trucks the way an old database allowed it.
        Schema::disableForeignKeyConstraints();
        DB::table('trucks')->delete();
        Schema::enableForeignKeyConstraints();

        (new DatabaseSeeder())->run();
        $this->assertSame(5, Truck::count());
        $this->assertSame($reportCount, Report::count()); // existing demo reports are not copied
        $this->assertSame(Report::count(), Report::distinct()->count('code'));

        // Running it a third time changes nothing.
        $routesBefore = TruckRoute::count();
        (new DatabaseSeeder())->run();
        $this->assertSame(5, Truck::count());
        $this->assertSame($routesBefore, TruckRoute::count());
        $this->assertSame($reportCount, Report::count());
        $this->assertEqualsCanonicalizing($codesBefore, Report::pluck('code')->all());
    }

    public function test_demo_seeder_does_not_reuse_a_code_of_a_real_report(): void
    {
        // A real report took the code RPT-1001. Remove the demo reports, keep the real one, run the seeder.
        $real = Report::where('code', 'RPT-1001')->first();
        Report::where('id', '!=', $real->id)->each(function ($report) {
            $report->logs()->delete();
            $report->assignments()->delete();
            $report->reviews()->delete();
            $report->delete();
        });
        $real->update(['submission_token' => null, 'description' => 'A real resident report']);

        (new DatabaseSeeder())->run();
        $this->assertSame('A real resident report', $real->fresh()->description);
        $this->assertSame(Report::count(), Report::distinct()->count('code'));
        $this->assertSame(1, Report::where('code', 'RPT-1001')->count());
    }

    // ---------------------------------------------------------------- 3. Report workflow

    public function test_resident_report_needs_a_photo_in_the_form_and_on_the_server(): void
    {
        $resident = User::where('email', 'resident@ecowatch.com')->first();
        $this->actingAs($resident)->get('/resident/reports/create')->assertSee('type="file" name="photo"', false)->assertSee('required', false);
        $data = ['category' => 'Illegal Dumping', 'barangay_id' => 1, 'location_text' => 'No Photo', 'lat' => 9.79, 'lng' => 125.49, 'submission_token' => (string) \Illuminate\Support\Str::uuid()];
        $this->post('/resident/reports', $data)->assertSessionHasErrors('photo');
        $this->assertSame(0, Report::where('location_text', 'No Photo')->count());
    }

    public function test_full_workflow_resident_collector_cgso_with_feedback_only(): void
    {
        $resident = User::where('email', 'resident@ecowatch.com')->first();
        $this->actingAs($resident)->post('/resident/reports', [
            'category' => 'Illegal Dumping', 'barangay_id' => $resident->barangay_id, 'location_text' => 'Flow Street', 'description' => 'Pile of trash',
            'lat' => 9.79, 'lng' => 125.49, 'photo' => $this->photo(), 'submission_token' => (string) \Illuminate\Support\Str::uuid(),
        ])->assertSessionHasNoErrors();
        $report = Report::where('location_text', 'Flow Street')->firstOrFail();
        $juan = User::where('email', 'collector@ecowatch.com')->first();
        $pedro = User::where('email', 'pedro@ecowatch.com')->first();

        $admin = $this->loginAs('admin@ecowatch.com');
        $admin->post("/cgso/reports/{$report->id}/assign", ['collector_id' => $juan->id])->assertSessionHasNoErrors();
        // Collector can change before the cleanup starts.
        $admin->post("/cgso/reports/{$report->id}/assign", ['collector_id' => $pedro->id, 'reason' => 'Closer crew'])->assertSessionHasNoErrors();

        $pedroSession = $this->loginAs('pedro@ecowatch.com');
        $pedroSession->post("/collector/reports/{$report->id}/start")->assertSessionHasNoErrors();
        $this->assertSame('In Progress', $report->fresh()->status);

        // Blocked while In Progress, also by a direct request.
        $this->loginAs('admin@ecowatch.com')->post("/cgso/reports/{$report->id}/assign", ['collector_id' => $juan->id, 'reason' => 'Try'])->assertSessionHasErrors('collector_id');
        $this->assertSame($pedro->id, $report->fresh()->collector_id);

        $this->loginAs('pedro@ecowatch.com')->post("/collector/reports/{$report->id}/evidence", ['before_photo' => $this->photo(), 'after_photo' => $this->photo()])->assertSessionHasNoErrors();
        $this->assertSame('For Verification', $report->fresh()->status);

        // Resident Yes/No is feedback only.
        $this->actingAs($resident)->post("/resident/reports/{$report->id}/feedback", ['resolved' => 'yes'])->assertSessionHasNoErrors();
        $this->assertSame('For Verification', $report->fresh()->status);
        $this->post("/resident/reports/{$report->id}/feedback", ['resolved' => 'no'])->assertSessionHasNoErrors();
        $this->assertSame('For Verification', $report->fresh()->status);

        // CGSO rejects, may change the collector, collector redoes it, CGSO approves.
        $admin = $this->loginAs('admin@ecowatch.com');
        $admin->post("/cgso/reports/{$report->id}/reject", ['rejection_reason' => 'Still trash in the photo'])->assertSessionHasNoErrors();
        $this->assertSame('Reopened', $report->fresh()->status);
        $admin->post("/cgso/reports/{$report->id}/assign", ['collector_id' => $juan->id, 'reason' => 'Pedro is off'])->assertSessionHasNoErrors();
        $this->loginAs('collector@ecowatch.com')->post("/collector/reports/{$report->id}/start")->assertSessionHasNoErrors();
        $this->post("/collector/reports/{$report->id}/evidence", ['before_photo' => $this->photo(), 'after_photo' => $this->photo()])->assertSessionHasNoErrors();
        $this->loginAs('admin@ecowatch.com')->post("/cgso/reports/{$report->id}/approve")->assertSessionHasNoErrors();
        $this->assertSame('Resolved', $report->fresh()->status);
        $this->actingAs($resident)->post("/resident/reports/{$report->id}/feedback", ['resolved' => 'yes'])->assertSessionHasNoErrors();
        $this->assertSame('Resolved', $report->fresh()->status);

        foreach (Report::where('id', $report->id)->get() as $row) {
            foreach (array_filter([$row->photo_path, $row->before_photo, $row->after_photo]) as $path) {
                if (str_starts_with($path, 'uploads/') && file_exists(public_path($path))) {
                    unlink(public_path($path));
                }
            }
        }
    }
}
