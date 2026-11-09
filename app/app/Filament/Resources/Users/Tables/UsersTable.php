<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use App\Services\MontageService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UsersTable
{
    public const GRID_SOURCES = [
        User::GRID_ARTIST => 'Artist photos',
        User::GRID_ALBUM => 'Album covers',
    ];

    public static function gridSizeLabel(int $size): string
    {
        return match ($size) {
            3 => '3 · 1+2',
            4 => '4 · 2x2',
            5 => '5 · 1+4',
            6 => '6 · 3x2',
            9 => '9 · 3x3',
            default => (string) $size,
        };
    }

    public static function montageUrl(User $record): ?string
    {
        return $record->social_montage ? url($record->social_montage) : null;
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('protocol')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === User::PROTOCOL_AT ? 'Bluesky' : 'Mastodon')
                    ->color(fn (string $state): string => $state === User::PROTOCOL_AT ? 'info' : 'purple')
                    ->sortable(),
                TextColumn::make('instance')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('username')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('lastfm_username')
                    ->label('Last.fm')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('language')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'gray' => User::STATUS_ACTIVE,
                        'info' => User::STATUS_SCHEDULE,
                        'warning' => [User::STATUS_QUEUED, User::STATUS_SENDING],
                        'danger' => User::STATUS_ERROR,
                    ])
                    ->sortable(),
                TextColumn::make('grid_source')
                    ->label('Images')
                    ->badge()
                    ->state(fn (User $record): string => $record->gridSource())
                    ->formatStateUsing(fn (string $state): string => self::GRID_SOURCES[$state] ?? $state)
                    ->color(fn (string $state): string => $state === User::GRID_ALBUM ? 'success' : 'info')
                    ->sortable(),
                TextColumn::make('grid_size')
                    ->label('Grid')
                    ->badge()
                    ->state(fn (User $record): int => $record->gridSize())
                    ->formatStateUsing(fn (int $state): string => self::gridSizeLabel($state))
                    ->color('gray')
                    ->sortable(),
                ImageColumn::make('montage')
                    ->label('Montage')
                    ->state(fn (User $record): ?string => self::montageUrl($record))
                    ->imageHeight(40)
                    ->extraImgAttributes(['loading' => 'lazy'])
                    ->url(fn (User $record): ?string => self::montageUrl($record), true)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('error_count')
                    ->label('Errors')
                    ->numeric()
                    ->sortable()
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('schedule')
                    ->label('Schedule')
                    ->state(fn (User $record): string => $record->day_of_week && $record->time
                        ? __('messages.day.'.(User::DAYS[$record->day_of_week] ?? 'sunday')).' '.substr((string) $record->time, 0, 5).' UTC'
                        : '—')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderBy('day_of_week', $direction)
                        ->orderBy('time', $direction)),
                TextColumn::make('updated_at')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('protocol')
                    ->options([
                        User::PROTOCOL_AT => 'Bluesky',
                        User::PROTOCOL_MASTODON => 'Mastodon',
                    ]),
                SelectFilter::make('status')
                    ->options([
                        User::STATUS_ACTIVE => 'Active',
                        User::STATUS_SCHEDULE => 'Schedule',
                        User::STATUS_QUEUED => 'Queued',
                        User::STATUS_SENDING => 'Sending',
                        User::STATUS_ERROR => 'Error',
                    ]),
                SelectFilter::make('grid_source')
                    ->label('Grid images')
                    ->options(self::GRID_SOURCES),
                SelectFilter::make('grid_size')
                    ->label('Grid size')
                    ->options(collect(MontageService::SIZES)
                        ->mapWithKeys(fn (int $size): array => [$size => self::gridSizeLabel($size)])
                        ->all()),
                SelectFilter::make('day_of_week')
                    ->label('Schedule day (UTC)')
                    ->options(collect(User::DAYS)
                        ->map(fn (string $day): string => __('messages.day.'.$day))
                        ->all()),
                SelectFilter::make('language')
                    ->options([
                        'en' => 'English',
                        'pt-BR' => 'Português',
                        'fr-FR' => 'Français',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('force-send')
                    ->label('Force send')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('Marks the user as QUEUED (errors reset). The post is sent on the next scheduler cycle.')
                    ->action(function (User $record): void {
                        $record->forceFill([
                            'status' => User::STATUS_QUEUED,
                            'error_count' => 0,
                            'send_attempts' => 0,
                        ])->save();

                        Notification::make()
                            ->title("User #{$record->id} queued for sending")
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100]);
    }
}
