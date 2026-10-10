<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression tests for tenant isolation and sync-upload hardening.
 */
class SecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ilmora.powersync.secret' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=')]);
        $this->seed(DatabaseSeeder::class);
    }

    private function login(string $phone, string $school = 'demo'): array
    {
        $r = $this->postJson('/api/v1/auth/login', [
            'login' => $phone, 'password' => 'password', 'school' => $school, 'platform' => 'web',
        ])->assertOk();
        return ['Authorization' => 'Bearer '.$r['token'], 'X-Device-Id' => $r['device']['id']];
    }

    private function upload(array $headers, array $op): string
    {
        $op += ['id' => (string) Str::uuid(), 'op' => 'PUT'];
        return $this->postJson('/api/v1/sync/upload', ['ops' => [$op]], $headers)->assertOk()['results'][0]['result'];
    }

    private function otherSchoolFamily(): string
    {
        $school = (string) Str::uuid();
        $branch = (string) Str::uuid();
        $family = (string) Str::uuid();
        DB::table('schools')->insert(['id' => $school, 'name' => 'Other', 'slug' => 'other', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('branches')->insert(['id' => $branch, 'school_id' => $school, 'name' => 'X', 'code' => 'B1', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('families')->insert(['id' => $family, 'school_id' => $school, 'branch_id' => $branch, 'code' => 'F1',
            'primary_guardian_name' => 'X', 'primary_phone' => '1', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        return $family;
    }

    public function test_super_admin_cannot_log_in_through_app_api_with_unknown_school(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'login' => 'admin@ilmora.test', 'password' => 'password', 'school' => 'no-such-school', 'platform' => 'web',
        ])->assertStatus(422);
    }

    public function test_suspended_school_cannot_log_in(): void
    {
        DB::table('schools')->where('slug', 'demo')->update(['status' => 'suspended']);
        $this->postJson('/api/v1/auth/login', [
            'login' => '03000000001', 'password' => 'password', 'school' => 'demo', 'platform' => 'web',
        ])->assertStatus(422);
    }

    public function test_token_cannot_be_used_with_another_device_id(): void
    {
        $a = $this->login('03000000001');
        $b = $this->login('03000000001');
        $this->getJson('/api/v1/auth/me', ['Authorization' => $a['Authorization'], 'X-Device-Id' => $b['X-Device-Id']])
            ->assertStatus(401);
        $this->getJson('/api/v1/auth/me', $a)->assertOk();
    }

    public function test_sync_rejects_reference_to_another_schools_row(): void
    {
        $h = $this->login('03000000001');
        $branch = DB::table('branches')->where('code', 'B1')->whereIn('school_id', DB::table('schools')->where('slug', 'demo')->select('id'))->value('id');
        $result = $this->upload($h, ['table' => 'guardians', 'data' => [
            'branch_id' => $branch, 'family_id' => $this->otherSchoolFamily(), 'name' => 'Spy', 'relation' => 'other',
        ]]);
        $this->assertSame('rejected', $result);
        $this->assertSame(0, DB::table('guardians')->count());
    }

    public function test_sync_rejects_unknown_and_locked_columns(): void
    {
        $h = $this->login('03000000001');
        $this->assertSame('rejected', $this->upload($h, ['table' => 'families', 'data' => ['school_id_x' => 1]]));
        $this->assertSame('rejected', $this->upload($h, ['table' => 'staff_leaves', 'data' => ['status' => 'approved']]));
    }

    public function test_sync_rejects_branch_outside_school(): void
    {
        $h = $this->login('03000000001');
        $otherBranch = DB::table('families')->where('id', $this->otherSchoolFamily())->value('branch_id');
        $this->assertSame('rejected', $this->upload($h, ['table' => 'families', 'data' => [
            'branch_id' => $otherBranch, 'code' => 'F9', 'primary_guardian_name' => 'X', 'primary_phone' => '1',
        ]]));
    }

    public function test_sync_accepts_valid_family(): void
    {
        $h = $this->login('03000000001');
        $branch = DB::table('branches')->where('name', 'Main Campus')->value('id');
        $this->assertSame('applied', $this->upload($h, ['table' => 'families', 'data' => [
            'branch_id' => $branch, 'code' => 'F1', 'primary_guardian_name' => 'Ali', 'primary_phone' => '0300',
        ]]));
    }

    public function test_teacher_cannot_write_families(): void
    {
        $h = $this->login('03000000002');
        $branch = DB::table('branches')->where('name', 'Main Campus')->value('id');
        $this->assertSame('rejected', $this->upload($h, ['table' => 'families', 'data' => [
            'branch_id' => $branch, 'code' => 'F2', 'primary_guardian_name' => 'X', 'primary_phone' => '1',
        ]]));
    }
}
