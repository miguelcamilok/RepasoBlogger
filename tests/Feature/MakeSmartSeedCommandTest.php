<?php

namespace Tests\Feature;

use App\Console\Commands\MakeSmartSeed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Faker\Factory as Faker;
use Mockery;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use PHPUnit\Framework\Attributes\Test;

class MakeSmartSeedCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::shouldReceive('hasTable')->andReturn(true);
        Schema::shouldReceive('getColumnListing')->andReturn([
            'id',
            'name',
            'email',
            'password',
            'created_at',
            'updated_at'
        ]);
        Schema::shouldReceive('getColumnType')->andReturn('string');
    }

    #[Test]
    public function it_runs_without_arguments_and_generates_all_models()
    {
        File::shouldReceive('isDirectory')->andReturn(true);
        File::shouldReceive('allFiles')->andReturn([]);

        $this->artisan('make:smart-seed', ['--count' => 5])
            ->expectsOutputToContain('Iniciando')
            ->assertSuccessful();
    }

    #[Test]
    public function it_generates_for_specific_model()
    {
        $fakeModel = new class extends \Illuminate\Database\Eloquent\Model {
            protected $table = 'users';
            protected $fillable = ['name', 'email', 'password'];
        };

        $this->app->bind('App\\Models\\User', fn() => $fakeModel);

        DB::shouldReceive('table->insertGetId')->andReturnUsing(fn() => rand(1, 999));

        File::shouldReceive('isDirectory')->andReturn(true);
        File::shouldReceive('allFiles')->andReturn([]);

        $this->artisan('make:smart-seed', ['model' => 'User', '--count' => 5])
            ->expectsOutputToContain('User')
            ->assertSuccessful();
    }

    #[Test]
    public function it_can_refresh_tables_before_inserting()
    {
        DB::shouldReceive('statement');
        DB::shouldReceive('table->truncate');
        File::shouldReceive('isDirectory')->andReturn(true);
        File::shouldReceive('allFiles')->andReturn([]);

        $this->artisan('make:smart-seed', ['--refresh' => true])
            ->expectsOutputToContain('Iniciando')
            ->assertSuccessful();
    }

    #[Test]
    public function it_generates_only_pivots_when_option_is_enabled()
    {
        File::shouldReceive('isDirectory')->andReturn(true);
        File::shouldReceive('allFiles')->andReturn([]);

        $this->artisan('make:smart-seed', ['--only-pivots' => true])
            ->expectsOutputToContain('pivote')
            ->assertSuccessful();
    }

    #[Test]
    public function it_handles_exceptions_gracefully()
    {
        File::shouldReceive('isDirectory')->andThrow(new \Exception('Fallo simulado'));

        $this->artisan('make:smart-seed')
            ->expectsOutputToContain('Error')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_generates_various_types_of_values_from_patterns()
    {
        $command = new MakeSmartSeed();
        $faker = Faker::create('es_ES');

        $prop = new \ReflectionProperty($command, 'faker');
        $prop->setAccessible(true);
        $prop->setValue($command, $faker);

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('generateFromPattern');
        $method->setAccessible(true);

        $types = [
            ['type' => 'email'],
            ['type' => 'boolean'],
            ['type' => 'integer'],
            ['type' => 'float'],
            ['type' => 'uuid'],
            ['type' => 'enum', 'values' => ['A', 'B']],
            ['type' => 'reference', 'prefix' => 'INV'],
        ];

        foreach ($types as $pattern) {
            $value = $method->invoke($command, $pattern);
            $this->assertNotNull($value);
        }
    }

    #[Test]
    public function it_ensures_unique_values_are_generated()
    {
        $command = new MakeSmartSeed();
        $faker = Faker::create();

        $prop = new \ReflectionProperty($command, 'faker');
        $prop->setAccessible(true);
        $prop->setValue($command, $faker);

        $method = (new \ReflectionClass($command))->getMethod('ensureUnique');
        $method->setAccessible(true);

        $first = $method->invoke($command, 'users', 'email', 'test@example.com', 'App\\Models\\User', new \stdClass());
        $second = $method->invoke($command, 'users', 'email', 'test@example.com', 'App\\Models\\User', new \stdClass());

        $this->assertNotEquals($first, $second);
    }

    #[Test]
    public function it_generates_foreign_keys_with_existing_relations()
    {
        $command = new MakeSmartSeed();
        $faker = \Faker\Factory::create();

        $prop = new \ReflectionProperty($command, 'faker');
        $prop->setAccessible(true);
        $prop->setValue($command, $faker);

        // ✅ Usar Illuminate\Console\OutputStyle
        $input = new \Symfony\Component\Console\Input\ArrayInput([]);
        $output = new \Symfony\Component\Console\Output\BufferedOutput();
        $io = new \Illuminate\Console\OutputStyle($input, $output);
        $command->setOutput($io);

        \Illuminate\Support\Facades\DB::shouldReceive('table->pluck')->andReturn([1, 2, 3]);

        $method = (new \ReflectionClass($command))->getMethod('generateForeignKey');
        $method->setAccessible(true);

        $model = new class extends \Illuminate\Database\Eloquent\Model {
            public function category()
            {
                return $this->belongsTo(\App\Models\Category::class);
            }
        };

        $relations = [];
        $result = $method->invokeArgs($command, [get_class($model), 'category_id', $model, &$relations]);
        $this->assertContains($result, [1, 2, 3]);
    }


    #[Test]
    public function it_handles_pivot_generation_with_existing_data()
    {
        $command = Mockery::mock(MakeSmartSeed::class)->makePartial();
        $command->shouldReceive('info');

        $faker = Faker::create();
        $prop = new \ReflectionProperty(MakeSmartSeed::class, 'faker');
        $prop->setAccessible(true);
        $prop->setValue($command, $faker);

        DB::shouldReceive('table->insert')->andReturn(true);
        DB::shouldReceive('table->pluck')->andReturn([1, 2, 3]);
        DB::shouldReceive('table->get')->andReturn(collect());

        $relation = new class {
            public function getTable()
            {
                return 'category_product';
            }
            public function getForeignPivotKeyName()
            {
                return 'category_id';
            }
            public function getRelatedPivotKeyName()
            {
                return 'product_id';
            }
            public function getRelated()
            {
                return new class {};
            }
        };

        $refProp = new \ReflectionProperty(MakeSmartSeed::class, 'generatedIds');
        $refProp->setAccessible(true);
        $refProp->setValue($command, [
            'App\\Models\\Category' => [1, 2, 3],
            'App\\Models\\Product' => [4, 5, 6],
        ]);

        $method = (new \ReflectionClass($command))->getMethod('generateBelongsToManyRecords');
        $method->setAccessible(true);

        $method->invoke($command, 'App\\Models\\Category', 'products', $relation);
        $this->assertTrue(true);
    }
}
