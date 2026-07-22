<?php

declare(strict_types=1);

namespace App\Filament\Resources\Artists\Schemas;

use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ArtistInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                ImageEntry::make('image')
                    ->label('')
                    ->getStateUsing(fn ($record): string => route('admin.artists.image', $record))
                    ->square()
                    ->imageSize(160),
                TextEntry::make('name'),
                TextEntry::make('lastfm_url')
                    ->label('Last.fm')
                    ->url(fn ($state): ?string => $state)
                    ->openUrlInNewTab(),
                TextEntry::make('musicbrainz_id')->label('MBID')->placeholder('—'),
                TextEntry::make('image_hash')
                    ->badge()
                    ->formatStateUsing(fn ($record): string => match (true) {
                        $record->image_hash === \App\Models\Artist::PLACEHOLDER_HASH => 'placeholder',
                        empty($record->image_hash) => 'missing',
                        default => (string) $record->image_hash,
                    }),
                TextEntry::make('created_at')->dateTime('Y-m-d H:i:s'),
                TextEntry::make('updated_at')->dateTime('Y-m-d H:i:s'),
            ])
            ->columns(3);
    }
}
