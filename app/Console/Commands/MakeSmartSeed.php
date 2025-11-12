<?php

namespace App\Console\Commands;

use Faker\Factory as Faker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;

class MakeSmartSeed extends Command
{
    protected $signature = 'make:smart-seed 
                            {model? : El modelo específico a generar (opcional)}
                            {--count=10 : Cantidad de registros a generar}
                            {--refresh : Truncar tablas antes de insertar}
                            {--only-pivots : Solo generar relaciones en tablas pivote}';

    protected $description = 'Genera datos de prueba inteligentes con relaciones coherentes';

    protected $faker;

    protected $generatedIds = [];

    protected $processedModels = [];

    protected $uniqueValues = [];

    protected $modelClass = [];

    // Configuración de patrones para detección inteligente
    protected array $columnPatterns = [
        // Patrones exactos (prioridad alta)
        'exact' => [
            'email_verified_at' => ['type' => 'datetime', 'range' => ['-1 year', 'now']],
            'published_at' => ['type' => 'datetime', 'range' => ['-1 year', 'now']],
            'verified_at' => ['type' => 'datetime', 'range' => ['-1 year', 'now']],
            'birth_date' => ['type' => 'date', 'max' => '-18 years'],
            'birthdate' => ['type' => 'date', 'max' => '-18 years'],
            'password' => ['type' => 'bcrypt', 'value' => 'password'],
            'remember_token' => ['type' => 'string', 'length' => 60],
            'api_token' => ['type' => 'string', 'length' => 80],
            'verification_code' => ['type' => 'numeric', 'pattern' => '######'],
            'invoice_number' => ['type' => 'reference', 'prefix' => 'INV'],
            'order_number' => ['type' => 'reference', 'prefix' => 'ORD'],
        ],

        // Patrones de sufijos (prioridad media-alta)
        'suffix' => [
            '_at' => ['type' => 'datetime'],
            '_date' => ['type' => 'date'],
            '_time' => ['type' => 'time'],
            '_id' => ['type' => 'foreign_key'],
        ],

        // Patrones de prefijos (prioridad media)
        'prefix' => [
            'is_' => ['type' => 'boolean'],
            'has_' => ['type' => 'boolean'],
            'can_' => ['type' => 'boolean'],
            'should_' => ['type' => 'boolean'],
            'will_' => ['type' => 'boolean'],
            'must_' => ['type' => 'boolean'],
        ],

        // Patrones por palabra clave (prioridad baja)
        'contains' => [
            'email' => ['type' => 'email', 'unique' => true],
            'username' => ['type' => 'username', 'unique' => true],
            'slug' => ['type' => 'slug', 'unique' => true],
            'phone' => ['type' => 'phone'],
            'mobile' => ['type' => 'phone'],
            'telephone' => ['type' => 'phone'],
            'address' => ['type' => 'address'],
            'street' => ['type' => 'street'],
            'city' => ['type' => 'city'],
            'state' => ['type' => 'state'],
            'country' => ['type' => 'country'],
            'postal' => ['type' => 'postcode'],
            'zip' => ['type' => 'postcode'],
            'name' => ['type' => 'name'],
            'title' => ['type' => 'title'],
            'description' => ['type' => 'paragraph'],
            'content' => ['type' => 'text', 'paragraphs' => 3],
            'body' => ['type' => 'text', 'paragraphs' => 5],
            'bio' => ['type' => 'paragraph'],
            'url' => ['type' => 'url'],
            'website' => ['type' => 'url'],
            'link' => ['type' => 'url'],
            'image' => ['type' => 'image'],
            'avatar' => ['type' => 'image', 'category' => 'people'],
            'photo' => ['type' => 'image', 'width' => 800, 'height' => 600],
            'thumbnail' => ['type' => 'image', 'width' => 150, 'height' => 150],
            'logo' => ['type' => 'image', 'width' => 300, 'height' => 100],
            'age' => ['type' => 'integer', 'min' => 18, 'max' => 80],
            'year' => ['type' => 'year'],
            'price' => ['type' => 'float', 'min' => 10, 'max' => 1000],
            'cost' => ['type' => 'float', 'min' => 5, 'max' => 500],
            'amount' => ['type' => 'float', 'min' => 0, 'max' => 10000],
            'salary' => ['type' => 'float', 'min' => 20000, 'max' => 100000],
            'quantity' => ['type' => 'integer', 'min' => 1, 'max' => 100],
            'stock' => ['type' => 'integer', 'min' => 0, 'max' => 500],
            'views' => ['type' => 'integer', 'min' => 0, 'max' => 100000],
            'rating' => ['type' => 'float', 'min' => 0, 'max' => 5, 'decimals' => 1],
            'score' => ['type' => 'integer', 'min' => 0, 'max' => 100],
            'status' => ['type' => 'enum', 'values' => ['active', 'inactive', 'pending', 'completed']],
            'role' => ['type' => 'enum', 'values' => ['admin', 'user', 'guest']],
            'priority' => ['type' => 'enum', 'values' => ['low', 'medium', 'high']],
            'gender' => ['type' => 'enum', 'values' => ['male', 'female', 'other']],
            'token' => ['type' => 'string', 'length' => 60],
            'code' => ['type' => 'alphanumeric', 'pattern' => '???-###'],
            'uuid' => ['type' => 'uuid'],
            'color' => ['type' => 'color'],
            'ip' => ['type' => 'ip'],
            'latitude' => ['type' => 'latitude'],
            'longitude' => ['type' => 'longitude'],
            'company' => ['type' => 'company'],
            'position' => ['type' => 'job_title'],
            'locale' => ['type' => 'locale'],
            'language' => ['type' => 'language_code'],
            'timezone' => ['type' => 'timezone'],
            'currency' => ['type' => 'currency'],
        ],
    ];

