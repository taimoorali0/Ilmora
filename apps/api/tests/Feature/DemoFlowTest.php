<?php

namespace Tests\Feature;

use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * End-to-end on the demo data: pull scoping per role, voucher run, offline payment,
 * payroll, logins for staff and parents, and the platform console.
 */
class DemoFlowTest extends TestCase
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

    /** Each call is a separate device request: never reuse the previous request's resolved user. */
    public function json($method, $uri, array $data = [], array $headers = [], $options = 0)
    {
        $this->app['auth']->forgetGuards();

        return parent::json($method, $uri, $data, $headers, $options);
    }

    private function pull(array $headers, string $query = ''): array
    {
        return $this->getJson('/api/v1/sync/pull'.$query, $headers)->assertOk()->json();
    }

    public function test_owner_pulls_the_whole_school_without_secrets(): void
    {
        $res = $this->pull($this->login('03000000001'));
        $this->assertCount(130, $res['tables']['students']);
        $this->assertCount(18, $res['tables']['staff']);
        $this->assertNotEmpty($res['tables']['invoices']);
        $this->assertNotEmpty($res['tables']['payslips']);
        $this->assertArrayNotHasKey('password', $res['tables']['users'][0]);
        $school = DB::table('schools')->where('slug', 'demo')->value('id');
        foreach ($res['tables'] as $rows) {
            foreach ($rows as $row) {
                $this->assertSame($school, $row['school_id']);
            }
        }
    }

    public function test_teacher_gets_only_own_sections_and_no_finance(): void
    {
        $res = $this->pull($this->login('03000000002'));
        $this->assertArrayNotHasKey('invoices', $res['tables']);
        $this->assertArrayNotHasKey('payments', $res['tables']);
        $this->assertArrayNotHasKey('users', $res['tables']);
        $this->assertGreaterThan(0, count($res['tables']['students']));
        $this->assertLessThan(130, count($res['tables']['students']));
        $this->assertCount(1, $res['tables']['staff']);
        $this->assertCount(1, $res['tables']['payslips']);
    }

    public function test_parent_gets_only_own_family(): void
    {
        $res = $this->pull($this->login('03000000007'));
        $family = DB::table('users')->where('phone', '03000000007')->value('family_id');
        $this->assertCount(1, $res['tables']['families']);
        $this->assertSame($family, $res['tables']['families'][0]['id']);
        foreach (['students', 'invoices', 'payments'] as $t) {
            foreach ($res['tables'][$t] as $row) {
                $this->assertSame($family, $row['family_id']);
            }
        }
        $this->assertArrayNotHasKey('staff', $res['tables']);
        $this->assertArrayNotHasKey('exam_papers', $res['tables']);
        // Parents may not write anything through sync.
        $this->postJson('/api/v1/sync/upload', ['ops' => [['op' => 'PATCH', 'table' => 'students', 'id' => $res['tables']['students'][0]['id'],
            'data' => ['name' => 'Hacked'], 'base_version' => 1]]], $this->login('03000000007'))
            ->assertOk()->assertJsonPath('results.0.result', 'rejected');
    }

    public function test_incremental_pull_returns_only_changes(): void
    {
        $this->travel(1)->minutes(); // past the seeding moment
        $headers = $this->login('03000000001');
        $this->travel(1)->minutes(); // past the login, which touches the user row
        $first = $this->pull($headers);
        $this->travel(1)->minutes();
        $student = DB::table('students')->first();
        $this->postJson('/api/v1/sync/upload', ['ops' => [['op' => 'PATCH', 'table' => 'students', 'id' => $student->id,
            'data' => ['notes' => 'Needs glasses'], 'base_version' => $student->version]]], $headers)
            ->assertOk()->assertJsonPath('results.0.result', 'applied');
        $this->travel(1)->minutes();
        $next = $this->pull($headers, '?since='.urlencode($first['cursor']));
        $this->assertSame(['students'], array_keys($next['tables']));
        $this->assertSame([], $this->pull($headers, '?since='.urlencode($next['cursor']))['tables']);
        $this->assertCount(1, $next['tables']['students']);
        $this->assertSame('Needs glasses', $next['tables']['students'][0]['notes']);
    }

    public function test_voucher_run_bills_every_family_once_and_books_balance(): void
    {
        $headers = $this->login('03000000004'); // accountant
        $branch = DB::table('branches')->where('code', 'B1')->where('school_id', DB::table('schools')->where('slug', 'demo')->value('id'))->value('id');
        $period = now()->addMonth()->format('Y-m');
        $res = $this->postJson('/api/v1/finance/voucher-run', ['period' => $period, 'branch_id' => $branch], $headers)->assertOk()->json();
        $this->assertSame(87, $res['created']);
        $this->assertGreaterThan(0, $res['total']);
        // Running again changes nothing.
        $again = $this->postJson('/api/v1/finance/voucher-run', ['period' => $period, 'branch_id' => $branch], $headers)->assertOk()->json();
        $this->assertSame(0, $again['created']);
        $this->assertSame(87, $again['skipped']);
        // Every journal balances.
        $this->assertSame((int) DB::table('ledger_entries')->sum('debit'), (int) DB::table('ledger_entries')->sum('credit'));
        // Teachers cannot run vouchers.
        $this->postJson('/api/v1/finance/voucher-run', ['period' => $period, 'branch_id' => $branch], $this->login('03000000002'))->assertForbidden();
    }

    public function test_offline_payment_is_allocated_once(): void
    {
        $headers = $this->login('03000000004');
        $family = DB::table('users')->where('phone', '03000000007')->value('family_id'); // owes this month
        $branch = DB::table('families')->where('id', $family)->value('branch_id');
        $op = ['op' => 'PUT', 'table' => 'payments', 'id' => (string) Str::uuid(), 'data' => ['family_id' => $family, 'branch_id' => $branch,
            'receipt_no' => 'B1-D09-000001', 'amount' => 1000, 'method' => 'cash', 'received_at' => now()->toIso8601String()]];
        $this->postJson('/api/v1/sync/upload', ['ops' => [$op]], $headers)->assertOk()->assertJsonPath('results.0.result', 'applied');
        $this->postJson('/api/v1/sync/upload', ['ops' => [$op]], $headers)->assertOk()->assertJsonPath('results.0.result', 'ignored_duplicate');
        $this->assertSame(1000, (int) DB::table('payment_allocations')->where('payment_id', $op['id'])->sum('amount'));
        $this->assertSame((int) DB::table('ledger_entries')->sum('debit'), (int) DB::table('ledger_entries')->sum('credit'));
    }

    public function test_payroll_draft_approve_and_pay(): void
    {
        $accountant = $this->login('03000000004');
        $branch = DB::table('staff')->value('branch_id');
        $period = now()->format('Y-m');
        $draft = $this->postJson('/api/v1/payroll/draft', ['period' => $period, 'branch_id' => $branch], $accountant)->assertOk()->json();
        $this->assertSame(18, $draft['payslips']);
        $this->assertSame([], $draft['blocked']);
        $this->assertGreaterThan(0, $draft['net']);
        // The accountant prepares, the principal approves (D5).
        $this->postJson("/api/v1/payroll/{$draft['run_id']}/approve", [], $accountant)->assertForbidden();
        $principal = $this->login('03000000003');
        $this->postJson("/api/v1/payroll/{$draft['run_id']}/approve", [], $principal)->assertOk();
        $this->assertSame(0, DB::table('staff_attendance_days')->where('shift_date', '>=', "$period-01")->where('locked', false)->count());
        $this->postJson('/api/v1/payroll/draft', ['period' => $period, 'branch_id' => $branch], $accountant)->assertStatus(422);
        $this->postJson("/api/v1/payroll/{$draft['run_id']}/pay", [], $principal)->assertOk();
        $this->assertSame('paid', DB::table('payroll_runs')->where('id', $draft['run_id'])->value('status'));
    }

    public function test_principal_creates_a_teacher_login_and_decides_leave(): void
    {
        $principal = $this->login('03000000003');
        $staff = DB::table('staff')->where('name', 'Sadia Noor')->value('id');
        $this->postJson('/api/v1/users', ['name' => 'Sadia Noor', 'phone' => '03001112223', 'password' => 'secret12', 'role' => 'teacher', 'staff_id' => $staff], $principal)->assertOk();
        $this->login('03001112223', 'demo', 'secret12');
        $this->postJson('/api/v1/users', ['name' => 'X', 'phone' => '03001112224', 'password' => 'secret12', 'role' => 'teacher'], $this->login('03000000002'))->assertForbidden();

        $leave = DB::table('staff_leaves')->where('status', 'requested')->value('id');
        $this->postJson("/api/v1/staff-leaves/$leave/decide", ['status' => 'approved'], $this->login('03000000003'))->assertOk();
        $this->assertSame('approved', DB::table('staff_leaves')->where('id', $leave)->value('status'));
    }

    public function test_super_admin_creates_a_school_and_its_owner_can_sign_in(): void
    {
        $this->postJson('/api/v1/platform/login', ['email' => 'admin@ilmora.test', 'password' => 'wrong'])->assertStatus(422);
        $token = $this->postJson('/api/v1/platform/login', ['email' => 'admin@ilmora.test', 'password' => 'password'])->assertOk()['token'];
        $admin = ['Authorization' => "Bearer $token"];
        $overview = $this->getJson('/api/v1/platform/overview', $admin)->assertOk()->json();
        $this->assertCount(2, $overview['schools']);
        $this->assertSame(130, collect($overview['schools'])->firstWhere('slug', 'demo')['students']);

        $this->postJson('/api/v1/platform/schools', ['name' => 'City Model School', 'slug' => 'citymodel', 'owner_name' => 'Zahid Mirza',
            'owner_phone' => '03219998877', 'owner_password' => 'welcome1', 'plan_id' => $overview['plans'][0]['id']], $admin)->assertOk();
        $owner = $this->login('03219998877', 'citymodel', 'welcome1');
        $pulled = $this->pull($owner);
        $this->assertCount(13, $pulled['tables']['classes']);
        $this->assertCount(0, $pulled['tables']['students']);

        // Suspending a school blocks its logins.
        $id = DB::table('schools')->where('slug', 'citymodel')->value('id');
        $this->patchJson("/api/v1/platform/schools/$id", ['status' => 'suspended'], $admin)->assertOk();
        $this->postJson('/api/v1/auth/login', ['login' => '03219998877', 'password' => 'welcome1', 'school' => 'citymodel', 'platform' => 'web'])->assertStatus(422);

        // School users cannot reach the platform console.
        $this->getJson('/api/v1/platform/overview', $this->login('03000000001'))->assertForbidden();
    }
}
