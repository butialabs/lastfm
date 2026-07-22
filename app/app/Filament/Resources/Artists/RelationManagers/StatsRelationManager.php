<?php

declare(strict_types=1);

namespace App\Filament\Resources\Artists\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class StatsRelationManager extends RelationManager
{
    protected static string $relationship = 'stats';

    protected static ?string $title = 'Recent appearances';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.username')
                    ->label('User')
                    ->placeholder('—'),
                TextColumn::make('user.lastfm_username')
                    ->label('Last.fm')
                    ->placeholder('—'),
                TextColumn::make('position')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('play_count')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('recorded_at')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->defaultSort('recorded_at', 'desc')
            ->paginated([25, 50]);
    }
}
