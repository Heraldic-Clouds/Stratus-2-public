<?php
declare(strict_types=1);
namespace OCA\Stratus2AI\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
final class Version000100Date20261015000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if (!$schema->hasTable('stratus2_jobs')) {
            $table = $schema->createTable('stratus2_jobs');
            $table->addColumn('id', 'string', ['length'=>32]);
            $table->addColumn('user_id', 'string', ['length'=>64]);
            $table->addColumn('state', 'string', ['length'=>24]);
            $table->addColumn('payload', 'text', ['notnull'=>true]);
            $table->addColumn('revision', 'integer', ['default'=>0]);
            $table->addColumn('created_at', 'bigint');
            $table->addColumn('updated_at', 'bigint');
            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id', 'created_at'], 'stratus2_user_jobs');
            $table->addIndex(['state'], 'stratus2_job_state');
        }
        return $schema;
    }
}
