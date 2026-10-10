<?php

namespace App\Filament\Resources\Schools\Tables;

use App\Filament\Resources\Schools\SchoolResource;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SchoolsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('logo_path')->label('')->disk('public')->circular()->size(32),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('slug')->label('Code')->searchable()->copyable(),
                TextColumn::make('status')->badge()->color(fn (string $state) => SchoolResource::statusColor($state)),
                TextColumn::make('students_count')->label('Students')
                    ->state(fn ($record) => \DB::table('students')->where('school_id', $record->id)->where('status', 'active')->count()),
                TextColumn::make('subscription_ends')->label('Subscription ends')->date()
                    ->state(fn ($record) => \DB::table('subscriptions')->where('school_id', $record->id)->max('ends_on')),
                TextColumn::make('created_at')->date()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(array_combine(SchoolResource::STATUSES, array_map('ucfirst', SchoolResource::STATUSES))),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                ...SchoolResource::statusActions(),
            ]);
    }
}
