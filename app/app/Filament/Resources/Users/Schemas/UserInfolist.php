<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Schemas;

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
                TextEntry::make('callback')->placeholder('—')->columnSpanFull(),
                TextEntry::make('social_message')->placeholder('—')->columnSpanFull(),
                TextEntry::make('social_montage')
                    ->placeholder('—')
                    ->url(fn ($state): ?string => $state)
                    ->openUrlInNewTab(),
                TextEntry::make('error_count'),
                TextEntry::make('created_at')->dateTime('Y-m-d H:i:s'),
                TextEntry::make('updated_at')->dateTime('Y-m-d H:i:s'),
            ])
            ->columns(3);
    }
}
