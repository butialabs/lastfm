<?php

declare(strict_types=1);

use App\Filament\Pages\Auth\Login;
use App\Filament\Resources\Artists\Pages\ListArtists;
use App\Filament\Resources\Artists\Pages\ViewArtist;
use App\Filament\Resources\Artists\RelationManagers\AlbumsRelationManager;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Widgets\ArtistStatsTable;
use App\Filament\Widgets\StatsOverview;
use App\Models\Admin;
use App\Models\Album;
use App\Models\Artist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
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
    Livewire::test(Login::class)
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
        ->assertSeeLivewire(StatsOverview::class)
        ->assertDontSeeLivewire(ArtistStatsTable::class);
});

it('lists users with filters and actions', function () {
    User::factory()->count(3)->create();
    User::factory()->mastodon()->create(['status' => User::STATUS_ERROR]);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListUsers::class)
        ->assertOk()
        ->assertCanSeeTableRecords(User::all());
});

it('filters users by schedule day', function () {
    $wednesday = User::factory()->scheduled()->count(2)->create(['day_of_week' => 3]);
    $friday = User::factory()->scheduled()->create(['day_of_week' => 5]);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListUsers::class)
        ->filterTable('day_of_week', 3)
        ->assertCanSeeTableRecords($wednesday)
        ->assertCanNotSeeTableRecords([$friday]);
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
        ->assertSeeLivewire(ArtistStatsTable::class);
});

it('requires an authenticated admin to stream artist images', function () {
    $artist = Artist::factory()->create();

    $this->get(route('admin.artists.image', $artist))->assertRedirect();

    $this->actingAs($this->admin, 'admin')
        ->get(route('admin.artists.image', $artist))
        ->assertNotFound();
});

it('shows and filters users by grid images and size', function () {
    $albums = User::factory()->count(2)->create(['grid_source' => User::GRID_ALBUM, 'grid_size' => 9]);
    $artists = User::factory()->create(['grid_source' => User::GRID_ARTIST, 'grid_size' => 5]);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListUsers::class)
        ->assertTableColumnExists('grid_source')
        ->assertTableColumnExists('grid_size')
        ->assertTableColumnFormattedStateSet('grid_source', 'Album covers', $albums->first())
        ->assertTableColumnFormattedStateSet('grid_size', '9 · 3x3', $albums->first())
        ->filterTable('grid_source', User::GRID_ALBUM)
        ->assertCanSeeTableRecords($albums)
        ->assertCanNotSeeTableRecords([$artists])
        ->resetTableFilters()
        ->filterTable('grid_size', 5)
        ->assertCanSeeTableRecords([$artists])
        ->assertCanNotSeeTableRecords($albums);
});

it('shows the grid preferences and montage on the user page', function () {
    $user = User::factory()->create([
        'grid_source' => User::GRID_ALBUM,
        'grid_size' => 4,
        'social_montage' => '/montage/'.md5('1'),
    ]);

    $this->actingAs($this->admin, 'admin')
        ->get("/admin/users/{$user->id}")
        ->assertOk()
        ->assertSee('Album covers')
        ->assertSee('4 · 2x2')
        ->assertSee(url('/montage/'.md5('1')), false);
});

it('lists artists with their album covers and without the removed columns', function () {
    $artist = Artist::factory()->create(['name' => 'Radiohead']);
    $withCover = Album::factory()->create(['artist_name' => 'Radiohead', 'name' => 'OK Computer']);
    Album::factory()->create(['artist_name' => 'Radiohead', 'name' => 'Pending', 'image_hash' => null]);
    $lonely = Artist::factory()->create();

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListArtists::class)
        ->assertTableColumnExists('album_covers')
        ->assertTableColumnDoesNotExist('image_hash')
        ->assertTableColumnDoesNotExist('stats_count')
        ->assertTableColumnStateSet('album_covers', [route('admin.albums.image', $withCover)], $artist)
        ->filterTable('has_albums', 'with')
        ->assertCanSeeTableRecords([$artist])
        ->assertCanNotSeeTableRecords([$lonely]);
});

it('runs the grouped artist image actions', function () {
    $artist = Artist::factory()->create(['name' => 'Band']);

    Http::fake(['https://img.test/*' => Http::response('nope', 404)]);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListArtists::class)
        ->callTableAction('change-image-url', $artist, ['url' => 'https://img.test/a.jpg'])
        ->assertNotified('Failed to download image');

    Http::assertSent(fn ($r) => $r->url() === 'https://img.test/a.jpg');
});

it('shows the albums tab on the artist page', function () {
    $artist = Artist::factory()->create(['name' => 'Radiohead']);
    $album = Album::factory()->create(['artist_name' => 'Radiohead', 'name' => 'Kid A']);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(AlbumsRelationManager::class, ['ownerRecord' => $artist, 'pageClass' => ViewArtist::class])
        ->assertOk()
        ->assertCanSeeTableRecords([$album]);
});

it('streams cached album covers to admins only', function () {
    Storage::fake('album-cache');
    $album = Album::factory()->create(['artist_name' => 'Radiohead', 'name' => 'Kid A', 'image_hash' => md5('radiohead|kid a')]);
    $webp = (string) ImageManager::gd()->create(10, 10)->fill('#00ff00')->toWebp();
    Storage::disk('album-cache')->put(md5('radiohead|kid a').'.webp', $webp);

    $this->get(route('admin.albums.image', $album))->assertRedirect();

    $this->actingAs($this->admin, 'admin')
        ->get(route('admin.albums.image', $album))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/webp');
});
