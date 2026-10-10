<?php

namespace App\Filament\Resources\Schools\Schemas;

use App\Models\Plan;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Password;

class SchoolForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('School')->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(150),
                TextInput::make('slug')->label('School code (used to log in)')
                    ->required()->regex('/^[a-z0-9-]{3,40}$/')->unique(ignoreRecord: true)
                    ->helperText('Lowercase letters, numbers and dashes, e.g. city-grammar')
                    ->disabledOn('edit'),
                TextInput::make('phone')->tel()->maxLength(20),
                TextInput::make('email')->email()->maxLength(150),
                Textarea::make('address')->maxLength(300)->columnSpanFull(),
                // Raster images only: SVG can carry scripts.
                FileUpload::make('logo_path')->label('Logo')->image()->disk('public')->directory('logos')
                    ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])->maxSize(1024),
                TextInput::make('offline_access_days')->label('Offline access (days)')
                    ->numeric()->minValue(1)->maxValue(30)->default(7)->required(),
            ]),

            Section::make('First campus, owner and plan')->columns(2)->visibleOn('create')->schema([
                TextInput::make('branch_name')->label('Campus name')->default('Main Campus')->required()->maxLength(80),
                TextInput::make('owner_name')->label('Owner / principal name')->required()->maxLength(100),
                TextInput::make('owner_phone')->label('Owner mobile (login)')->tel()->required()
                    ->regex('/^03\d{9}$/')->helperText('11 digits, e.g. 03001234567'),
                TextInput::make('owner_password')->label('Temporary password')->password()->revealable()->required()
                    ->rule(Password::min(10)->letters()->numbers()),
                Select::make('plan_id')->label('Plan')->required()
                    ->options(fn () => Plan::where('active', true)->pluck('name', 'id')),
                DatePicker::make('ends_on')->label('Subscription ends')->required()->default(now()->addYear())->after('today'),
            ]),
        ]);
    }
}
