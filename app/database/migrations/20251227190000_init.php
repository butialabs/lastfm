<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class Init extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('users');

        $table
            ->addColumn('protocol', 'string', ['limit' => 20])
            ->addColumn('instance', 'string', ['limit' => 255])
            ->addColumn('username', 'string', ['limit' => 255])
            ->addColumn('did', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('password', 'text', ['null' => true])
            ->addColumn('token', 'text', ['null' => true])
            ->addColumn('lastfm_username', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('day_of_week', 'integer', ['null' => true])
            ->addColumn('time', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('timezone', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('language', 'string', ['limit' => 10, 'default' => 'en'])
            ->addColumn('status', 'string', ['limit' => 20, 'default' => 'ACTIVE'])
            ->addColumn('callback', 'text', ['null' => true])
            ->addColumn('social_message', 'text', ['null' => true])
            ->addColumn('social_montage', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('error_count', 'integer', ['default' => 0])
            ->addColumn('created_at', 'string', ['limit' => 40])
            ->addColumn('updated_at', 'string', ['limit' => 40])
            ->addIndex(['protocol', 'instance', 'username'], ['unique' => true, 'name' => 'users_unique_account'])
            ->addIndex(['status'], ['name' => 'users_status'])
            ->create();
    }
}

