<?php

declare(strict_types=1);

use App\Models\Artist;
use App\Models\ArtistStat;
use App\Models\Config as ConfigModel;
use App\Models\User;
use App\Services\Crypto\LegacyCryptoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['lastfm.encryption_key' => 'fixture-key-32-chars-000000000000']);

    // Temporary legacy database (old app schema: string timestamps).
    $this->legacyPath = sys_get_temp_dir().'/legacy-test-'.uniqid().'.sqlite';
    touch($this->legacyPath);
    config(['database.connections.legacy.database' => $this->legacyPath]);
    DB::purge('legacy');

    $schema = Schema::connection('legacy');

    $schema->create('users', function ($table) {
        $table->increments('id');
        $table->string('protocol', 20);
        $table->string('instance');
        $table->string('username');
        $table->string('did')->nullable();
        $table->text('password')->nullable();
        $table->text('token')->nullable();
        $table->string('lastfm_username')->nullable();
        $table->integer('day_of_week')->nullable();
        $table->string('time', 20)->nullable();
        $table->string('timezone', 100)->nullable();
        $table->string('language', 10)->default('en');
        $table->string('status', 20)->default('ACTIVE');
        $table->text('callback')->nullable();
        $table->text('social_message')->nullable();
        $table->string('social_montage')->nullable();
        $table->integer('error_count')->default(0);
        $table->string('created_at', 40);
        $table->string('updated_at', 40);
    });

    $schema->create('artists', function ($table) {
        $table->increments('id');
        $table->string('name');
        $table->string('lastfm_url');
        $table->string('musicbrainz_id', 36)->nullable();
        $table->string('image_hash', 32)->nullable();
        $table->string('created_at', 40);
        $table->string('updated_at', 40);
    });

    $schema->create('artist_stats', function ($table) {
        $table->increments('id');
        $table->integer('artist_id');
        $table->integer('user_id');
        $table->integer('position');
        $table->integer('play_count');
        $table->string('recorded_at', 40);
    });

    $schema->create('config', function ($table) {
        $table->string('key', 100)->primary();
        $table->text('value')->nullable();
    });
});

afterEach(function () {
    // Clean up the temporary legacy file and any .migrated-* renames
    foreach (glob($this->legacyPath.'*') ?: [] as $file) {
        @unlink($file);
    }
});

function seedLegacyDatabase(LegacyCryptoService $crypto): void
{
    DB::connection('legacy')->table('users')->insert([
        [
            'id' => 7,
            'protocol' => 'at',
            'instance' => 'https://bsky.social',
            'username' => 'alice.bsky.social',
            'did' => 'did:plc:alice',
            'password' => $crypto->encrypt('alice-app-password'),
            'token' => null,
            'lastfm_username' => 'alice',
            'day_of_week' => 5,
            'time' => '12:00:00',
            'timezone' => 'America/Sao_Paulo',
            'language' => 'pt-BR',
            'status' => 'SCHEDULE',
            'callback' => 'Queued successfully',
            'social_message' => 'old post',
            'social_montage' => '/montage/'.md5('7'),
            'error_count' => 0,
            'created_at' => '2025-12-27T19:00:00+00:00',
            'updated_at' => '2026-04-13T04:53:30+00:00',
        ],
        [
            'id' => 9,
            'protocol' => 'mastodon',
            'instance' => 'https://mastodon.social',
            'username' => 'bob',
            'did' => null,
            'password' => null,
            'token' => $crypto->encrypt('bob-mastodon-token'),
            'lastfm_username' => 'bob',
            'day_of_week' => 1,
            'time' => '09:00:00',
            'timezone' => 'UTC',
            'language' => 'en',
            'status' => 'QUEUED',
            'callback' => null,
            'social_message' => null,
            'social_montage' => null,
            'error_count' => 2,
            'created_at' => '2026-01-10T10:00:00+00:00',
            'updated_at' => '2026-01-10T10:00:00+00:00',
        ],
    ]);

    DB::connection('legacy')->table('artists')->insert([
        'id' => 3,
        'name' => 'Legado Band',
        'lastfm_url' => 'https://www.last.fm/music/Legado+Band',
        'musicbrainz_id' => 'mbid-123',
        'image_hash' => md5('legado band'),
        'created_at' => '2026-04-13 05:00:00',
        'updated_at' => '2026-04-13 05:00:00',
    ]);

    DB::connection('legacy')->table('artist_stats')->insert([
        'id' => 11,
        'artist_id' => 3,
        'user_id' => 7,
        'position' => 1,
        'play_count' => 42,
        'recorded_at' => '2026-04-13 05:00:00',
    ]);

    DB::connection('legacy')->table('config')->insert([
        'key' => 'analytics_script',
        'value' => '<script>analytics</script>',
    ]);
}

