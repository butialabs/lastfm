<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reset-errors')
                ->label('Reset error users')
                ->icon('heroicon-o-arrow-path')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('All users in ERROR will be moved back to SCHEDULE with errors reset.')
                ->action(function (): void {
                    $affected = User::query()
                        ->where('status', User::STATUS_ERROR)
                        ->update(['status' => User::STATUS_SCHEDULE, 'error_count' => 0]);

                    Notification::make()
                        ->title("{$affected} user(s) restored")
                        ->success()
                        ->send();
                }),
        ];
    }
}
