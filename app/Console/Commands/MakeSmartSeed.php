<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Faker\Factory as Faker;
use ReflectionClass;
use ReflectionMethod;

class MakeSmartSeed extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:smart-seed 
                            {model? : El modelo específico a generar (opcional)}
                            {--count=10 : Cantidad de registros a generar}
                            {--refresh : Truncar tablas antes de insertar}
                            {--only-pivots : Solo generar relaciones en tablas pivote}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Genera datos de prueba inteligentes con relaciones coherentes';

    protected $faker;
    protected $generatedIds = [];
    protected $processedModels = [];
    protected $modelsGraph = [];
    protected $uniqueValues = []; // Para rastrear valores únicos generados

    /**
     * Execute the console command.
     */
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

    /**
     * Genera datos para un modelo específico
     */
    protected function generateForModel(string $modelName, int $count)
    {
        $modelClass = $this->getModelClass($modelName);
        
        if (!$modelClass) {
            $this->error("❌ Modelo {$modelName} no encontrado");
            return;
        }

        if ($this->option('refresh')) {
            $this->truncateModel($modelClass);
        }

        $dependencies = $this->getModelDependencies($modelClass);
        
        foreach ($dependencies as $depClass) {
            if (!isset($this->processedModels[$depClass])) {
                $this->generateForModel(class_basename($depClass), $count);
            }
        }

        $this->insertRecords($modelClass, $count);
        $this->generatePivotsForModel($modelClass);
    }

    /**
     * Genera datos para todos los modelos
     */
    protected function generateForAllModels(int $count)
    {
        $models = $this->getAllModels();
        
        if (empty($models)) {
            $this->warn("⚠️  No se encontraron modelos en app/Models");
            return;
        }

        $sortedModels = $this->sortModelsByDependencies($models);

        if ($this->option('refresh')) {
            $this->info("🗑️  Limpiando tablas...");
            foreach (array_reverse($sortedModels) as $modelClass) {
                $this->truncateModel($modelClass);
            }
        }

        foreach ($sortedModels as $modelClass) {
            $this->insertRecords($modelClass, $count);
        }

        // Generar pivotes después de todos los modelos
        $this->info("\n🔗 Generando relaciones en tablas pivote...");
        foreach ($sortedModels as $modelClass) {
            $this->generatePivotsForModel($modelClass);
        }
    }

    /**
     * Obtiene todos los modelos de app/Models
     */
    protected function getAllModels(): array
    {
        $modelsPath = app_path('Models');
        $models = [];

        if (!File::isDirectory($modelsPath)) {
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
                if (!$reflection->isAbstract() && $reflection->isSubclassOf('Illuminate\Database\Eloquent\Model')) {
                    $models[] = $className;
                }
            }
        }

        return $models;
    }

    /**
     * Ordena modelos por dependencias
     */
    protected function sortModelsByDependencies(array $models): array
    {
        $graph = [];
        $sorted = [];
        $visited = [];

        foreach ($models as $model) {
            $graph[$model] = $this->getModelDependencies($model);
        }

        $visit = function ($model) use (&$visit, &$visited, &$sorted, $graph) {
            if (isset($visited[$model])) {
                return;
            }

            $visited[$model] = true;

            if (isset($graph[$model])) {
                foreach ($graph[$model] as $dependency) {
                    if (in_array($dependency, array_keys($graph))) {
                        $visit($dependency);
                    }
                }
            }

            $sorted[] = $model;
        };

        foreach ($models as $model) {
            $visit($model);
        }

        return $sorted;
    }

    /**
     * Obtiene dependencias de un modelo
     */
    protected function getModelDependencies(string $modelClass): array
    {
        $dependencies = [];
        $model = new $modelClass;
        $table = $model->getTable();

        if (!Schema::hasTable($table)) {
            return $dependencies;
        }

        $columns = Schema::getColumnListing($table);

        foreach ($columns as $column) {
            if (Str::endsWith($column, '_id') && !in_array($column, ['created_by', 'updated_by', 'deleted_by'])) {
                $relationName = Str::camel(Str::beforeLast($column, '_id'));
                
                if (method_exists($model, $relationName)) {
                    try {
                        $relation = $model->$relationName();
                        $relatedClass = get_class($relation->getRelated());
                        
                        if ($relatedClass !== $modelClass) {
                            $dependencies[] = $relatedClass;
                        }
                    } catch (\Exception $e) {
                        // Relación no válida
                    }
                }
            }
        }

        return array_unique($dependencies);
    }

    /**
     * Inserta registros para un modelo
     */
    protected function insertRecords(string $modelClass, int $count)
    {
        $model = new $modelClass;
        $table = $model->getTable();
        $modelName = class_basename($modelClass);

        if (!Schema::hasTable($table)) {
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
            // Insertar registro por registro para respetar restricciones UNIQUE
            $insertedIds = [];
            
            for ($i = 0; $i < $count; $i++) {
                $record = $this->generateRecord($modelClass, $fillableColumns);
                
                // Verificar si hay campos únicos con valores nulos
                $hasNullRequired = false;
                foreach ($record as $key => $value) {
                    if ($value === null && Str::endsWith($key, '_id')) {
                        $hasNullRequired = true;
                        break;
                    }
                }
                
                if (!$hasNullRequired) {
                    try {
                        $id = DB::table($table)->insertGetId($record);
                        $insertedIds[] = $id;
                    } catch (\Exception $e) {
                        // Si falla, intentar con valores únicos regenerados
                        if (Str::contains($e->getMessage(), ['Duplicate entry', 'UNIQUE'])) {
                            $this->warn("  ⚠️  Registro duplicado detectado, regenerando...");
                            continue;
                        } else {
                            throw $e;
                        }
                    }
                }
            }

            if (empty($insertedIds)) {
                // Si no se insertó ninguno, obtener IDs existentes
                $insertedIds = DB::table($table)->pluck('id')->toArray();
            }

            // Guardar IDs generados
            $this->generatedIds[$modelClass] = $insertedIds;
            $this->processedModels[$modelClass] = true;

            $actualCount = count($insertedIds);
            $this->info("✅ Se generaron {$actualCount} registros para {$modelName}");
            
        } catch (\Exception $e) {
            $this->error("❌ Error al insertar {$modelName}: " . $e->getMessage());
        }
    }

    /**
     * Obtiene columnas que se pueden llenar
     */
    protected function getFillableColumns($model, array $columns): array
    {
        $excluded = ['id', 'created_at', 'updated_at', 'deleted_at', 'remember_token'];
        
        return array_filter($columns, function ($column) use ($excluded, $model) {
            if (in_array($column, $excluded)) {
                return false;
            }

            // Si el modelo tiene $guarded, respetarlo
            if (property_exists($model, 'guarded') && !empty($model->guarded)) {
                return !in_array($column, $model->guarded);
            }

            return true;
        });
    }

    /**
     * Genera un registro con datos coherentes
     */
    protected function generateRecord(string $modelClass, array $columns): array
    {
        $record = [];
        $model = new $modelClass;
        $generatedForRelations = [];
        $table = $model->getTable();

        // Inicializar rastreador de valores únicos para esta tabla si no existe
        if (!isset($this->uniqueValues[$table])) {
            $this->uniqueValues[$table] = [];
        }

        // Primera pasada: generar valores no-relacionales
        foreach ($columns as $column) {
            if (!Str::endsWith($column, '_id')) {
                $value = $this->generateValueForColumn($modelClass, $column, $model);
                
                // Para campos que pueden ser únicos, asegurar unicidad
                if (in_array($column, ['email', 'username', 'slug'])) {
                    $attempts = 0;
                    while (isset($this->uniqueValues[$table][$column][$value]) && $attempts < 10) {
                        $value = $this->generateValueForColumn($modelClass, $column, $model);
                        $attempts++;
                    }
                    $this->uniqueValues[$table][$column][$value] = true;
                }
                
                $record[$column] = $value;
            }
        }

        // Segunda pasada: generar claves foráneas
        foreach ($columns as $column) {
            if (Str::endsWith($column, '_id')) {
                $foreignKey = $this->generateValueForColumn($modelClass, $column, $model);
                
                // Si es null y la columna es NOT NULL, intentar generar dependencia
                if ($foreignKey === null) {
                    $relationName = Str::camel(Str::beforeLast($column, '_id'));
                    if (method_exists($model, $relationName)) {
                        try {
                            $relation = $model->$relationName();
                            $relatedClass = get_class($relation->getRelated());
                            
                            // Generar dependencias si no existen
                            if (!isset($this->generatedIds[$relatedClass]) || empty($this->generatedIds[$relatedClass])) {
                                if (!isset($generatedForRelations[$relatedClass])) {
                                    $this->info("  ↳ Generando registros para " . class_basename($relatedClass) . " (dependencia requerida)...");
                                    $this->insertRecords($relatedClass, 5);
                                    $generatedForRelations[$relatedClass] = true;
                                }
                            }
                            
                            // Intentar obtener ID nuevamente
                            if (isset($this->generatedIds[$relatedClass]) && !empty($this->generatedIds[$relatedClass])) {
                                $foreignKey = $this->faker->randomElement($this->generatedIds[$relatedClass]);
                            }
                        } catch (\Exception $e) {
                            // No se pudo generar
                        }
                    }
                }
                
                $record[$column] = $foreignKey;
            }
        }

        // Agregar timestamps
        $record['created_at'] = now();
        $record['updated_at'] = now();

        return $record;
    }

    /**
     * Genera valor para una columna específica
     */
    protected function generateValueForColumn(string $modelClass, string $column, $model)
    {
        // Detectar relaciones (columnas _id)
        if (Str::endsWith($column, '_id')) {
            return $this->generateForeignKey($modelClass, $column, $model);
        }

        $columnType = Schema::getColumnType($model->getTable(), $column);

        // Generar por nombre de columna
        $value = $this->generateByColumnName($column);
        if ($value !== null) {
            return $value;
        }

        // Generar por tipo de dato
        return $this->generateByColumnType($columnType, $column);
    }

    /**
     * Genera valor basado en el nombre de la columna
     */
    protected function generateByColumnName(string $column)
    {
        $columnLower = strtolower($column);

        // Patrones específicos primero (más específicos a menos específicos)
        $patterns = [
            'email_verified_at' => fn() => $this->faker->dateTimeBetween('-1 year', 'now'),
            'firstname' => fn() => $this->faker->firstName(),
            'lastname' => fn() => $this->faker->lastName(),
            'first_name' => fn() => $this->faker->firstName(),
            'last_name' => fn() => $this->faker->lastName(),
            'birth_date' => fn() => $this->faker->date('Y-m-d', '-18 years'),
            'start_date' => fn() => $this->faker->date(),
            'end_date' => fn() => $this->faker->date(),
            'published_at' => fn() => $this->faker->dateTimeBetween('-1 year', 'now'),
            'verified_at' => fn() => $this->faker->dateTimeBetween('-1 year', 'now'),
            'email' => fn() => $this->faker->unique()->safeEmail(),
            'password' => fn() => bcrypt('password'),
            'username' => fn() => $this->faker->userName(),
            'phone' => fn() => $this->faker->phoneNumber(),
            'address' => fn() => $this->faker->address(),
            'city' => fn() => $this->faker->city(),
            'country' => fn() => $this->faker->country(),
            'postal_code' => fn() => $this->faker->postcode(),
            'zip_code' => fn() => $this->faker->postcode(),
            'title' => fn() => $this->faker->sentence(4),
            'slug' => fn() => $this->faker->slug(),
            'description' => fn() => $this->faker->paragraph(),
            'content' => fn() => $this->faker->paragraphs(3, true),
            'body' => fn() => $this->faker->paragraphs(5, true),
            'text' => fn() => $this->faker->paragraph(),
            'url' => fn() => $this->faker->url(),
            'website' => fn() => $this->faker->url(),
            'image' => fn() => $this->faker->imageUrl(640, 480),
            'avatar' => fn() => $this->faker->imageUrl(200, 200, 'people'),
            'photo' => fn() => $this->faker->imageUrl(),
            'price' => fn() => $this->faker->randomFloat(2, 10, 1000),
            'amount' => fn() => $this->faker->randomFloat(2, 0, 10000),
            'quantity' => fn() => $this->faker->numberBetween(1, 100),
            'stock' => fn() => $this->faker->numberBetween(0, 500),
            'status' => fn() => $this->faker->randomElement(['active', 'inactive', 'pending']),
            'type' => fn() => $this->faker->word(),
            'name' => fn() => $this->faker->name(),
            'token' => fn() => Str::random(60),
            'code' => fn() => strtoupper($this->faker->bothify('???-###')),
            'color' => fn() => $this->faker->hexColor(),
            'ip' => fn() => $this->faker->ipv4(),
            'latitude' => fn() => $this->faker->latitude(),
            'longitude' => fn() => $this->faker->longitude(),
        ];

        // Buscar coincidencia exacta primero
        if (isset($patterns[$columnLower])) {
            return $patterns[$columnLower]();
        }

        // Luego buscar patrones que contengan
        foreach ($patterns as $pattern => $generator) {
            if (Str::contains($columnLower, $pattern)) {
                return $generator();
            }
        }

        // Patrones booleanos
        if (Str::startsWith($columnLower, ['is_', 'has_', 'can_', 'should_'])) {
            return $this->faker->boolean();
        }

        // Patrones de fecha
        if (Str::endsWith($columnLower, ['_at', '_date'])) {
            return $this->faker->dateTimeBetween('-1 year', 'now');
        }

        return null;
    }

    /**
     * Genera valor basado en el tipo de columna
     */
    protected function generateByColumnType(string $type, string $column)
    {
        return match ($type) {
            'string', 'text', 'char', 'varchar' => $this->faker->sentence(),
            'integer', 'bigint', 'smallint', 'tinyint' => $this->faker->numberBetween(1, 100),
            'float', 'double', 'decimal' => $this->faker->randomFloat(2, 0, 1000),
            'boolean' => $this->faker->boolean(),
            'date' => $this->faker->date(),
            'datetime', 'timestamp' => $this->faker->dateTime(),
            'time' => $this->faker->time(),
            'json' => json_encode(['key' => $this->faker->word()]),
            default => $this->faker->word(),
        };
    }

    /**
     * Genera clave foránea válida
     */
    protected function generateForeignKey(string $modelClass, string $column, $model)
    {
        $relationName = Str::camel(Str::beforeLast($column, '_id'));

        if (!method_exists($model, $relationName)) {
            // Si no hay método de relación, intentar obtener IDs de la tabla relacionada
            $relatedTable = Str::plural(Str::beforeLast($column, '_id'));
            if (Schema::hasTable($relatedTable)) {
                $ids = DB::table($relatedTable)->pluck('id')->toArray();
                if (!empty($ids)) {
                    return $this->faker->randomElement($ids);
                }
            }
            
            // Si no encuentra nada, devolver 1 como fallback
            return 1;
        }

        try {
            $relation = $model->$relationName();
            
            // Verificar que sea una relación válida de Eloquent
            if (!($relation instanceof \Illuminate\Database\Eloquent\Relations\Relation)) {
                return 1;
            }
            
            $relatedClass = get_class($relation->getRelated());

            // Si ya tenemos IDs generados, usar uno
            if (isset($this->generatedIds[$relatedClass]) && !empty($this->generatedIds[$relatedClass])) {
                return $this->faker->randomElement($this->generatedIds[$relatedClass]);
            }

            // Si no, obtener de la base de datos
            $relatedModel = new $relatedClass;
            $relatedTable = $relatedModel->getTable();
            
            if (Schema::hasTable($relatedTable)) {
                $relatedIds = DB::table($relatedTable)->pluck('id')->toArray();

                if (!empty($relatedIds)) {
                    $this->generatedIds[$relatedClass] = $relatedIds;
                    return $this->faker->randomElement($relatedIds);
                }
            }

            // Si llegamos aquí y no hay registros, devolver null
            // El método que llama decidirá si necesita generar dependencias
            return null;
            
        } catch (\Exception $e) {
            return 1;
        }
    }

    /**
     * Genera relaciones para tablas pivote
     */
    protected function generatePivotsForModel(string $modelClass)
    {
        $model = new $modelClass;
        $reflection = new ReflectionClass($modelClass);
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            // Saltar métodos que no son del modelo actual o tienen parámetros
            if ($method->class !== $modelClass || $method->getNumberOfParameters() > 0) {
                continue;
            }

            // Saltar métodos conocidos que no son relaciones
            $skipMethods = [
                'getConnectionName', 'getConnection', 'getTable', 'getKeyName',
                'getKey', 'getRouteKey', 'getRouteKeyName', 'getFillable',
                'getGuarded', 'getCasts', 'getDates', 'getHidden', 'getVisible',
                'toArray', 'toJson', 'jsonSerialize', 'fresh', 'refresh',
                'getAttribute', 'setAttribute', 'boot', 'booted', 'bootIfNotBooted'
            ];

            if (in_array($method->name, $skipMethods)) {
                continue;
            }

            try {
                $relation = $method->invoke($model);

                // Verificar que sea realmente una relación BelongsToMany
                if ($relation instanceof \Illuminate\Database\Eloquent\Relations\BelongsToMany) {
                    $this->generateBelongsToManyRecords($modelClass, $method->name, $relation);
                }
            } catch (\Throwable $e) {
                // No es una relación válida o error al invocar, continuar
                continue;
            }
        }
    }

    /**
     * Genera registros para relación BelongsToMany
     */
    protected function generateBelongsToManyRecords(string $modelClass, string $relationName, $relation)
    {
        $pivotTable = $relation->getTable();
        $foreignPivotKey = $relation->getForeignPivotKeyName();
        $relatedPivotKey = $relation->getRelatedPivotKeyName();
        
        $relatedClass = get_class($relation->getRelated());

        $parentIds = $this->generatedIds[$modelClass] ?? DB::table((new $modelClass)->getTable())->pluck('id')->toArray();
        $relatedIds = $this->generatedIds[$relatedClass] ?? DB::table((new $relatedClass)->getTable())->pluck('id')->toArray();

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
                
                if (!in_array($pairKey, $existingPairs)) {
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

        if (!empty($pivotRecords)) {
            DB::table($pivotTable)->insert($pivotRecords);
            $this->info("✅ Se generaron " . count($pivotRecords) . " relaciones en {$pivotTable}");
        }
    }

    /**
     * Genera solo relaciones pivote
     */
    protected function generateOnlyPivots()
    {
        $this->info("🔗 Generando solo relaciones en tablas pivote...\n");
        
        $models = $this->getAllModels();
        
        foreach ($models as $modelClass) {
            $this->generatePivotsForModel($modelClass);
        }
    }

    /**
     * Trunca tabla de un modelo
     */
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

    /**
     * Obtiene la clase completa del modelo
     */
    protected function getModelClass(string $modelName): ?string
    {
        $modelClass = 'App\\Models\\' . $modelName;

        if (class_exists($modelClass)) {
            return $modelClass;
        }

        return null;
    }
}