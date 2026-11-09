<?php

declare(strict_types=1);

namespace App\Filament\Resources\Artists\RelationManagers;

use App\Filament\Resources\Artists\Actions\ArtistImageActions;
use App\Models\Album;
use App\Services\LastFm\AlbumImageFetcher;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AlbumsRelationManager extends RelationManager
{
    protected static string $relationship = 'albums';

    protected static ?string $title = 'Albums';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('cover')
                    ->label('')
                    ->getStateUsing(fn (Album $record): string => route('admin.albums.image', $record))
                    ->square()
                    ->imageSize(64)
                    ->extraImgAttributes(['loading' => 'lazy']),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('image_hash')
                    ->label('Cover')
                    ->badge()
                    ->state(fn (Album $record): string => match (true) {
                        $record->image_hash === Album::PLACEHOLDER_HASH => 'placeholder',
                        empty($record->image_hash) => 'missing',
                        default => 'ok',
                    })
                    ->colors([
                        'warning' => 'placeholder',
                        'danger' => 'missing',
                        'success' => 'ok',
                    ]),
                TextColumn::make('musicbrainz_id')
                    ->label('MBID')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('regenerate-cover')
                        ->label('Force download')
                        ->icon('heroicon-o-arrow-path')
                        ->requiresConfirmation()
                        ->action(fn (Album $record, AlbumImageFetcher $images) => ArtistImageActions::notify($images->regenerate($record))),
                    Action::make('cover-from-url')
                        ->label('Set image from URL')
                        ->icon('heroicon-o-link')
                        ->schema([
                            TextInput::make('url')
                                ->label('Image URL')
                                ->url()
                                ->required(),
                        ])
                        ->action(fn (Album $record, array $data, AlbumImageFetcher $images) => ArtistImageActions::notify($images->fromUrl($record, (string) $data['url']))),
                ])
                    ->label('Cover')
                    ->icon('heroicon-o-photo')
                    ->color('gray')
                    ->button(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->paginated([10, 25, 50]);
    }
}
