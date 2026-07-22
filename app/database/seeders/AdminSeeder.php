<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AdminSeeder extends Seeder
{
    /**
     * Create the initial admin from ADMIN_USER/ADMIN_PASSWORD env vars.
     * Only runs when the table is empty — never overwrites a password
     * that was changed through the panel.
     */
    public function run(): void
    {
        if (Admin::query()->count() > 0) {
            return;
        }

        $username = (string) config('lastfm.admin.username', '');
        $password = (string) config('lastfm.admin.password', '');

        if ($username === '' || $password === '') {
            Log::channel('app')->warning('AdminSeeder: ADMIN_USER/ADMIN_PASSWORD not set; admin not created.');

            return;
        }

        Admin::create([
            'username' => $username,
            'password' => Hash::make($password),
        ]);

        $this->command?->info("Admin '{$username}' created from environment variables.");
    }
}
