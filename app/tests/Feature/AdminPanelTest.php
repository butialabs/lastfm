<?php

declare(strict_types=1);

use App\Filament\Resources\Artists\Pages\ListArtists;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Admin;
use App\Models\Artist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = Admin::factory()->create();
});

it('redirects guests from the panel to the login page', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('shows the login page', function () {
    $this->get('/admin/login')->assertOk();
});

it('logs in with the seeded admin credentials', function () {
    Livewire::test(\App\Filament\Pages\Auth\Login::class)
        ->set('data.username', $this->admin->username)
        ->set('data.password', 'password')
        ->call('authenticate')
        ->assertRedirect('/admin');

    $this->assertAuthenticated('admin');
});

it('loads the dashboard with the stats widget', function () {
    User::factory()->count(3)->create();
    Artist::factory()->count(2)->create();

    $this->actingAs($this->admin, 'admin')
        ->get('/admin')
        ->assertOk()
        ->assertSeeLivewire(\App\Filament\Widgets\StatsOverview::class)
        ->assertDontSeeLivewire(\App\Filament\Widgets\ArtistStatsTable::class);
});

it('lists users with filters and actions', function () {
    User::factory()->count(3)->create();
    User::factory()->mastodon()->create(['status' => User::STATUS_ERROR]);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListUsers::class)
        ->assertOk()
        ->assertCanSeeTableRecords(User::all());
});

it('force-sends a user (requeue)', function () {
    $user = User::factory()->create(['status' => User::STATUS_ERROR, 'error_count' => 3]);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListUsers::class)
        ->callTableAction('force-send', $user);

    $user->refresh();
    expect($user->status)->toBe(User::STATUS_QUEUED)
        ->and($user->error_count)->toBe(0);
});

it('resets error users', function () {
    User::factory()->count(2)->create(['status' => User::STATUS_ERROR, 'error_count' => 2]);
    User::factory()->create(['status' => User::STATUS_SCHEDULE]);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListUsers::class)
        ->callAction('reset-errors');

    expect(User::where('status', User::STATUS_ERROR)->count())->toBe(0)
        ->and(User::where('status', User::STATUS_SCHEDULE)->count())->toBe(3);
});

it('lists artists with image-status filter', function () {
    Artist::factory()->create(['image_hash' => null]);
    Artist::factory()->create(['image_hash' => Artist::PLACEHOLDER_HASH]);
    Artist::factory()->create();

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListArtists::class)
        ->assertOk()
        ->assertCanSeeTableRecords(Artist::all());
});

it('loads the statistics and config pages', function () {
    $this->actingAs($this->admin, 'admin')
        ->get('/admin/statistics')
        ->assertOk()
        ->assertSeeLivewire(\App\Filament\Widgets\ArtistStatsTable::class);

    $this->actingAs($this->admin, 'admin')
        ->get('/admin/manage-config')
        ->assertOk();
});

it('saves the analytics script config', function () {
    $this->actingAs($this->admin, 'admin');

    Livewire::test(\App\Filament\Pages\ManageConfig::class)
        ->set('data.analytics_script', '<script>x</script>')
        ->call('save');

    expect(\App\Models\Config::getValue('analytics_script'))->toBe('<script>x</script>');
});
