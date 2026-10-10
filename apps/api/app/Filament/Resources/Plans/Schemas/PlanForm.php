<?php

namespace App\Filament\Resources\Plans\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class PlanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()->maxLength(60),
                TextInput::make('max_students')
                    ->numeric(),
                TextInput::make('max_staff')
                    ->numeric(),
                TextInput::make('max_branches')
                    ->required()
                    ->numeric()
                    ->default(1),
                TextInput::make('monthly_message_credits')
                    ->required()
                    ->numeric()
                    ->default(0),
                CheckboxList::make('modules')
                    ->options(fn () => array_combine(config('ilmora.modules'), array_map(
                        fn ($m) => ucwords(str_replace('_', ' ', $m)), config('ilmora.modules'))))
                    ->columns(3)->required()->columnSpanFull(),
                TextInput::make('price_monthly')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('price_yearly')
                    ->required()
                    ->numeric()
                    ->default(0),
                Toggle::make('active')
                    ->default(true),
            ]);
    }
}