it('imports the legacy database preserving ids and converting crypto and timestamps', function () {
    $crypto = new LegacyCryptoService;
    seedLegacyDatabase($crypto);

    $this->artisan('lastfm:import-legacy')->assertSuccessful();

    // IDs preserved 1:1 (md5(user_id) montages stay valid)
    $alice = User::find(7);
    expect($alice)->not->toBeNull()
        ->and($alice->protocol)->toBe('at')
        ->and($alice->status)->toBe('SCHEDULE')
        ->and($alice->language)->toBe('pt-BR')
        ->and($alice->social_montage)->toBe('/montage/'.md5('7'));

    // Credentials re-encrypted with APP_KEY (Crypt) — decrypt to the original value
    expect(Crypt::decryptString($alice->password))->toBe('alice-app-password');
    expect(Crypt::decryptString(User::find(9)->token))->toBe('bob-mastodon-token');

    // Timestamps string/DATE_ATOM convertidos para datetime
    expect($alice->created_at->format('Y-m-d H:i:s'))->toBe('2025-12-27 19:00:00')
        ->and($alice->updated_at->format('Y-m-d H:i:s'))->toBe('2026-04-13 04:53:30');

    $artist = Artist::find(3);
    expect($artist)->not->toBeNull()
        ->and($artist->created_at->format('Y-m-d H:i:s'))->toBe('2026-04-13 05:00:00');

    $stat = ArtistStat::find(11);
    expect($stat)->not->toBeNull()
        ->and($stat->user_id)->toBe(7)
        ->and($stat->recorded_at->format('Y-m-d H:i:s'))->toBe('2026-04-13 05:00:00');

    expect(ConfigModel::getValue('analytics_script'))->toBe('<script>analytics</script>');

    // Legacy database renamed (backup) → command becomes a no-op
    expect(file_exists($this->legacyPath))->toBeFalse()
        ->and(glob($this->legacyPath.'.migrated-*'))->not->toBeEmpty();

    $this->artisan('lastfm:import-legacy')->assertSuccessful();
    expect(User::count())->toBe(2);
});

it('nulls credentials with invalid HMAC and keeps importing', function () {
    DB::connection('legacy')->table('users')->insert([
        'id' => 1,
        'protocol' => 'at',
        'instance' => 'https://bsky.social',
        'username' => 'eve',
        'did' => 'did:plc:eve',
        'password' => base64_encode(str_repeat('x', 64)), // HMAC inválido
        'token' => null,
        'lastfm_username' => 'eve',
        'day_of_week' => null,
        'time' => null,
        'timezone' => null,
        'language' => 'en',
        'status' => 'ACTIVE',
        'callback' => null,
        'social_message' => null,
        'social_montage' => null,
        'error_count' => 0,
        'created_at' => '2026-01-01T00:00:00+00:00',
        'updated_at' => '2026-01-01T00:00:00+00:00',
    ]);

    $this->artisan('lastfm:import-legacy')->assertSuccessful();

    $eve = User::find(1);
    expect($eve)->not->toBeNull()
        ->and($eve->password)->toBeNull();
});

it('is a no-op when there is no legacy database', function () {
    config(['database.connections.legacy.database' => sys_get_temp_dir().'/nao-existe-'.uniqid().'.sqlite']);
    DB::purge('legacy');

    $this->artisan('lastfm:import-legacy')->assertSuccessful();
    expect(User::count())->toBe(0);
});

it('refuses to import over a populated database without --force', function () {
    User::create([
        'protocol' => 'at',
        'instance' => 'https://bsky.social',
        'username' => 'existing',
        'status' => 'ACTIVE',
    ]);

    $crypto = new LegacyCryptoService;
    seedLegacyDatabase($crypto);

    $this->artisan('lastfm:import-legacy')->assertFailed();
    expect(User::count())->toBe(1);

    $this->artisan('lastfm:import-legacy', ['--force' => true])->assertSuccessful();
    expect(User::count())->toBe(2)
        ->and(User::find(7))->not->toBeNull();
});

it('fails clearly when ENCRYPTION_KEY is missing', function () {
    seedLegacyDatabase(new LegacyCryptoService);
    config(['lastfm.encryption_key' => '']);

    $this->artisan('lastfm:import-legacy')->assertFailed();
    expect(User::count())->toBe(0);
});
