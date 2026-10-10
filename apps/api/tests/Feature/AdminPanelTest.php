<?php

namespace Tests\Feature;

use App\Filament\Resources\Schools\Pages\CreateSchool;
use App\Filament\Resources\Schools\Pages\ListSchools;
use App\Models\Plan;
use App\Models\School;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function superAdmin(): User
    {
        return User::where('is_super_admin', true)->firstOrFail();
    }

    public function test_guest_is_sent_to_login(): void
    {
        $this->get('/admin/schools')->assertRedirect('/admin/login');
    }

    public function test_school_owner_cannot_open_admin_panel(): void
    {
        $owner = User::where('phone', '03000000001')->firstOrFail();
        $this->actingAs($owner)->get('/admin/schools')->assertForbidden();
    }

    public function test_super_admin_can_open_admin_panel(): void
    {
        $this->actingAs($this->superAdmin())->get('/admin/schools')->assertOk()->assertSee('Demo Public School');
    }

    public function test_creating_a_school_sets_up_branch_owner_subscription_and_accounts(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(CreateSchool::class)
            ->fillForm([
                'name' => 'City Grammar School', 'slug' => 'city-grammar', 'offline_access_days' => 7,
                'branch_name' => 'Main Campus', 'owner_name' => 'Ayesha Khan', 'owner_phone' => '03111234567',
                'owner_password' => 'Strong-pass-123', 'plan_id' => Plan::first()->id, 'ends_on' => now()->addYear()->toDateString(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $school = School::where('slug', 'city-grammar')->firstOrFail();
        $owner = User::where('school_id', $school->id)->where('phone', '03111234567')->firstOrFail();
        $this->assertTrue(Hash::check('Strong-pass-123', $owner->password));
        $this->assertSame(['owner'], $owner->roles->pluck('role')->all());
        $this->assertSame(1, DB::table('branches')->where('school_id', $school->id)->count());
        $this->assertSame(1, DB::table('subscriptions')->where('school_id', $school->id)->count());
        $this->assertSame(7, DB::table('accounts')->where('school_id', $school->id)->count());

        $log = DB::table('audit_logs')->where('action', 'create_school')->where('row_id', $school->id)->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString('Strong-pass-123', (string) $log->after);

        // The new owner can sign in to the app with the school code.
        config(['ilmora.powersync.secret' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=')]);
        $this->postJson('/api/v1/auth/login', ['login' => '03111234567', 'password' => 'Strong-pass-123',
            'school' => 'city-grammar', 'platform' => 'web'])->assertOk();
    }

    public function test_weak_owner_password_and_bad_slug_are_rejected(): void
    {
        $this->actingAs($this->superAdmin());
        Livewire::test(CreateSchool::class)
            ->fillForm(['name' => 'X School', 'slug' => 'Bad Slug!', 'owner_password' => 'short', 'owner_phone' => '123',
                'branch_name' => 'Main', 'owner_name' => 'X', 'plan_id' => Plan::first()->id, 'ends_on' => now()->addYear()->toDateString()])
            ->call('create')
            ->assertHasFormErrors(['slug', 'owner_password', 'owner_phone']);
        $this->assertFalse(School::where('name', 'X School')->exists());
    }

    public function test_suspend_blocks_school_logins_and_is_audited(): void
    {
        $this->actingAs($this->superAdmin());
        $school = School::where('slug', 'demo')->firstOrFail();

        Livewire::test(ListSchools::class)->callTableAction('suspend', $school);

        $this->assertSame('suspended', $school->fresh()->status);
        $this->assertTrue(DB::table('audit_logs')->where('action', 'school_status')->where('row_id', $school->id)->exists());
        $this->postJson('/api/v1/auth/login', ['login' => '03000000001', 'password' => 'password', 'school' => 'demo',
            'platform' => 'web'])->assertStatus(422);
    }
}
