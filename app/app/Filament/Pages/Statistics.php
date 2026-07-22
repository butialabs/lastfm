<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Widgets\ArtistStatsTable;
use Filament\Pages\Page;

class Statistics extends Page
{
    protected static \UnitEnum|string|null $navigationGroup = null;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $title = 'Statistics';

    protected string $view = 'filament.pages.statistics';

    protected function getFooterWidgets(): array
    {
        return [
            ArtistStatsTable::class,
        ];
    }
}
