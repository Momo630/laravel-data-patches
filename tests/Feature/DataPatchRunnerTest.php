<?php

namespace Tests\Feature;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Momo630\LaravelDataPatches\Contracts\DataPatch;
use Momo630\LaravelDataPatches\Contracts\RequiresDataFiles;
use Momo630\LaravelDataPatches\Models\DataPatchApplication;
use Momo630\LaravelDataPatches\Support\DataPatchRunner;
use Momo630\LaravelDataPatches\Support\HasDataPatchFiles;
use PDOException;
use RuntimeException;
use Tests\TestCase;

class DataPatchRunnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_only_previews(): void
    {
        $patch = new RunnerTestPatch;
        $result = app(DataPatchRunner::class)->run($patch, true);

        $this->assertSame('dry_run', $result['outcome']);
        $this->assertSame(0, $patch->applies);
        $this->assertSame(1, $patch->previews);
        $this->assertDatabaseMissing('data_patch_applications', ['patch_id' => $patch::NAME]);
    }

    public function test_success_is_recorded_and_repeated_runs_are_blocked(): void
    {
        $patch = new RunnerTestPatch;
        $runner = app(DataPatchRunner::class);
        $this->assertSame('applied', $runner->run($patch)['outcome']);
        $history = DataPatchApplication::query()->get()->map->getAttributes()->all();

        $this->assertThrows(fn () => $runner->run($patch), RuntimeException::class);
        $this->assertSame(1, $patch->applies);
        $this->assertSame($history, DataPatchApplication::query()->get()->map->getAttributes()->all());
        $this->assertDatabaseHas('data_patch_applications', ['patch_id' => $patch::NAME, 'outcome' => 'applied']);
    }

    public function test_missing_required_file_blocks_preview_and_apply_without_writes(): void
    {
        $patch = new RequiredFileRunnerTestPatch;
        $this->assertSame([storage_path('app/private/data-patches/required-files-test/source.json')], $patch->requiredFiles());

        foreach ([true, false] as $dryRun) {
            $this->assertThrows(fn () => app(DataPatchRunner::class)->run($patch, $dryRun), RuntimeException::class);
        }

        $this->assertSame(0, $patch->previews);
        $this->assertSame(0, $patch->applies);
        $this->assertDatabaseCount('data_patch_applications', 0);
    }

    public function test_readable_required_files_allow_preview_and_apply_without_automatic_preview(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'data-patch-');
        try {
            $patch = new RequiredFileRunnerTestPatch([$file]);
            $runner = app(DataPatchRunner::class);

            $this->assertSame('dry_run', $runner->run($patch, true)['outcome']);
            $this->assertSame(0, $patch->applies);
            $this->assertDatabaseCount('data_patch_applications', 0);
            $this->assertSame('applied', $runner->run($patch)['outcome']);
            $this->assertSame(1, $patch->previews);
            $this->assertSame(1, $patch->applies);
            $this->assertDatabaseHas('data_patch_applications', ['patch_id' => $patch::NAME, 'outcome' => 'applied']);
        } finally {
            unlink($file);
        }
    }

    public function test_invalid_required_file_paths_are_rejected_without_callbacks_or_ledger(): void
    {
        foreach (['', 'source.json', 42] as $file) {
            $patch = new RequiredFileRunnerTestPatch([$file]);

            $this->assertThrows(fn () => app(DataPatchRunner::class)->run($patch), RuntimeException::class);
            $this->assertSame(0, $patch->previews);
            $this->assertSame(0, $patch->applies);
        }

        $this->assertDatabaseCount('data_patch_applications', 0);
    }

    public function test_required_file_cannot_be_a_directory(): void
    {
        $patch = new RequiredFileRunnerTestPatch([sys_get_temp_dir()]);

        $this->assertThrows(fn () => app(DataPatchRunner::class)->run($patch), RuntimeException::class);

        $this->assertSame(0, $patch->applies);
        $this->assertDatabaseCount('data_patch_applications', 0);
    }

    public function test_recorded_patch_does_not_check_required_files(): void
    {
        $patch = new RequiredFileRunnerTestPatch;
        DataPatchApplication::query()->create(['patch_id' => $patch::NAME, 'description' => $patch::DESCRIPTION, 'outcome' => 'applied', 'recorded_at' => now('UTC')]);

        foreach ([true, false] as $dryRun) {
            $this->assertThrows(fn () => app(DataPatchRunner::class)->run($patch, $dryRun), RuntimeException::class);
        }

        $this->assertSame(0, $patch->fileChecks);
        $this->assertSame(0, $patch->previews);
        $this->assertSame(0, $patch->applies);
        $this->assertDatabaseCount('data_patch_applications', 1);
    }

    public function test_recorded_patch_blocks_dry_run_without_previewing(): void
    {
        $patch = new RunnerTestPatch;
        DataPatchApplication::query()->create(['patch_id' => $patch::NAME, 'description' => $patch::DESCRIPTION, 'outcome' => 'applied', 'recorded_at' => now('UTC')]);

        $this->assertThrows(fn () => app(DataPatchRunner::class)->run($patch, true), RuntimeException::class);

        $this->assertSame(0, $patch->previews);
        $this->assertSame(0, $patch->applies);
    }

    public function test_failed_apply_rolls_back_the_reservation_and_can_retry(): void
    {
        $patch = new RunnerTestPatch(true);
        $runner = app(DataPatchRunner::class);
        $this->assertThrows(fn () => $runner->run($patch), RuntimeException::class);
        $patch->fails = false;
        $this->assertSame('applied', $runner->run($patch)['outcome']);
        $this->assertSame(2, $patch->applies);
    }

    public function test_already_satisfied_is_recorded_without_applied_at(): void
    {
        $patch = new RunnerTestPatch(false, true);
        app(DataPatchRunner::class)->run($patch);

        $this->assertDatabaseHas('data_patch_applications', ['patch_id' => $patch::NAME, 'outcome' => 'already_satisfied', 'applied_at' => null]);
    }

    public function test_runner_rejects_incomplete_metadata(): void
    {
        $patch = new class implements DataPatch
        {
            public function preview(): array
            {
                return [];
            }

            public function apply(): array
            {
                return [];
            }
        };

        $this->assertThrows(fn () => app(DataPatchRunner::class)->run($patch), RuntimeException::class);
    }

    public function test_ledger_model_uses_a_string_primary_key_and_casts(): void
    {
        $application = DataPatchApplication::query()->create([
            'patch_id' => 'model-test',
            'description' => 'model test',
            'outcome' => 'applied',
            'result' => ['changed' => true],
            'applied_at' => now('UTC'),
            'recorded_at' => now('UTC'),
        ]);

        $this->assertFalse($application->getIncrementing());
        $this->assertSame('string', $application->getKeyType());
        $this->assertSame(['changed' => true], $application->fresh()->result);
        $this->assertInstanceOf(\Carbon\CarbonInterface::class, $application->fresh()->applied_at);
    }

    public function test_mysql_primary_key_collision_blocks_the_patch_callback(): void
    {
        $patch = new RunnerTestPatch;
        app('db')->connection()->beforeExecuting(static function (string $query): void {
            if (str_starts_with(strtolower($query), 'insert into "data_patch_applications"')) {
                throw RunnerTestPatch::mysqlUniqueViolation();
            }
        });

        $this->assertThrows(fn () => app(DataPatchRunner::class)->run($patch), RuntimeException::class);

        $this->assertSame(0, $patch->applies);
    }

    public function test_unique_violation_from_the_patch_callback_is_rethrown(): void
    {
        $patch = new RunnerTestPatch(throwsUniqueConstraint: true);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->expectExceptionMessage("Duplicate entry 'runner-test' for key 'PRIMARY'");

        app(DataPatchRunner::class)->run($patch);
    }
}

