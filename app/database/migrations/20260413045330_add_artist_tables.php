<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddArtistTables extends AbstractMigration
{
    public function change(): void
    {
        $artistsTable = $this->table('artists');
        $artistsTable
            ->addColumn('name', 'string', ['limit' => 255])
            ->addColumn('lastfm_url', 'string', ['limit' => 255])
            ->addColumn('musicbrainz_id', 'string', ['limit' => 36, 'null' => true])
            ->addColumn('image_hash', 'string', ['limit' => 32])
            ->addColumn('created_at', 'string', ['limit' => 40])
            ->addColumn('updated_at', 'string', ['limit' => 40])
            ->addIndex(['name'], ['unique' => true])
            ->addIndex(['image_hash'])
            ->create();

        $statsTable = $this->table('artist_stats');
        $statsTable
            ->addColumn('artist_id', 'integer')
            ->addColumn('user_id', 'integer')
            ->addColumn('position', 'integer')
            ->addColumn('play_count', 'integer')
            ->addColumn('recorded_at', 'string', ['limit' => 40])
            ->addForeignKey('artist_id', 'artists', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE'])
            ->addIndex(['artist_id', 'user_id', 'recorded_at'])
            ->addIndex(['recorded_at'])
            ->create();
    }
}