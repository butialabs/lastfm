<?php

declare(strict_types=1);

namespace App\Filament\Resources\Artists;

use App\Filament\Resources\Artists\Pages\ListArtists;
use App\Filament\Resources\Artists\Pages\ViewArtist;
use App\Filament\Resources\Artists\RelationManagers\StatsRelationManager;
use App\Filament\Resources\Artists\Schemas\ArtistInfolist;
use App\Filament\Resources\Artists\Tables\ArtistsTable;
use App\Models\Artist;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ArtistResource extends Resource
{
    protected static ?string $model = Artist::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static ?string $recordTitleAttribute = 'name';

    public static function infolist(Schema $schema): Schema
    {
        return ArtistInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ArtistsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            StatsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListArtists::route('/'),
            'view' => ViewArtist::route('/{record}'),
        ];
    }
}
