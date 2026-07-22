<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Config;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;

class ManageConfig extends Page implements HasForms
{
    use InteractsWithForms;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'Config';

    protected static ?string $title = 'Configuration';

    protected string $view = 'filament.pages.manage-config';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'analytics_script' => Config::getValue('analytics_script'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Textarea::make('analytics_script')
                    ->label('Analytics script')
                    ->helperText('HTML/JS injected into the <head> of the public site.')
                    ->rows(10)
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Save')
                ->submit('save'),
        ];
    }

    public function save(): void
    {
        $data = $this->form->getState();

        Config::setValue('analytics_script', $data['analytics_script'] ?? null);

        Notification::make()
            ->title(__('messages.admin.config.saved'))
            ->success()
            ->send();
    }
}
