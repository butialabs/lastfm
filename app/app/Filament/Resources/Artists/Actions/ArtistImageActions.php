<?php

declare(strict_types=1);

namespace App\Filament\Resources\Artists\Actions;

use App\Models\Artist;
use App\Services\ImageProviderService;
use App\Services\LastFm\ArtistImageFetcher;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;

class ArtistImageActions
{
    public static function group(): ActionGroup
    {
        return ActionGroup::make([
            self::forceDownload(),
            self::fromUrl(),
            self::findImages(),
        ])
            ->label('Image')
            ->icon('heroicon-o-photo')
            ->color('gray')
            ->button();
    }

    public static function forceDownload(): Action
    {
        return Action::make('regenerate-image')
            ->label('Force download')
            ->icon('heroicon-o-arrow-path')
            ->requiresConfirmation()
            ->action(function (Artist $record, ArtistImageFetcher $images): void {
                self::notify($images->regenerate((int) $record->id));
            });
    }

    public static function fromUrl(): Action
    {
        return Action::make('change-image-url')
            ->label('Set image from URL')
            ->icon('heroicon-o-link')
            ->schema([
                TextInput::make('url')
                    ->label('Image URL')
                    ->url()
                    ->required(),
            ])
            ->action(function (Artist $record, array $data, ArtistImageFetcher $images): void {
                self::notify($images->fromUrl((int) $record->id, (string) $data['url']));
            });
    }

    public static function findImages(): Action
    {
        return Action::make('find-sources')
            ->label('Find images')
            ->icon('heroicon-o-magnifying-glass')
            ->schema(function (Artist $record, ImageProviderService $providers): array {
                $sources = $providers->fetchImageSources($record->name, $record->musicbrainz_id);

                if ($sources === []) {
                    return [
                        Placeholder::make('empty')
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
            ->action(function (Artist $record, array $data, ArtistImageFetcher $images): void {
                if (empty($data['url'])) {
                    return;
                }

                self::notify($images->fromUrl((int) $record->id, (string) $data['url']));
            });
    }

    public static function notify(bool $ok): void
    {
        Notification::make()
            ->title($ok ? 'Image updated' : 'Failed to download image')
            ->{$ok ? 'success' : 'danger'}()
            ->send();
    }
}
