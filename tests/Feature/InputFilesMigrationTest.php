<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Momo630\LaravelDataPatches\Models\DataPatchApplication;
use Tests\TestCase;

class InputFilesMigrationTest extends TestCase
{
    public function test_additive_migration_preserves_old_rows_and_rolls_back_only_the_new_column(): void
    {
        $original = require __DIR__.'/../../database/migrations/2026_09_08_000000_create_data_patch_applications_table.php';
        $migration = require __DIR__.'/../../database/migrations/2026_11_20_000000_add_input_files_to_data_patch_applications_table.php';
        $original->up();
        DataPatchApplication::query()->create([
            'patch_id' => 'legacy', 'description' => 'legacy application', 'outcome' => 'applied',
            'result' => ['changed' => 42], 'recorded_at' => now('UTC'),
        ]);

        $migration->up();
        $application = DataPatchApplication::query()->findOrFail('legacy');
        $this->assertNull($application->input_files);
        $this->assertSame(['changed' => 42], $application->result);
        $application->update(['input_files' => [['path' => 'source.json', 'disk' => 's3', 'bucket' => 'private', 'key' => 'patches/legacy/source.json', 'sha256' => hash('sha256', 'source')]]]);
        $this->assertSame('source.json', $application->fresh()->input_files[0]['path']);

        $migration->down();
        $this->assertFalse(Schema::hasColumn('data_patch_applications', 'input_files'));
        $this->assertDatabaseHas('data_patch_applications', ['patch_id' => 'legacy', 'outcome' => 'applied']);
        $this->assertSame(['changed' => 42], $application->fresh()->result);
        $original->down();
    }
}
