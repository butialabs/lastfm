<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Artist;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('Total users', User::count())
                ->icon('heroicon-o-users'),
            Stat::make('Active users', User::countActive())
                ->icon('heroicon-o-check-circle')
                ->color('success'),
            Stat::make('Bluesky', User::where('protocol', User::PROTOCOL_AT)->count())
                ->icon('heroicon-o-cloud')
                ->color('info'),
            Stat::make('Mastodon', User::where('protocol', User::PROTOCOL_MASTODON)->count())
                ->icon('heroicon-o-megaphone')
                ->color('purple'),
            Stat::make('Artists', Artist::count())
                ->icon('heroicon-o-photo'),
        ];
    }
}
