<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Momo630\LaravelDataPatches\Support\DataPatchRunner;
use Mockery;
use Tests\TestCase;

class DataPatchCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $fixtureFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'data-patches.path' => $this->fixtureDirectory(),
            'data-patches.namespace' => 'Tests\\Fixtures\\DataPatches',
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtureFiles as $file) {
            unlink($file);
        }

        parent::tearDown();
    }

    public function test_known_name_resolves_and_forwards_dry_run_without_database_writes(): void
    {
        $this->addPatchFixture('KnownPatch', 'fixture-patch');
        $runner = Mockery::mock(DataPatchRunner::class);
        $runner->expects('run')
            ->withArgs(fn ($patch, bool $dryRun): bool => $patch::NAME === 'fixture-patch' && $dryRun)
            ->andReturn(['outcome' => 'dry_run', 'result' => []]);
        $this->app->instance(DataPatchRunner::class, $runner);

        $this->artisan('app:data-patch fixture-patch --dry-run --no-interaction')
            ->expectsOutputToContain('dry_run')
            ->assertSuccessful();
        $this->assertDatabaseCount('data_patch_applications', 0);
    }

    public function test_unknown_patch_is_rejected_before_running(): void
    {
        $this->artisan('app:data-patch missing --no-interaction')
            ->expectsOutputToContain('Unknown data patch: missing.')
            ->assertFailed();
    }

    public function test_production_requires_force_and_force_does_not_bypass_history(): void
    {
        $this->addPatchFixture('RecordedPatch', 'recorded-patch', 'recorded patch');
        $this->app->instance('env', 'production');
        $this->artisan('app:data-patch missing --no-interaction')
            ->expectsOutputToContain('Production requires --force.')
            ->assertFailed();

        DB::table('data_patch_applications')->insert(['patch_id' => 'recorded-patch', 'description' => 'recorded patch', 'outcome' => 'applied', 'recorded_at' => now('UTC')]);
        $this->artisan('app:data-patch recorded-patch --dry-run --force --no-interaction')
            ->expectsOutputToContain('Data patch recorded-patch has already been recorded.')
            ->assertFailed();
    }

    public function test_duplicate_patch_name_is_rejected(): void
    {
        $this->addPatchFixture('FirstDuplicatePatch', 'duplicate-patch');
        $this->addPatchFixture('SecondDuplicatePatch', 'duplicate-patch');

        $this->artisan('app:data-patch duplicate-patch --no-interaction')
            ->expectsOutputToContain('Duplicate data patch name.')
            ->assertFailed();
    }

    public function test_empty_patch_description_is_rejected(): void
    {
        $this->addPatchFixture('EmptyDescriptionPatch', 'empty-description', '');

        $this->artisan('app:data-patch empty-description --no-interaction')
            ->expectsOutputToContain('Invalid data patch: Tests\\Fixtures\\DataPatches\\EmptyDescriptionPatch.')
            ->assertFailed();
    }

    public function test_non_patch_class_is_rejected(): void
    {
        $this->addFixture('InvalidPatch', 'class InvalidPatch {}');

        $this->artisan('app:data-patch fixture-patch --no-interaction')
            ->expectsOutputToContain('Invalid data patch: Tests\\Fixtures\\DataPatches\\InvalidPatch.')
            ->assertFailed();
    }

    public function test_empty_patch_name_is_rejected(): void
    {
        $this->addPatchFixture('EmptyNamePatch', '');

        $this->artisan('app:data-patch fixture-patch --no-interaction')
            ->expectsOutputToContain('Invalid data patch: Tests\\Fixtures\\DataPatches\\EmptyNamePatch.')
            ->assertFailed();
    }

    public function test_configured_path_and_namespace_resolve_patches(): void
    {
        $path = dirname(__DIR__, 2).'/Fixtures/ConfiguredPatches';
        config([
            'data-patches.path' => $path,
            'data-patches.namespace' => 'Tests\\Fixtures\\ConfiguredPatches',
        ]);
        $this->addPatchFixture('ConfiguredPatch', 'configured-patch', 'configured fixture', $path, 'Tests\\Fixtures\\ConfiguredPatches');

        $this->artisan('app:data-patch configured-patch --dry-run --no-interaction')
            ->expectsOutputToContain('dry_run')
            ->assertSuccessful();
    }

    private function addPatchFixture(string $class, string $name, string $description = 'fixture', ?string $directory = null, string $namespace = 'Tests\\Fixtures\\DataPatches'): void
    {
        $this->addFixture($class, "use Momo630\\LaravelDataPatches\\Contracts\\DataPatch;\n\nclass {$class} implements DataPatch\n{\n    public const NAME = '{$name}';\n    public const DESCRIPTION = '{$description}';\n\n    public function preview(): array { return []; }\n    public function apply(): array { return []; }\n}", $directory, $namespace);
    }

    private function addFixture(string $class, string $definition, ?string $directory = null, string $namespace = 'Tests\\Fixtures\\DataPatches'): void
    {
        $directory ??= $this->fixtureDirectory();
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $file = $directory."/{$class}.php";
        file_put_contents($file, "<?php\n\nnamespace {$namespace};\n\n{$definition}\n");
        $this->fixtureFiles[] = $file;
    }

    private function fixtureDirectory(): string
    {
        return dirname(__DIR__, 2).'/Fixtures/DataPatches';
    }
}
