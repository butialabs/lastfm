<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Schemas;

use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class UserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('id')->label('#'),
                TextEntry::make('protocol')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'at' ? 'Bluesky' : 'Mastodon'),
                TextEntry::make('instance'),
                TextEntry::make('username'),
                TextEntry::make('did')->placeholder('—'),
                TextEntry::make('lastfm_username')->label('Last.fm')->placeholder('—'),
                TextEntry::make('language')->badge(),
                TextEntry::make('status')->badge(),
                TextEntry::make('day_of_week')->placeholder('—'),
                TextEntry::make('time')->placeholder('—'),
                TextEntry::make('timezone')->placeholder('—'),
                TextEntry::make('grid_source')
                    ->label('Grid images')
                    ->badge()
                    ->state(fn (User $record): string => $record->gridSource())
                    ->formatStateUsing(fn (string $state): string => UsersTable::GRID_SOURCES[$state] ?? $state),
                TextEntry::make('grid_size')
                    ->label('Grid size')
                    ->badge()
                    ->state(fn (User $record): int => $record->gridSize())
                    ->formatStateUsing(fn (int $state): string => UsersTable::gridSizeLabel($state)),
                TextEntry::make('callback')->placeholder('—')->columnSpanFull(),
                TextEntry::make('social_message')->placeholder('—')->columnSpanFull(),
                ImageEntry::make('montage')
                    ->label('Last montage')
                    ->state(fn (User $record): ?string => UsersTable::montageUrl($record))
                    ->imageHeight(200)
                    ->placeholder('—')
                    ->url(fn (User $record): ?string => UsersTable::montageUrl($record), true)
                    ->columnSpanFull(),
                TextEntry::make('error_count'),
                TextEntry::make('send_attempts'),
                TextEntry::make('created_at')->dateTime('Y-m-d H:i:s'),
                TextEntry::make('updated_at')->dateTime('Y-m-d H:i:s'),
            ])
            ->columns(3);
    }
}
