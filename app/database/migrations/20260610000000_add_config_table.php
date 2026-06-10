<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddConfigTable extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('config', ['id' => false, 'primary_key' => ['key']]);
        $table
            ->addColumn('key', 'string', ['limit' => 100])
            ->addColumn('value', 'text', ['null' => true, 'default' => null])
            ->create();
    }
}
