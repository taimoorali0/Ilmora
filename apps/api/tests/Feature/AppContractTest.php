<?php

namespace Tests\Feature;

use App\Services\Platform\SchoolProvisioner;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What the app relies on: the exact rows its screens upload are accepted, a new
 * school is usable on day one, and each role may do what its screens offer.
 */
class AppContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
    }

    private function login(string $phone, string $school = 'demo', string $password = 'password'): array
    {
        $r = $this->postJson('/api/v1/auth/login', ['login' => $phone, 'password' => $password, 'school' => $school, 'platform' => 'web'])->assertOk();

        return ['Authorization' => 'Bearer '.$r['token'], 'X-Device-Id' => $r['device']['id']];
    }

    public function json($method, $uri, array $data = [], array $headers = [], $options = 0)
    {
        $this->app['auth']->forgetGuards();

        return parent::json($method, $uri, $data, $headers, $options);
    }

    private function demo(): string
    {
        return DB::table('schools')->where('slug', 'demo')->value('id');
    }

    private function upload(array $headers, string $table, array $data): string
    {
        $branch = DB::table('branches')->where('code', 'B1')->whereIn('school_id', DB::table('schools')->where('slug', 'demo')->select('id'))->value('id');
        $r = $this->postJson('/api/v1/sync/upload', ['ops' => [['op' => 'PUT', 'table' => $table, 'id' => (string) Str::uuid(), 'data' => $data + ['branch_id' => $branch]]]], $headers)->assertOk();

        return $r['results'][0]['result'].(isset($r['results'][0]['message']) ? ': '.$r['results'][0]['message'] : '');
    }

    public function test_every_message_kind_the_app_sends_is_accepted(): void
    {
        $owner = $this->login('03000000001');
        $family = DB::table('families')->where('school_id', $this->demo())->value('id');
        foreach (['late_fee', 'fee_voucher', 'absent', 'attendance_report', 'result', 'homework', 'general'] as $kind) {
            $this->assertSame('applied', $this->upload($owner, 'messages', ['family_id' => $family, 'to_name' => 'Parent', 'to_phone' => '03001234567',
                'channel' => 'whatsapp', 'kind' => $kind, 'body' => 'Test']), $kind);
        }
        // Delivery status is the server's to set.
        $this->assertSame(7, DB::table('messages')->where('body', 'Test')->where('status', 'queued')->count());
    }

    public function test_teacher_can_message_parents_and_request_leave_but_not_touch_fees(): void
    {
        $teacher = $this->login('03000000002');
        $staffId = DB::table('users')->where('phone', '03000000002')->value('staff_id');
        $family = DB::table('families')->where('school_id', $this->demo())->value('id');
        $this->assertSame('applied', $this->upload($teacher, 'messages', ['family_id' => $family, 'to_name' => 'P', 'to_phone' => '03001234567', 'channel' => 'whatsapp', 'kind' => 'absent', 'body' => 'x']));
        $this->assertSame('applied', $this->upload($teacher, 'staff_leaves', ['staff_id' => $staffId, 'leave_type_id' => DB::table('leave_types')->where('school_id', $this->demo())->value('id'),
            'from_date' => '2026-12-01', 'to_date' => '2026-12-01', 'half_day' => false, 'reason' => 'Personal']));
        $this->assertStringStartsWith('rejected', $this->upload($teacher, 'expenses', ['category' => 'Other', 'description' => 'x', 'amount' => 100, 'spent_on' => '2026-11-01']));
        $this->assertStringStartsWith('rejected', $this->upload($teacher, 'payments', ['family_id' => $family, 'receipt_no' => 'B1-D99-000001', 'amount' => 100, 'method' => 'cash', 'received_at' => now()->toIso8601String()]));
    }

    public function test_admin_officer_sees_own_payslips_but_not_everyone_elses(): void
    {
        $res = $this->getJson('/api/v1/sync/pull', $this->login('03000000005'))->assertOk()->json();
        $staffId = DB::table('users')->where('phone', '03000000005')->value('staff_id');
        $this->assertNotEmpty($res['tables']['payslips']);
        foreach ($res['tables']['payslips'] as $slip) {
            $this->assertSame($staffId, $slip['staff_id']);
        }
        $this->assertArrayNotHasKey('invoices', $res['tables']);
    }

    public function test_a_new_school_can_admit_students_on_day_one(): void
    {
        $school = app(SchoolProvisioner::class)->create(['name' => 'Day One School', 'slug' => 'dayone', 'owner_name' => 'Owner',
            'owner_phone' => '03123456789', 'owner_password' => 'secret12', 'plan_id' => DB::table('plans')->value('id')]);
        $this->assertSame(13, DB::table('sections')->where('school_id', $school->id)->count());
        $this->assertSame(5, DB::table('subjects')->where('school_id', $school->id)->count());
        $this->assertSame(65, DB::table('section_subjects')->where('school_id', $school->id)->count());

        $owner = $this->login('03123456789', 'dayone', 'secret12');
        $res = $this->getJson('/api/v1/sync/pull', $owner)->assertOk()->json();
        $this->assertCount(13, $res['tables']['sections']);
        foreach ($res['tables'] as $rows) {
            foreach ($rows as $row) {
                $this->assertSame($school->id, $row['school_id']); // nothing from the demo school leaks in
            }
        }
    }

    public function test_install_command_creates_plans_and_super_admin(): void
    {
        DB::table('subscriptions')->delete();
        DB::table('plans')->delete();
        $this->artisan('ilmora:install', ['--email' => 'boss@example.com', '--password' => 'a-long-password'])->assertSuccessful();
        $this->assertSame(3, DB::table('plans')->count());
        $this->postJson('/api/v1/platform/login', ['email' => 'boss@example.com', 'password' => 'a-long-password'])->assertOk()->assertJsonStructure(['token']);
        $this->artisan('ilmora:install', ['--email' => 'bad', '--password' => 'short'])->assertFailed();
    }
}