final class RequiredFileRunnerTestPatch implements RequiresDataFiles
{
    use HasDataPatchFiles;

    public const NAME = 'required-files-test';

    public const DESCRIPTION = 'required files test';

    protected const DATA_DIRECTORY = 'required-files-test';

    public int $fileChecks = 0;

    public int $previews = 0;

    public int $applies = 0;

    public function __construct(private ?array $files = null) {}

    public function requiredFiles(): array
    {
        $this->fileChecks++;

        return $this->files ?? [$this->dataPath('source.json')];
    }

    public function preview(): array
    {
        $this->previews++;

        return [];
    }

    public function apply(): array
    {
        $this->applies++;

        return [];
    }
}

final class RunnerTestPatch implements DataPatch
{
    public const NAME = 'runner-test';

    public const DESCRIPTION = 'runner test';

    public int $applies = 0;

    public int $previews = 0;

    public function __construct(public bool $fails = false, private bool $satisfied = false, private bool $throwsUniqueConstraint = false) {}

    public function preview(): array
    {
        $this->previews++;

        return ['preview' => true];
    }

    public function apply(): array
    {
        $this->applies++;
        if ($this->fails) {
            throw new RuntimeException('expected failure');
        }

        if ($this->throwsUniqueConstraint) {
            throw self::mysqlUniqueViolation();
        }

        return $this->satisfied ? ['outcome' => 'already_satisfied', 'result' => ['changed' => false]] : ['result' => ['changed' => true]];
    }

    public static function mysqlUniqueViolation(): UniqueConstraintViolationException
    {
        return new UniqueConstraintViolationException(
            'mysql',
            'insert into data_patch_applications (patch_id) values (?)',
            ['runner-test'],
            new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'runner-test' for key 'PRIMARY'", 23000),
        );
    }
}
