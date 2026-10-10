<?php

namespace App\Filament\Resources\Schools\Pages;

use App\Filament\Resources\Schools\SchoolResource;
use App\Support\Audit;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditSchool extends EditRecord
{
    protected static string $resource = SchoolResource::class;

    protected function getHeaderActions(): array
    {
        // No delete: a school's records are kept; suspend it instead.
        return [ViewAction::make()];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $allowed = array_intersect_key($data, array_flip(['name', 'phone', 'email', 'address', 'logo_path', 'offline_access_days']));
        $before = $record->only(array_keys($allowed));
        $record->update($allowed);
        Audit::log('update_school', 'schools', $record->id, $record->id, $before, $allowed);
        return $record;
    }
}
