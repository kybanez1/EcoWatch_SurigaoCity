<?php

namespace Tests\Feature;

use App\Models\Report;
use App\Models\Schedule;
use App\Models\Truck;
use App\Models\TruckRoute;
use App\Models\User;
use Tests\Concerns\FreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class EcoWatchTest extends TestCase
{
    use FreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function as(string $email): static
    {
        return $this->actingAs(User::where('email', $email)->firstOrFail());
    }

    public function test_public_pages_load(): void
    {
        foreach (['/', '/schedule', '/schedule?day=Mon&barangay=1', '/trucks-routes', '/announcements', '/login', '/register'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/schedule')->assertSee('Truck #01')->assertSee('San Juan');
    }

    public function test_login_redirects_by_role_and_wrong_password_fails(): void
    {
        $this->post('/login', ['email' => 'admin@ecowatch.com', 'password' => 'admin123'])->assertRedirect('/cgso/dashboard');
        auth()->logout();
        $this->post('/login', ['email' => 'resident@ecowatch.com', 'password' => 'resident123'])->assertRedirect('/resident/dashboard');
        auth()->logout();
        $this->post('/login', ['email' => 'collector@ecowatch.com', 'password' => 'collector123'])->assertRedirect('/collector/dashboard');
        auth()->logout();
        $this->post('/login', ['email' => 'official.sanjuan@ecowatch.com', 'password' => 'official123'])->assertRedirect('/official/dashboard');
        auth()->logout();
        $this->post('/login', ['email' => 'admin@ecowatch.com', 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertNotEquals('admin123', User::where('email', 'admin@ecowatch.com')->value('password'));
    }

    public function test_role_security(): void
    {
        $this->get('/cgso/dashboard')->assertRedirect('/login');
        $this->as('resident@ecowatch.com')->get('/cgso/dashboard')->assertForbidden();
        $this->as('resident@ecowatch.com')->get('/cgso/trucks')->assertForbidden();
        $this->as('resident@ecowatch.com')->get('/collector/dashboard')->assertForbidden();
        $this->as('collector@ecowatch.com')->get('/cgso/reports')->assertForbidden();
        $this->as('collector@ecowatch.com')->get('/resident/dashboard')->assertForbidden();
        $this->as('official.sanjuan@ecowatch.com')->get('/cgso/users')->assertForbidden();
        $this->as('admin@ecowatch.com')->get('/resident/dashboard')->assertForbidden();
    }

    public function test_all_cgso_pages_render(): void
    {
        foreach (['dashboard', 'routes', 'routes/1/edit', 'trucks', 'schedules', 'reports', 'reports?status=Resolved&search=RPT', 'collections', 'announcements', 'users'] as $p) {
            $this->as('admin@ecowatch.com')->get("/cgso/$p")->assertOk();
        }
        $this->as('admin@ecowatch.com')->get('/cgso/dashboard')->assertSee('Total Trucks')->assertSee('Reports by Category');
    }

    public function test_all_citizen_and_collector_pages_render(): void
    {
        foreach (['resident@ecowatch.com' => 'resident', 'official.sanjuan@ecowatch.com' => 'official'] as $email => $r) {
            foreach (['dashboard', 'reports', 'reports/create', 'route-map'] as $p) {
                $this->as($email)->get("/$r/$p")->assertOk();
            }
        }
        $this->as('collector@ecowatch.com')->get('/collector/dashboard')->assertOk()->assertSee('Washington Street');
    }

    public function test_dashboard_counts_come_from_database(): void
    {
        $r = $this->as('admin@ecowatch.com')->get('/cgso/dashboard');
        $r->assertViewHas('stats', fn ($s) => $s['Total Trucks'] === Truck::count() && $s['Unresolved Reports'] === 3 && $s['Resolved Reports'] === 1 && $s['Total Collectors'] === 5);
        Truck::create(['truck_code' => 'TRUCK-006', 'plate_number' => 'ZZZ-1', 'capacity_tons' => 5]);
        $this->as('admin@ecowatch.com')->get('/cgso/dashboard')->assertViewHas('stats', fn ($s) => $s['Total Trucks'] === 6);
    }

    public function test_official_cannot_give_feedback_on_or_see_others_reports(): void
    {
        $other = Report::where('code', 'RPT-1002')->first(); // Maria's
        $this->as('official.sanjuan@ecowatch.com')->post("/official/reports/{$other->id}/feedback", ['resolved' => 'yes'])->assertForbidden();
        $this->as('official.sanjuan@ecowatch.com')->get('/official/reports')->assertSee('RPT-1003'); // own
    }

    public function test_truck_crud_and_unique_code(): void
    {
        $a = $this->as('admin@ecowatch.com');
        $a->post('/cgso/trucks', ['truck_code' => 'TRUCK-001', 'plate_number' => 'NEW-1', 'capacity_tons' => 5, 'status' => 'Available'])->assertSessionHasErrors('truck_code');
        $a->post('/cgso/trucks', ['truck_code' => 'TRUCK-006', 'plate_number' => 'NEW-1', 'capacity_tons' => 5, 'status' => 'Available'])->assertSessionHasNoErrors();
        $t = Truck::where('truck_code', 'TRUCK-006')->firstOrFail();
        $a->put("/cgso/trucks/{$t->id}", ['truck_code' => 'TRUCK-006', 'plate_number' => 'NEW-2', 'capacity_tons' => 7, 'status' => 'Maintenance'])->assertSessionHasNoErrors();
        $this->assertSame('NEW-2', $t->fresh()->plate_number);
        $a->put("/cgso/trucks/{$t->id}", ['truck_code' => 'bad', 'plate_number' => 'X', 'capacity_tons' => 7, 'status' => 'Available'])->assertSessionHasErrors('truck_code');
        // collector can only drive one truck
        $c = User::where('email', 'collector@ecowatch.com')->first();
        $a->put("/cgso/trucks/{$t->id}", ['truck_code' => 'TRUCK-006', 'plate_number' => 'NEW-2', 'capacity_tons' => 7, 'status' => 'Available', 'collector_id' => $c->id])->assertSessionHasErrors('collector_id');
        $a->patch("/cgso/trucks/{$t->id}/status", ['status' => 'On Route']);
        $this->assertSame('On Route', $t->fresh()->status);
        $a->delete("/cgso/trucks/{$t->id}");
        $this->assertNull(Truck::find($t->id));
    }

    public function test_schedule_crud_toggle_and_reassign_truck(): void
    {
        $a = $this->as('admin@ecowatch.com');
        $payload = ['truck_id' => 3, 'barangay_id' => 8, 'days' => ['Tue', 'Fri'], 'pickup_time' => '09:00'];
        $a->post('/cgso/schedules', $payload)->assertSessionHasNoErrors();
        $s = Schedule::where('barangay_id', 8)->firstOrFail();
        $this->assertSame(['Tue', 'Fri'], $s->days);
        $a->post('/cgso/schedules', $payload)->assertSessionHasErrors('pickup_time'); // same truck, same time, same day
        $a->post('/cgso/schedules', ['days' => []] + $payload)->assertSessionHasErrors('days');
        $a->put("/cgso/schedules/{$s->id}", ['truck_id' => 2, 'days' => ['Mon']] + $payload)->assertSessionHasNoErrors(); // reassign truck
        $this->assertSame(2, $s->fresh()->truck_id);
        $a->put("/cgso/schedules/{$s->id}", ['truck_id' => 2, 'truck_route_id' => 1] + $payload)->assertSessionHasErrors('truck_route_id'); // route of other truck
        $a->put("/cgso/schedules/{$s->id}", ['truck_id' => 2, 'truck_route_id' => 2] + $payload)->assertSessionHasErrors('truck_route_id'); // route of another barangay
        $a->patch("/cgso/schedules/{$s->id}/toggle");
        $this->assertFalse($s->fresh()->is_active);
        $a->delete("/cgso/schedules/{$s->id}");
        $this->assertNull(Schedule::find($s->id));
    }

    public function test_route_assignment_creates_linked_schedule(): void
    {
        $a = $this->as('admin@ecowatch.com');
        $a->post('/cgso/routes', ['truck_id' => 5, 'name' => 'Route F', 'barangay_id' => 7, 'days' => ['Mon', 'Wed'], 'pickup_time' => '15:00',
            'path' => json_encode([[9.79, 125.49], [9.791, 125.492]]), 'streets' => json_encode(['A St', 'B St'])])->assertSessionHasNoErrors();
        $r = TruckRoute::where('name', 'Route F')->firstOrFail();
        $this->assertCount(2, $r->path);
        $this->assertCount(2, $r->areas);
        $this->assertSame(['Mon', 'Wed'], $r->schedules()->first()->days);
        $a->put("/cgso/routes/{$r->id}", ['truck_id' => 5, 'name' => 'Route F', 'barangay_id' => 7, 'days' => ['Sat'], 'pickup_time' => '13:00', 'path' => '[]', 'streets' => '["A St"]']);
        $this->assertSame(1, $r->areas()->count());
        $this->assertSame(1, Schedule::where('truck_route_id', $r->id)->count());
        $a->post('/cgso/routes', ['truck_id' => 5, 'name' => 'Route F', 'barangay_id' => 7, 'days' => ['Mon'], 'pickup_time' => 'x'])->assertSessionHasErrors('name');
        $a->delete("/cgso/routes/{$r->id}");
        $this->assertNull(TruckRoute::find($r->id));
    }

    public function test_collector_updates_progress_only_for_own_truck(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-11-02 09:00:00'); // a Monday: Route A is scheduled
        $col = $this->as('collector@ecowatch.com');
        $area = TruckRoute::first()->areas()->first();
        $col->post("/collector/areas/{$area->id}", ['status' => 'Collecting'])->assertSessionHasNoErrors();
        $this->assertSame('Collecting', $area->fresh()->today_status);
        $col->post("/collector/areas/{$area->id}", ['status' => 'Done'])->assertSessionHasNoErrors();
        $this->assertSame('Done', $area->fresh()->today_status);
        $col->post("/collector/areas/{$area->id}", ['status' => 'Nonsense'])->assertSessionHasErrors('status');
        $other = TruckRoute::where('truck_id', 2)->first()->areas()->first();
        $col->post("/collector/areas/{$other->id}", ['status' => 'Done'])->assertForbidden();
        $col->post('/collector/notes', ['truck_route_id' => 1, 'note' => 'Road blocked'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('collection_notes', ['note' => 'Road blocked']);
        $this->as('admin@ecowatch.com')->get('/cgso/dashboard')->assertOk();
    }

    public function test_announcements_barangays_and_users(): void
    {
        $a = $this->as('admin@ecowatch.com');
        $a->post('/cgso/announcements', ['category' => 'General Notice', 'title' => 'Hello', 'body' => 'World'])->assertSessionHasNoErrors();
        $this->get('/announcements')->assertSee('Hello');
        $a->delete('/cgso/announcements/'.\App\Models\Announcement::where('title', 'Hello')->value('id'));
        $this->assertDatabaseMissing('announcements', ['title' => 'Hello']);

        $a->post('/cgso/barangays', ['name' => 'Mat-i'])->assertSessionHasNoErrors();
        $a->post('/cgso/barangays', ['name' => 'Mat-i'])->assertSessionHasErrors('name');
        $b = \App\Models\Barangay::where('name', 'Mat-i')->first();
        $a->put("/cgso/barangays/{$b->id}", ['name' => 'Mati'])->assertSessionHasNoErrors();
        $a->post('/cgso/officials', ['barangay_id' => $b->id, 'name' => 'Off', 'email' => 'o@x.com', 'password' => 'secret123'])->assertSessionHasNoErrors();
        $a->post('/cgso/officials', ['barangay_id' => $b->id, 'name' => 'Off2', 'email' => 'o2@x.com', 'password' => 'secret123'])->assertSessionHasErrors('barangay_id');
        $a->delete("/cgso/barangays/{$b->id}")->assertSessionHasErrors('barangay'); // still has an official
        $a->post('/cgso/collectors', ['name' => 'Col', 'email' => 'c@x.com', 'password' => 'secret123'])->assertSessionHasNoErrors();
        $a->delete('/cgso/users/'.User::where('email', 'c@x.com')->value('id'));
        $this->assertNull(User::where('email', 'c@x.com')->first());
    }

    public function test_resident_registration_and_logout(): void
    {
        $this->post('/register', ['name' => 'New Res', 'email' => 'new@x.com', 'password' => 'secret123', 'password_confirmation' => 'secret123', 'barangay_id' => 2])->assertRedirect('/resident/dashboard');
        $this->assertSame('resident', User::where('email', 'new@x.com')->value('role'));
        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_report_filters(): void
    {
        $a = $this->as('admin@ecowatch.com');
        $a->get('/cgso/reports?status=Resolved')->assertViewHas('reports', fn ($r) => $r->count() === 1);
        $a->get('/cgso/reports?search=Roberto')->assertViewHas('reports', fn ($r) => $r->count() === 1);
        $a->get('/cgso/reports?barangay_id=1')->assertViewHas('reports', fn ($r) => $r->count() === 2);
        $this->get('/schedule?day=Sat')->assertViewHas('schedules', fn ($s) => $s->count() === 3);
    }
}