    public function handle()
    {
        $this->faker = Faker::create('es_ES');
        $count = (int) $this->option('count');
        $modelName = $this->argument('model');
        $onlyPivots = $this->option('only-pivots');

        $this->info("🚀 Iniciando generación de datos inteligentes...\n");

        try {
            if ($onlyPivots) {
                $this->generateOnlyPivots();

                return 0;
            }

            if ($modelName) {
                $this->generateForModel($modelName, $count);
            } else {
                $this->generateForAllModels($count);
            }

            $this->info("\n✨ Proceso completado exitosamente!");

            return 0;
        } catch (\Exception $e) {
            $this->error("\n❌ Error: " . $e->getMessage());
            $this->error($e->getTraceAsString());

            return 1;
        }
    }

    protected function generateForModel(string $modelName, int $count)
    {
        $modelClass = $this->getModelClass($modelName);

        if (! $modelClass) {
            $this->error("❌ Modelo {$modelName} no encontrado");

            return;
        }

        if ($this->option('refresh')) {
            $this->truncateModel($modelClass);
        }

        $this->insertRecords($modelClass, $count);
        $this->generatePivotsForModel($modelClass);
    }

    protected function generateForAllModels(int $count)
    {
        $models = $this->getAllModels();

        if (empty($models)) {
            $this->warn('⚠️  No se encontraron modelos en app/Models');

            return;
        }

        $sortedModels = $this->sortModelsByMigrations($models);

        if ($this->option('refresh')) {
            $this->info('🗑️  Limpiando tablas...');
            foreach (array_reverse($sortedModels) as $modelClass) {
                $this->truncateModel($modelClass);
            }
        }

        foreach ($sortedModels as $modelClass) {
            $this->insertRecords($modelClass, $count);
        }

        $this->info("\n🔗 Generando relaciones en tablas pivote...");
        foreach ($sortedModels as $modelClass) {
            $this->generatePivotsForModel($modelClass);
        }
    }

