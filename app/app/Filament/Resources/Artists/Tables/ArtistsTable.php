<?php

declare(strict_types=1);

namespace App\Filament\Resources\Artists\Tables;

use App\Models\Artist;
use App\Services\ImageProviderService;
use App\Services\LastFmService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class ArtistsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image')
                    ->label('')
                    ->getStateUsing(fn (Artist $record): string => route('admin.artists.image', $record))
                    ->square()
                    ->size(48),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->url(fn (Artist $record): string => $record->lastfm_url, true),
                TextColumn::make('musicbrainz_id')
                    ->label('MBID')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('image_hash')
                    ->label('Image')
                    ->badge()
                    ->formatStateUsing(fn (Artist $record): string => match (true) {
                        $record->image_hash === Artist::PLACEHOLDER_HASH => 'placeholder',
                        empty($record->image_hash) => 'missing',
                        default => 'ok',
                    })
                    ->colors([
                        'warning' => 'placeholder',
                        'danger' => 'missing',
                        'success' => 'ok',
                    ]),
                TextColumn::make('stats_count')
                    ->label('Appearances')
                    ->counts('stats')
                    ->numeric()
                    ->sortable(),
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
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('regenerate-image')
                    ->label('Force download')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->action(function (Artist $record, LastFmService $lastfm): void {
                        $ok = $lastfm->regenerateArtistImage((int) $record->id);

                        Notification::make()
                            ->title($ok ? 'Image updated' : 'Failed to download image')
                            ->{$ok ? 'success' : 'danger'}()
                            ->send();
                    }),
                Action::make('change-image-url')
                    ->label('Set image from URL')
                    ->icon('heroicon-o-link')
                    ->schema([
                        TextInput::make('url')
                            ->label('Image URL')
                            ->url()
                            ->required(),
                    ])
                    ->action(function (Artist $record, array $data, LastFmService $lastfm): void {
                        $ok = $lastfm->downloadArtistImageFromUrl((int) $record->id, (string) $data['url']);

                        Notification::make()
                            ->title($ok ? 'Image updated' : 'Failed to download image')
                            ->{$ok ? 'success' : 'danger'}()
                            ->send();
                    }),
                Action::make('find-sources')
                    ->label('Find images')
                    ->icon('heroicon-o-magnifying-glass')
                    ->schema(function (Artist $record, ImageProviderService $providers): array {
                        $sources = $providers->fetchImageSources($record->name, $record->musicbrainz_id);

                        if ($sources === []) {
                            return [
                                \Filament\Forms\Components\Placeholder::make('empty')
                                    ->label('No sources found (TheAudioDB/Fanart).'),
                            ];
                        }

                        $options = [];
                        foreach ($sources as $source) {
                            $options[$source['url']] = new HtmlString(
                                '<span class="flex items-center gap-3">'
                                .'<img src="'.e($source['url']).'" alt="" class="w-12 h-12 object-cover rounded-md" loading="lazy">'
                                .'<span><strong>'.e($source['source']).'</strong> · '.e($source['type']).'</span>'
                                .'</span>'
                            );
                        }

                        return [
                            Radio::make('url')
                                ->label('Choose an image')
                                ->options($options)
                                ->required(),
                        ];
                    })
                    ->action(function (Artist $record, array $data, LastFmService $lastfm): void {
                        if (empty($data['url'])) {
                            return;
                        }

                        $ok = $lastfm->downloadArtistImageFromUrl((int) $record->id, (string) $data['url']);

                        Notification::make()
                            ->title($ok ? 'Image updated' : 'Failed to download image')
                            ->{$ok ? 'success' : 'danger'}()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkAction::make('regenerate-images')
                    ->label('Force download')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->action(function ($records, LastFmService $lastfm): void {
                        $ok = 0;
                        foreach ($records as $record) {
                            if ($lastfm->regenerateArtistImage((int) $record->id)) {
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
