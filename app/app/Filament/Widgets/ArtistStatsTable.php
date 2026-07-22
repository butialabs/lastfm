<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Artist;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Filament\Forms\Components\DatePicker;
use Illuminate\Database\Eloquent\Builder;

class ArtistStatsTable extends TableWidget
{
    protected static ?string $heading = 'Artist appearance statistics';

    // Shown only on the Statistics page (not on the dashboard).
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                fn (): Builder => Artist::query()
                    ->join('artist_stats as s', 'artists.id', '=', 's.artist_id')
                    ->selectRaw('artists.id, artists.name, COUNT(s.id) as appearance_count, AVG(s.position) as average_position, SUM(s.play_count) as total_plays')
                    ->groupBy('artists.id', 'artists.name')
            )
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('appearance_count')
                    ->label('Appearances')
                    ->numeric()
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('appearance_count', $direction)),
                TextColumn::make('average_position')
                    ->label('Avg. position')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('average_position', $direction)),
                TextColumn::make('total_plays')
                    ->label('Total plays')
                    ->numeric()
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('total_plays', $direction)),
            ])
            ->filters([
                Filter::make('period')
                    ->schema([
                        DatePicker::make('from_date')->label('From'),
                        DatePicker::make('to_date')->label('To'),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from_date'] ?? null, fn (Builder $q, $date) => $q->where('s.recorded_at', '>=', $date.' 00:00:00'))
                        ->when($data['to_date'] ?? null, fn (Builder $q, $date) => $q->where('s.recorded_at', '<=', $date.' 23:59:59'))),
            ])
            ->defaultSort('appearance_count', 'desc')
            ->paginated([25, 50, 100]);
    }
}