    protected function getAllModels(): array
    {
        $modelsPath = app_path('Models');
        $models = [];

        if (! File::isDirectory($modelsPath)) {
            return $models;
        }

        $files = File::allFiles($modelsPath);

        foreach ($files as $file) {
            $className = 'App\\Models\\' . str_replace(
                ['/', '.php'],
                ['\\', ''],
                Str::after($file->getPathname(), app_path('Models') . DIRECTORY_SEPARATOR)
            );

            if (class_exists($className)) {
                $reflection = new ReflectionClass($className);
                if (! $reflection->isAbstract() && $reflection->isSubclassOf('Illuminate\Database\Eloquent\Model')) {
                    $models[] = $className;
                }
            }
        }

        return $models;
    }

    protected function sortModelsByMigrations(array $models): array
    {
        $migrationsPath = database_path('migrations');

        if (! File::isDirectory($migrationsPath)) {
            $this->warn('⚠️  Carpeta de migraciones no encontrada');

            return $models;
        }

        $migrationFiles = File::files($migrationsPath);
        usort($migrationFiles, fn($a, $b) => strcmp($a->getFilename(), $b->getFilename()));

        $tablesOrder = [];
        foreach ($migrationFiles as $file) {
            $tableName = $this->extractTableNameFromMigration($file);
            if ($tableName) {
                $tablesOrder[] = $tableName;
            }
        }

        $tableToModel = [];
        foreach ($models as $modelClass) {
            try {
                $model = new $modelClass;
                $table = $model->getTable();

                if (Schema::hasTable($table)) {
                    $tableToModel[$table] = $modelClass;
                }
            } catch (\Exception $e) {
                // Modelo sin tabla válida, se omite
            }
        }

        $sortedModels = [];
        foreach ($tablesOrder as $table) {
            if (isset($tableToModel[$table])) {
                $sortedModels[] = $tableToModel[$table];
                unset($tableToModel[$table]);
            }
        }

        foreach ($tableToModel as $modelClass) {
            $sortedModels[] = $modelClass;
        }

        return $sortedModels;
    }

    protected function extractTableNameFromMigration($file): ?string
    {
        $content = File::get($file->getPathname());

        $patterns = [
            "/Schema::create\s*\(\s*['\"]([^'\"]+)['\"]/",
            "/Schema::table\s*\(\s*['\"]([^'\"]+)['\"]/",
            "/Schema::rename\s*\(\s*['\"]([^'\"]+)['\"].*['\"]([^'\"]+)['\"]/",
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                if (isset($matches[1])) {
                    return $matches[count($matches) - 1];
                }
            }
        }

        $fileName = $file->getFilename();
        if (preg_match('/_create_(.+)_table\.php$/', $fileName, $matches)) {
            return $matches[1];
        }

