<?php

namespace App\Filament\Resources\Schools\Schemas;

use App\Filament\Resources\Schools\SchoolResource;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SchoolInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                ImageEntry::make('logo_path')->label('Logo')->disk('public')->placeholder('No logo'),
                TextEntry::make('name'),
                TextEntry::make('slug')->label('School code')->copyable(),
                TextEntry::make('status')->badge()->color(fn (string $state) => SchoolResource::statusColor($state)),
                TextEntry::make('phone')->placeholder('-'),
                TextEntry::make('email')->placeholder('-'),
                TextEntry::make('address')->placeholder('-')->columnSpanFull(),
                TextEntry::make('offline_access_days')->label('Offline access (days)'),
                TextEntry::make('created_at')->dateTime(),
            ]),
        ]);
    }
}
