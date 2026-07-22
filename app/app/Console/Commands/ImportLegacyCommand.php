<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Crypto\LegacyCryptoService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ImportLegacyCommand extends Command
{
    protected $signature = 'lastfm:import-legacy
                            {--force : Reimport even if the new database already has data}';

    protected $description = 'Import data from the legacy database (lastfm.sqlite), converting encryption and timestamps';

    private int $credentialsNulled = 0;

    public function handle(LegacyCryptoService $legacyCrypto): int
    {
        $legacyPath = config('database.connections.legacy.database');

        if (! is_file($legacyPath)) {
            $this->info('No legacy database found — nothing to import.');

            return self::SUCCESS;
        }

        if (empty((string) config('lastfm.encryption_key'))) {
            $this->error('ENCRYPTION_KEY is not set. It is required to decrypt credentials from the legacy database.');

            return self::FAILURE;
        }

        // Safe to always run: only pending migrations are applied.
        $this->callSilently('migrate', ['--force' => true]);

        if (! $this->option('force') && DB::table('users')->count() > 0) {
            $this->warn('The new database already contains users. Use --force to reimport (deletes current data).');

            return self::FAILURE;
        }

        $this->info("Importing from: {$legacyPath}");

        if ($this->option('force')) {
            $this->truncateNewDatabase();
        }

        $stats = [
            'users' => $this->importUsers($legacyCrypto),
            'artists' => $this->importSimple('artists', ['created_at', 'updated_at']),
            'artist_stats' => $this->importSimple('artist_stats', ['recorded_at']),
            'config' => $this->importConfig(),
        ];

        foreach ($stats as $table => $count) {
            $this->line("  {$table}: {$count} record(s)");
        }

        if ($this->credentialsNulled > 0) {
            $this->warn("  {$this->credentialsNulled} credential(s) with invalid HMAC were imported as NULL (user will need to log in again).");
        }

        $this->renameLegacyFile($legacyPath);

        Log::channel('app')->info('Legacy import finished', $stats + ['credentials_nulled' => $this->credentialsNulled]);
        $this->info('Import complete. Legacy database renamed for backup.');

        return self::SUCCESS;
    }

    private function truncateNewDatabase(): void
    {
        $this->warn('Deleting current data from the new database (--force)...');

        Schema::disableForeignKeyConstraints();
        DB::table('artist_stats')->delete();
        DB::table('users')->delete();
        DB::table('artists')->delete();
        DB::table('config')->delete();
        Schema::enableForeignKeyConstraints();
    }

    private function importUsers(LegacyCryptoService $legacyCrypto): int
    {
        if (! Schema::connection('legacy')->hasTable('users')) {
            return 0;
        }

        $imported = 0;

        DB::connection('legacy')->table('users')->orderBy('id')->chunk(200, function ($users) use ($legacyCrypto, &$imported) {
            DB::transaction(function () use ($users, $legacyCrypto, &$imported) {
                foreach ($users as $row) {
                    DB::table('users')->insert([
                        'id' => $row->id,
                        'protocol' => $row->protocol,
                        'instance' => $row->instance,
                        'username' => $row->username,
                        'did' => $row->did,
                        'password' => $this->convertCredential($legacyCrypto, $row->password, $row->id),
                        'token' => $this->convertCredential($legacyCrypto, $row->token, $row->id),
                        'lastfm_username' => $row->lastfm_username,
                        'day_of_week' => $row->day_of_week,
                        'time' => $row->time,
                        'timezone' => $row->timezone,
                        'language' => $row->language ?? 'en',
                        'status' => $row->status ?? 'ACTIVE',
                        'callback' => $row->callback,
                        'social_message' => $row->social_message,
                        'social_montage' => $row->social_montage,
                        'error_count' => (int) ($row->error_count ?? 0),
                        'created_at' => $this->convertTimestamp($row->created_at),
                        'updated_at' => $this->convertTimestamp($row->updated_at),
                    ]);
                    $imported++;
                }
            });
        });

        return $imported;
    }

    /** @param  list<string>  $dateColumns */
    private function importSimple(string $table, array $dateColumns): int
    {
        if (! Schema::connection('legacy')->hasTable($table)) {
            return 0;
        }

        $imported = 0;

        DB::connection('legacy')->table($table)->orderBy('id')->chunk(500, function ($rows) use ($table, $dateColumns, &$imported) {
            DB::transaction(function () use ($rows, $table, $dateColumns, &$imported) {
                foreach ($rows as $row) {
                    $data = (array) $row;
                    foreach ($dateColumns as $column) {
                        $data[$column] = $this->convertTimestamp($data[$column] ?? null);
                    }
                    DB::table($table)->insert($data);
                    $imported++;
                }
            });
        });

        return $imported;
    }

    private function importConfig(): int
    {
        if (! Schema::connection('legacy')->hasTable('config')) {
            return 0;
        }

        $imported = 0;

        foreach (DB::connection('legacy')->table('config')->get() as $row) {
            DB::table('config')->updateOrInsert(['key' => $row->key], ['value' => $row->value]);
            $imported++;
        }

        return $imported;
    }

    // Legacy scheme → Laravel Crypt. Rows that fail HMAC are imported as NULL.
    private function convertCredential(LegacyCryptoService $legacyCrypto, ?string $payload, int $userId): ?string
    {
        if ($payload === null || $payload === '') {
            return null;
        }

        try {
            return Crypt::encryptString($legacyCrypto->decrypt($payload));
        } catch (\Throwable $e) {
            $this->credentialsNulled++;
            Log::channel('app')->warning('Legacy import: invalid credential imported as NULL', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function convertTimestamp(mixed $value): string
    {
        try {
            return Carbon::parse((string) $value)->utc()->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return now()->utc()->format('Y-m-d H:i:s');
        }
    }

    private function renameLegacyFile(string $legacyPath): void
    {
        // Close the legacy connection first: Windows cannot rename open files.
        DB::purge('legacy');
        clearstatcache();

        $backup = $legacyPath.'.migrated-'.now()->format('YmdHis');

        if (! @rename($legacyPath, $backup)) {
            $this->warn("Could not rename the legacy database to '{$backup}'. Rename it manually to avoid re-importing.");

            return;
        }

        $this->line("  backup: {$backup}");
    }
}