        return null;
    }

    protected function insertRecords(string $modelClass, int $count)
    {
        $model = new $modelClass;
        $table = $model->getTable();
        $modelName = class_basename($modelClass);

        if (! Schema::hasTable($table)) {
            $this->warn("⚠️  Tabla {$table} no existe para {$modelName}");

            return;
        }

        if (isset($this->processedModels[$modelClass])) {
            return;
        }

        $this->info("📝 Generando {$count} registros para {$modelName}...");

        $columns = Schema::getColumnListing($table);
        $fillableColumns = $this->getFillableColumns($model, $columns);

        try {
            $insertedIds = [];

            for ($i = 0; $i < $count; $i++) {
                $record = $this->generateRecord($modelClass, $fillableColumns);

                $hasNullRequired = false;
                foreach ($record as $key => $value) {
                    if ($value === null && Str::endsWith($key, '_id')) {
                        $hasNullRequired = true;
                        break;
                    }
                }

                if (! $hasNullRequired) {
                    try {
                        $id = DB::table($table)->insertGetId($record);
                        $insertedIds[] = $id;
                    } catch (\Exception $e) {
                        if (Str::contains($e->getMessage(), ['Duplicate entry', 'UNIQUE'])) {
                            $this->warn('  ⚠️  Registro duplicado detectado, regenerando...');

                            continue;
                        } else {
                            throw $e;
                        }
                    }
                }
            }

            if (empty($insertedIds)) {
                $insertedIds = DB::table($table)->pluck('id')->toArray();
            }

            $this->generatedIds[$modelClass] = $insertedIds;
            $this->processedModels[$modelClass] = true;

            $actualCount = count($insertedIds);
            $this->info("✅ Se generaron {$actualCount} registros para {$modelName}");
        } catch (\Exception $e) {
            $this->error("❌ Error al insertar {$modelName}: " . $e->getMessage());
        }
    }

    protected function getFillableColumns($model, array $columns): array
    {
        $excluded = ['id', 'created_at', 'updated_at', 'deleted_at', 'remember_token'];

        return array_filter($columns, function ($column) use ($excluded, $model) {
            if (in_array($column, $excluded)) {
                return false;
            }

            if (property_exists($model, 'guarded') && ! empty($model->guarded)) {
                return ! in_array($column, $model->guarded);
            }

            return true;
        });
    }

    protected function generateRecord(string $modelClass, array $columns): array
    {
        $record = [];
        $model = new $modelClass;
        $generatedForRelations = [];
        $table = $model->getTable();

        if (! isset($this->uniqueValues[$table])) {
            $this->uniqueValues[$table] = [];
        }

        // Primero generar campos normales
        foreach ($columns as $column) {
            if (! Str::endsWith($column, '_id')) {
                $value = $this->generateValueForColumn($modelClass, $column, $model);

                // Manejar unicidad
                if ($this->requiresUniqueness($column)) {
                    $value = $this->ensureUnique($table, $column, $value, $modelClass, $model);
                }

                $record[$column] = $value;
            }
        }

        // Luego generar foreign keys
        foreach ($columns as $column) {
            if (Str::endsWith($column, '_id')) {
                $foreignKey = $this->generateForeignKey($modelClass, $column, $model, $generatedForRelations);
                $record[$column] = $foreignKey;
            }
        }

        $record['created_at'] = now();
        $record['updated_at'] = now();

        return $record;
    }

    /**
     * Sistema inteligente de generación de valores basado en patrones
     */
    protected function generateValueForColumn(string $modelClass, string $column, $model)
    {
        if (Str::endsWith($column, '_id')) {
            return null; // Manejado por generateForeignKey
        }

        $columnLower = strtolower($column);

        // 1. Buscar coincidencia exacta (máxima prioridad)
        if (isset($this->columnPatterns['exact'][$columnLower])) {
            return $this->generateFromPattern($this->columnPatterns['exact'][$columnLower]);
        }

        // 2. Buscar por sufijos
        foreach ($this->columnPatterns['suffix'] as $suffix => $pattern) {
            if (Str::endsWith($columnLower, $suffix)) {
                return $this->generateFromPattern($pattern);
            }
        }

        // 3. Buscar por prefijos
        foreach ($this->columnPatterns['prefix'] as $prefix => $pattern) {
            if (Str::startsWith($columnLower, $prefix)) {
                return $this->generateFromPattern($pattern);
            }
        }

        // 4. Buscar por palabra clave contenida
        foreach ($this->columnPatterns['contains'] as $keyword => $pattern) {
            if (Str::contains($columnLower, $keyword)) {
                return $this->generateFromPattern($pattern);
            }
        }

        // 5. Fallback: generar por tipo de columna
        $columnType = $this->getColumnType($model->getTable(), $column);

        return $this->generateByColumnType($columnType, $column);
    }

    /**
     * Genera valor desde un patrón definido
     */
    protected function generateFromPattern(array $pattern)
    {
        return match ($pattern['type']) {
            'datetime' => $this->faker->dateTimeBetween(
                $pattern['range'][0] ?? '-1 year',
                $pattern['range'][1] ?? 'now'
            )->format('Y-m-d H:i:s'),

            'date' => $this->faker->date('Y-m-d', $pattern['max'] ?? 'now'),
            'time' => $this->faker->time('H:i:s'),
            'year' => $this->faker->year(),

            'email' => $this->faker->unique()->safeEmail(),
            'username' => $this->faker->unique()->userName(),
            'slug' => $this->faker->unique()->slug(),
            'name' => $this->faker->name(),
            'title' => $this->faker->sentence(4),

            'phone' => $this->faker->phoneNumber(),
            'address' => $this->faker->address(),
            'street' => $this->faker->streetAddress(),
            'city' => $this->faker->city(),
            'state' => $this->faker->state(),
            'country' => $this->faker->country(),
            'postcode' => $this->faker->postcode(),

            'paragraph' => $this->faker->paragraph(),
            'text' => $this->faker->paragraphs($pattern['paragraphs'] ?? 3, true),

            'url' => $this->faker->url(),
            'image' => $this->faker->imageUrl(
                $pattern['width'] ?? 640,
                $pattern['height'] ?? 480,
                $pattern['category'] ?? null
            ),

            'boolean' => $this->faker->boolean(),

            'integer' => $this->faker->numberBetween(
                $pattern['min'] ?? 1,
                $pattern['max'] ?? 100
            ),

            'float' => $this->faker->randomFloat(
                $pattern['decimals'] ?? 2,
                $pattern['min'] ?? 0,
                $pattern['max'] ?? 1000
            ),

            'enum' => $this->faker->randomElement($pattern['values'] ?? ['option1', 'option2', 'option3']),

            'uuid' => $this->faker->uuid(),
            'color' => $this->faker->hexColor(),
            'ip' => $this->faker->ipv4(),
            'latitude' => $this->faker->latitude(),
            'longitude' => $this->faker->longitude(),

            'company' => $this->faker->company(),
            'job_title' => $this->faker->jobTitle(),

            'locale' => $this->faker->locale(),
            'language_code' => $this->faker->languageCode(),
            'timezone' => $this->faker->timezone(),
            'currency' => $this->faker->currencyCode(),

            'bcrypt' => bcrypt($pattern['value'] ?? 'password'),

            'string' => Str::random($pattern['length'] ?? 60),
            'numeric' => $this->faker->numerify($pattern['pattern'] ?? '######'),
            'alphanumeric' => strtoupper($this->faker->bothify($pattern['pattern'] ?? '???-###')),
            'reference' => strtoupper($this->faker->bothify(($pattern['prefix'] ?? 'REF') . '-########')),

            default => $this->faker->word(),
        };
    }

    protected function getColumnType(string $table, string $column): string
    {
        try {
            $type = Schema::getColumnType($table, $column);

            $typeMap = [
                'bigint' => 'biginteger',
                'int' => 'integer',
                'varchar' => 'string',
                'tinyint' => 'tinyinteger',
                'smallint' => 'smallinteger',
                'mediumint' => 'mediuminteger',
            ];

            return $typeMap[$type] ?? $type;
        } catch (\Exception $e) {
            return 'string';
        }
    }

    protected function generateByColumnType(string $type, string $column)
    {
        return match (strtolower($type)) {
            'string', 'char', 'varchar' => $this->faker->sentence(3),
            'text', 'tinytext', 'mediumtext', 'longtext' => $this->faker->paragraph(),
            'integer', 'int', 'bigint', 'biginteger' => $this->faker->numberBetween(1, 100000),
            'tinyint', 'tinyinteger' => $this->faker->numberBetween(1, 127),
            'smallint', 'smallinteger' => $this->faker->numberBetween(1, 32767),
            'mediumint', 'mediuminteger' => $this->faker->numberBetween(1, 8388607),
            'float', 'double', 'real' => $this->faker->randomFloat(2, 0, 10000),
            'decimal', 'numeric' => $this->faker->randomFloat(2, 0, 99999),
            'boolean', 'bool', 'tinyint(1)' => $this->faker->boolean(),
            'date' => $this->faker->date('Y-m-d'),
            'datetime', 'timestamp' => $this->faker->dateTime()->format('Y-m-d H:i:s'),
            'time' => $this->faker->time('H:i:s'),
            'year' => $this->faker->year(),
            'json', 'jsonb' => json_encode(['key' => $this->faker->word(), 'value' => $this->faker->sentence()]),
            'binary', 'blob' => base64_encode($this->faker->text(50)),
            'uuid' => $this->faker->uuid(),
            'enum' => $this->faker->randomElement(['option1', 'option2', 'option3']),
            'set' => 'value1,value2',
            'geometry', 'point', 'linestring', 'polygon' => DB::raw("ST_GeomFromText('POINT({$this->faker->longitude()} {$this->faker->latitude()})')"),
            'geography' => DB::raw("ST_GeographyFromText('SRID=4326;POINT({$this->faker->longitude()} {$this->faker->latitude()})')"),
            'ipaddress', 'ip' => $this->faker->ipv4(),
            'macaddress', 'mac' => $this->faker->macAddress(),
            default => $this->faker->word(),
        };
    }

    /**
     * Verifica si un campo requiere valores únicos
     */
    protected function requiresUniqueness(string $column): bool
    {
        $columnLower = strtolower($column);

        foreach ($this->columnPatterns['contains'] as $keyword => $pattern) {
            if (Str::contains($columnLower, $keyword) && isset($pattern['unique']) && $pattern['unique']) {
                return true;
            }
        }

        return in_array($columnLower, ['email', 'username', 'slug']);
    }

    /**
     * Asegura que un valor sea único
     */
    protected function ensureUnique(string $table, string $column, $value, string $modelClass, $model): mixed
    {
        $attempts = 0;

        while (isset($this->uniqueValues[$table][$column][$value]) && $attempts < 10) {
            $value = $this->generateValueForColumn($modelClass, $column, $model);
            $attempts++;
        }

        $this->uniqueValues[$table][$column][$value] = true;

        return $value;
    }

    protected function generateForeignKey(string $modelClass, string $column, $model, array &$generatedForRelations)
    {
        $relationName = Str::camel(Str::beforeLast($column, '_id'));

        if (! method_exists($model, $relationName)) {
            $relatedTable = Str::plural(Str::beforeLast($column, '_id'));
            if (Schema::hasTable($relatedTable)) {
                $ids = DB::table($relatedTable)->pluck('id')->toArray();
                if (! empty($ids)) {
                    return $this->faker->randomElement($ids);
                }
            }

            return 1;
        }

        try {
            $relation = $model->$relationName();

            if (! ($relation instanceof \Illuminate\Database\Eloquent\Relations\Relation)) {
                return 1;
            }

            $relatedClass = get_class($relation->getRelated());

            if (isset($this->generatedIds[$relatedClass]) && ! empty($this->generatedIds[$relatedClass])) {
                return $this->faker->randomElement($this->generatedIds[$relatedClass]);
            }

            $relatedModel = new $relatedClass;
            $relatedTable = $relatedModel->getTable();

            if (Schema::hasTable($relatedTable)) {
                $relatedIds = DB::table($relatedTable)->pluck('id');

                // 🧩 Fix: en tests pluck() devuelve un array en lugar de una colección
                if (is_array($relatedIds)) {
                    $relatedIds = $relatedIds;
                } elseif (method_exists($relatedIds, 'toArray')) {
                    $relatedIds = $relatedIds->toArray();
                } else {
                    $relatedIds = (array) $relatedIds;
                }

                if (! empty($relatedIds)) {
                    $this->generatedIds[$relatedClass] = $relatedIds;
                }
            }


            if (! isset($generatedForRelations[$relatedClass])) {
                $this->info('  ↳ Generando registros para ' . class_basename($relatedClass) . ' (dependencia requerida)...');
                $this->insertRecords($relatedClass, 5);
                $generatedForRelations[$relatedClass] = true;

                if (isset($this->generatedIds[$relatedClass]) && ! empty($this->generatedIds[$relatedClass])) {
                    return $this->faker->randomElement($this->generatedIds[$relatedClass]);
                }
            }

            return null;
        } catch (\Exception $e) {
            return 1;
        }
    }

    protected function generatePivotsForModel(string $modelClass)
    {
        $model = new $modelClass;
        $reflection = new ReflectionClass($modelClass);
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            if ($method->class !== $modelClass || $method->getNumberOfParameters() > 0) {
                continue;
            }

            $skipMethods = [
                'getConnectionName',
                'getConnection',
                'getTable',
                'getKeyName',
                'getKey',
                'getRouteKey',
                'getRouteKeyName',
                'getFillable',
                'getGuarded',
                'getCasts',
                'getDates',
                'getHidden',
                'getVisible',
                'toArray',
                'toJson',
                'jsonSerialize',
                'fresh',
                'refresh',
                'getAttribute',
                'setAttribute',
                'boot',
                'booted',
                'bootIfNotBooted',
            ];

            if (in_array($method->name, $skipMethods)) {
                continue;
            }

            try {
                $relation = $method->invoke($model);

                if ($relation instanceof \Illuminate\Database\Eloquent\Relations\BelongsToMany) {
                    $this->generateBelongsToManyRecords($modelClass, $method->name, $relation);
                }
            } catch (\Throwable $e) {
                continue;
            }
        }
    }

    protected function generateBelongsToManyRecords(string $modelClass, string $relationName, $relation)
    {
        $pivotTable = $relation->getTable();
        $foreignPivotKey = $relation->getForeignPivotKeyName();
        $relatedPivotKey = $relation->getRelatedPivotKeyName();

        $relatedClass = get_class($relation->getRelated());

        $parentIds = $this->generatedIds[$modelClass]
            ?? DB::table((new $modelClass)->getTable())->pluck('id');

        $relatedIds = $this->generatedIds[$relatedClass] ?? [];

        if (empty($relatedIds)) {
            $relatedInstance = new $relatedClass;

            // 🧩 Fix: Si el mock no tiene getTable() (como en tests)
            $relatedTable = method_exists($relatedInstance, 'getTable')
                ? $relatedInstance->getTable()
                : 'mock_related_table';

            $relatedIds = DB::table($relatedTable)->pluck('id');
        }

        if (is_array($parentIds) === false && method_exists($parentIds, 'toArray')) {
            $parentIds = $parentIds->toArray();
        }
        if (is_array($relatedIds) === false && method_exists($relatedIds, 'toArray')) {
            $relatedIds = $relatedIds->toArray();
        }


        if (empty($parentIds) || empty($relatedIds)) {
            return;
        }

        $pivotRecords = [];
        $existingPairs = DB::table($pivotTable)
            ->get([$foreignPivotKey, $relatedPivotKey])
            ->map(fn($row) => "{$row->$foreignPivotKey}-{$row->$relatedPivotKey}")
            ->toArray();

        foreach ($parentIds as $parentId) {
            $relationsCount = $this->faker->numberBetween(1, min(5, count($relatedIds)));
            $selectedRelatedIds = $this->faker->randomElements($relatedIds, $relationsCount);

            foreach ($selectedRelatedIds as $relatedId) {
                $pairKey = "{$parentId}-{$relatedId}";

                if (! in_array($pairKey, $existingPairs)) {
                    $pivotRecords[] = [
                        $foreignPivotKey => $parentId,
                        $relatedPivotKey => $relatedId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    $existingPairs[] = $pairKey;
                }
            }
        }

        if (! empty($pivotRecords)) {
            DB::table($pivotTable)->insert($pivotRecords);
            $this->info('✅ Se generaron ' . count($pivotRecords) . " relaciones en {$pivotTable}");
        }
    }

    protected function generateOnlyPivots()
    {
        $this->info("🔗 Generando solo relaciones en tablas pivote...\n");

        $models = $this->getAllModels();

        foreach ($models as $modelClass) {
            $this->generatePivotsForModel($modelClass);
        }
    }

    protected function truncateModel(string $modelClass)
    {
        $model = new $modelClass;
        $table = $model->getTable();

        if (Schema::hasTable($table)) {
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            DB::table($table)->truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        }
    }

    protected function getModelClass(string $modelName): ?string
    {
        $modelClass = 'App\\Models\\' . $modelName;

        if (class_exists($modelClass)) {
            return $modelClass;
        }

        return null;
    }
}
