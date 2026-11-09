<?php

declare(strict_types=1);

namespace App\Filament\Resources\Artists\Tables;

use App\Filament\Resources\Artists\Actions\ArtistImageActions;
use App\Models\Album;
use App\Models\Artist;
use App\Services\LastFm\ArtistImageFetcher;
use Filament\Actions\BulkAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ArtistsTable
{
    private const ALBUM_PREVIEW = 4;

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['albums' => fn ($q) => $q->withImage()->latest('updated_at')])
                ->withCount('albums'))
            ->columns([
                ImageColumn::make('image')
                    ->label('')
                    ->getStateUsing(fn (Artist $record): string => route('admin.artists.image', $record))
                    ->square()
                    ->imageSize(48)
                    ->extraImgAttributes(['loading' => 'lazy']),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->url(fn (Artist $record): string => $record->lastfm_url, true),
                ImageColumn::make('album_covers')
                    ->label('Albums')
                    ->getStateUsing(fn (Artist $record): array => $record->albums
                        ->map(fn (Album $album): string => route('admin.albums.image', $album))
                        ->all())
                    ->square()
                    ->imageSize(36)
                    ->stacked()
                    ->overlap(2)
                    ->limit(self::ALBUM_PREVIEW)
                    ->limitedRemainingText()
                    ->extraImgAttributes(['loading' => 'lazy'])
                    ->placeholder('—'),
                TextColumn::make('albums_count')
                    ->label('# Albums')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('musicbrainz_id')
                    ->label('MBID')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('no_image')
                    ->label('Image status')
                    ->options([
                        '1' => 'Missing',
                        'placeholder' => 'Placeholder',
                    ])
                    ->query(fn ($query, array $data) => $query->noImage($data['value'] ?? null)),
                SelectFilter::make('has_albums')
                    ->label('Albums')
                    ->options([
                        'with' => 'With albums',
                        'without' => 'Without albums',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'with' => $query->has('albums'),
                        'without' => $query->doesntHave('albums'),
                        default => $query,
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                ArtistImageActions::group(),
            ])
            ->toolbarActions([
                BulkAction::make('regenerate-images')
                    ->label('Force download')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->action(function ($records, ArtistImageFetcher $images): void {
                        $ok = 0;
                        foreach ($records as $record) {
                            if ($images->regenerate((int) $record->id)) {
                                $ok++;
                            }
                        }

                        Notification::make()
                            ->title("{$ok} of ".$records->count().' image(s) updated')
                            ->success()
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ])
            ->defaultSort('name')
            ->paginated([25, 50, 100]);
    }
}
