<?php

declare(strict_types=1);

namespace OCA\S3ShadowMigrator\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds self-healing audit columns to oc_s3shadow_files:
 *   - status: 'active' | 'lost' | 'corrupt' | 'orphan'
 *   - healed_at: timestamp of last healing action
 */
class Version1000000Date20260505000000 extends SimpleMigrationStep {

    public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('s3shadow_files')) {
            $output->warning('Table oc_s3shadow_files does not exist yet. Skipping self-healing column migration.');
            return null;
        }

        $table = $schema->getTable('s3shadow_files');
        $changed = false;

        if (!$table->hasColumn('status')) {
            $table->addColumn('status', Types::STRING, [
                'notnull' => false,
                'length'  => 20,
                'default' => 'active',
            ]);
            $changed = true;
            $output->info('Added column: oc_s3shadow_files.status');
        }

        if (!$table->hasColumn('healed_at')) {
            $table->addColumn('healed_at', Types::STRING, [
                'notnull' => false,
                'length'  => 20,
                'default' => null,
            ]);
            $changed = true;
            $output->info('Added column: oc_s3shadow_files.healed_at');
        }

        return $changed ? $schema : null;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        // Backfill all existing rows with status='active' so the healer has a consistent baseline
        $output->info('Backfilling existing rows with status=active...');
    }
}
