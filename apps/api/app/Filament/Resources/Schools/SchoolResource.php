<?php

namespace App\Filament\Resources\Schools;

use App\Filament\Resources\Schools\Pages\CreateSchool;
use App\Filament\Resources\Schools\Pages\EditSchool;
use App\Filament\Resources\Schools\Pages\ListSchools;
use App\Filament\Resources\Schools\Pages\ViewSchool;
use App\Filament\Resources\Schools\Schemas\SchoolForm;
use App\Filament\Resources\Schools\Schemas\SchoolInfolist;
use App\Filament\Resources\Schools\Tables\SchoolsTable;
use App\Models\School;
use App\Support\Audit;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class SchoolResource extends Resource
{
    protected static ?string $model = School::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?string $recordTitleAttribute = 'name';

    public const STATUSES = ['active', 'read_only', 'suspended', 'closed'];

    public const DEFAULT_ACCOUNTS = [
        ['1100', 'Cash in hand', 'asset'], ['1200', 'Bank', 'asset'], ['2100', 'Advance fee / family credit', 'liability'],
        ['4100', 'Tuition fee income', 'income'], ['4200', 'Fine income', 'income'], ['5100', 'Discounts given', 'expense'],
        ['5200', 'Salaries', 'expense'],
    ];

    public static function statusColor(string $status): string
    {
        return match ($status) { 'active' => 'success', 'read_only' => 'warning', default => 'danger' };
    }

    /** Suspend / reactivate / read-only. Data is always kept; only access changes (D10). */
    public static function statusActions(): array
    {
        $make = fn (string $name, string $label, string $to, string $color, string $warning) => Action::make($name)
            ->label($label)->color($color)->requiresConfirmation()->modalDescription($warning)
            ->visible(fn (School $record) => $record->status !== $to)
            ->action(function (School $record) use ($to, $label) {
                $from = $record->status;
                $record->update(['status' => $to]);
                Audit::log('school_status', 'schools', $record->id, $record->id, ['status' => $from], ['status' => $to]);
                Notification::make()->title("{$record->name}: {$label} done")->success()->send();
            });

        return [
            $make('suspend', 'Suspend', 'suspended', 'danger', 'All logins for this school are blocked. Data is kept.'),
            $make('read_only', 'Read-only', 'read_only', 'warning', 'Users can view but not change anything (e.g. unpaid renewal).'),
            $make('activate', 'Activate', 'active', 'success', 'Restore full access for this school.'),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return SchoolForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SchoolInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SchoolsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSchools::route('/'),
            'create' => CreateSchool::route('/create'),
            'view' => ViewSchool::route('/{record}'),
            'edit' => EditSchool::route('/{record}/edit'),
        ];
    }
}
