<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Support\Colors\Color;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class UsersTable
{
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
                TextColumn::make('error_count')
                    ->label('Errors')
                    ->numeric()
                    ->sortable()
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('schedule')
                    ->label('Schedule')
                    ->state(fn (User $record): string => $record->day_of_week && $record->time
                        ? __('messages.day.'.['', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'][$record->day_of_week] ?? 'sunday').' '.substr((string) $record->time, 0, 5).' UTC'
                        : '—'),
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
