<?php

namespace App\Filament\Resources\Schools\Pages;

use App\Filament\Resources\Schools\SchoolResource;
use App\Models\School;
use App\Support\Audit;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Only the super admin creates schools (D10). One transaction creates the school,
 * its first campus, the owner login, the subscription and the chart of accounts.
 */
class CreateSchool extends CreateRecord
{
    protected static string $resource = SchoolResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data) {
            $now = now();
            $school = School::create([
                'name' => $data['name'], 'slug' => $data['slug'], 'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null, 'address' => $data['address'] ?? null,
                'logo_path' => $data['logo_path'] ?? null, 'offline_access_days' => $data['offline_access_days'],
                'status' => 'active', 'created_by' => auth()->id(),
            ]);

            $branchId = (string) Str::uuid();
            DB::table('branches')->insert([
                'id' => $branchId, 'school_id' => $school->id, 'name' => $data['branch_name'], 'code' => 'B1',
                'created_at' => $now, 'updated_at' => $now,
            ]);

            $ownerId = (string) Str::uuid();
            DB::table('users')->insert([
                'id' => $ownerId, 'school_id' => $school->id, 'name' => $data['owner_name'], 'phone' => $data['owner_phone'],
                'password' => Hash::make($data['owner_password']), 'status' => 'active',
                'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('user_roles')->insert([
                'id' => (string) Str::uuid(), 'school_id' => $school->id, 'user_id' => $ownerId, 'role' => 'owner',
                'branch_id' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);

            DB::table('subscriptions')->insert([
                'id' => (string) Str::uuid(), 'school_id' => $school->id, 'plan_id' => $data['plan_id'],
                'starts_on' => $now->toDateString(), 'ends_on' => $data['ends_on'], 'status' => 'active',
                'created_at' => $now, 'updated_at' => $now,
            ]);

            foreach (SchoolResource::DEFAULT_ACCOUNTS as [$code, $name, $type]) {
                DB::table('accounts')->insert([
                    'id' => (string) Str::uuid(), 'school_id' => $school->id, 'code' => $code, 'name' => $name,
                    'type' => $type, 'owner_type' => 'school', 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            // Never log the password.
            Audit::log('create_school', 'schools', $school->id, $school->id, null,
                ['name' => $school->name, 'slug' => $school->slug, 'owner_phone' => $data['owner_phone'], 'plan_id' => $data['plan_id']]);

            return $school;
        });
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
