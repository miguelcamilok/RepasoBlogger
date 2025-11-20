<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class AutoProduce extends Command
{
    protected $signature = 'autoproduce {--force : Sobrescribir archivos existentes}';
    protected $description = 'Automatiza la creación de modelos, relaciones, traits y rutas';

    private $modelsCreated = [];
    private $oneToOneRelations = [];

    public function handle()
    {
        $this->info('🚀 Iniciando AutoProduce...');
        $this->newLine();

        // Paso 1: Verificar y crear MakeSmartModels si no existe
        $this->step1_CheckMakeSmartModels();

        // Paso 2: Ejecutar MakeSmartModels
        $this->step2_ExecuteMakeSmartModels();

        // Paso 3: Detectar relaciones 1:1
        $this->step3_DetectOneToOneRelations();

        // Paso 4: Crear trait CrudBasic
        $this->step4_CreateCrudBasicTrait();

        // Paso 5: Inyectar trait en controladores
        $this->step5_InjectTraitInControllers();

        // Paso 6: Generar rutas automáticamente
        $this->step6_GenerateRoutes();

        $this->step7_CreateMakeSmartSeed();

        $this->step8_ExecuteMakeSmartSeed();

        // Resumen final
        $this->showFinalSummary();

        return Command::SUCCESS;
    }

    private function step1_CheckMakeSmartModels()
    {
        $commandPath = app_path('Console/Commands/MakeSmartModels.php');

        if (!File::exists($commandPath)) {
            $this->warn('⚠️  Comando MakeSmartModels no encontrado. Creando automáticamente...');

            if (!File::isDirectory(app_path('Console/Commands'))) {
                File::makeDirectory(app_path('Console/Commands'), 0755, true);
            }

            $stubContent = $this->getMakeSmartModelsStub();
            File::put($commandPath, $stubContent);

            $this->info("✅ MakeSmartModels creado automáticamente en: {$commandPath}");

            // 🔥 Recargar para que el comando exista enseguida
            Artisan::call('optimize:clear');
            shell_exec('composer dump-autoload');
        } else {
            $this->info('✅ Comando MakeSmartModels encontrado.');
        }
    }

    private function step2_ExecuteMakeSmartModels()
    {
        $this->info('🔧 Ejecutando MakeSmartModels...');

        $command = 'php artisan make:smart-models';
        $params = [];

        // Si existen modelos y no está el flag --force
        $modelsExist = $this->checkIfModelsExist();
        if ($modelsExist && !$this->option('force')) {
            if (!$this->confirm('Se detectaron modelos existentes. ¿Deseas sobrescribirlos?', false)) {
                $this->info('ℹ️  No se sobreescribieron los modelos.');
                return;
            }
            $params[] = '--overwrite';
        } else {
            if ($this->option('force')) {
                $params[] = '--overwrite';
            }
        }

        // Construir comando con parámetros
        if (!empty($params)) {
            $command .= ' ' . implode(' ', array_map(fn($p) => $p, $params));
        }

        // Ejecución en NUEVO proceso (clave para Laravel 12)
        $this->info("▶️ Ejecutando: $command");
        passthru($command);

        // Obtener lista de modelos creados
        $this->modelsCreated = $this->getCreatedModels();
        $this->info('✅ MakeSmartModels ejecutado. Modelos detectados: ' . count($this->modelsCreated));
    }



    private function step3_DetectOneToOneRelations()
    {
        $this->info('🔍 Detectando relaciones 1:1 en migraciones...');

        $migrationsPath = database_path('migrations');
        $migrations = File::glob($migrationsPath . '/*.php');

        foreach ($migrations as $migration) {
            $content = File::get($migration);

            // Buscar foreign keys con unique()
            if (preg_match_all('/\$table->foreignId\([\'"](\w+)[\'"]\).*?->unique\(\)/s', $content, $matches)) {
                foreach ($matches[1] as $foreignKey) {
                    // Extraer el nombre de la tabla del archivo de migración
                    if (preg_match('/create_(\w+)_table/', basename($migration), $tableMatch)) {
                        $tableName = $tableMatch[1];
                        $relatedTable = str_replace('_id', '', $foreignKey);

                        $this->oneToOneRelations[] = [
                            'table' => $tableName,
                            'foreign_key' => $foreignKey,
                            'related_table' => $relatedTable
                        ];
                    }
                }
            }
        }

        if (count($this->oneToOneRelations) > 0) {
            $this->info('✅ Se detectaron ' . count($this->oneToOneRelations) . ' relaciones 1:1:');
            foreach ($this->oneToOneRelations as $relation) {
                $this->line('   - ' . Str::studly(Str::singular($relation['table'])) . ' -> ' . Str::studly(Str::singular($relation['related_table'])));
            }
        } else {
            $this->info('ℹ️  No se detectaron relaciones 1:1.');
        }
    }

    private string $selectedTrait = '';

    private function step4_CreateCrudBasicTrait()
    {
        $this->info('📦 Creando trait Crud...');
        $opcion = $this->choice('¿Que trait quieres crear?', ['CrudBasic', 'CrudAdvanced']);
        $this->selectedTrait = $opcion;

        switch ($opcion) {
            case 'CrudBasic':
                $traitPath = app_path('Traits/CrudBasic.php');
                $traitStub = $this->getCrudBasicTraitStub();
                break;
            case 'CrudAdvanced':
                $traitPath = app_path('Traits/CrudAdvanced.php');
                $traitStub = $this->getCrudAdvancedTraitStub();
                break;
        }

        if (File::exists($traitPath) && !$this->option('force')) {
            $this->warn('⚠️  El trait ' . $opcion . ' ya existe en: ' . $traitPath);
            return;
        }

        // Crear directorio Traits si no existe
        if (!File::exists(app_path('Traits'))) {
            File::makeDirectory(app_path('Traits'), 0755, true);
        }

        File::put($traitPath, $traitStub);

        $this->info("✅ Trait $opcion creado en: app/Traits/$opcion.php");
        $this->warn('⚠️  IMPORTANTE: Debes implementar los métodos del CRUD en el trait manualmente.');
    }

    private function step5_InjectTraitInControllers()
    {
        $this->info('💉 Inyectando trait Crud en controladores...');
        $traitName = $this->selectedTrait;
        $traitNamespace = "App\\Traits\\$traitName";

        $controllers = File::glob(app_path('Http/Controllers/*Controller.php'));
        $injectedCount = 0;

        foreach ($controllers as $controllerPath) {
            // Saltar Controller.php base
            if (basename($controllerPath) === 'Controller.php') {
                continue;
            }

            $content = File::get($controllerPath);

            // Verificar si ya tiene el trait
            if (strpos($content, "use $traitName;") !== false) {
                continue;
            }

            // Verificar si ya tiene el use statement
            $hasUseStatement = strpos($content, "use $traitNamespace;") !== false;

            // Agregar use statement si no existe
            if (!$hasUseStatement) {
                $content = preg_replace(
                    '/(namespace\s+App\\\\Http\\\\Controllers;)/',
                    "$1\n\nuse App\\Traits\\$traitName;",
                    $content,
                    1
                );
            }

            // Agregar use trait dentro de la clase
            $content = preg_replace(
                '/(class\s+\w+\s+extends\s+Controller\s*\{)/',
                "$1\n    use $traitName;\n",
                $content,
                1
            );


            File::put($controllerPath, $content);
            $injectedCount++;

            $this->line('   ✓ ' . basename($controllerPath));
        }

        $this->info("✅ Trait inyectado en $injectedCount controladores.");
    }

    private function step6_GenerateRoutes()
    {
        $this->info('🛣️  Generando rutas automáticas...');

        $routesPath = base_path('routes/api.php');

        if (!File::exists($routesPath)) {
            $this->warn('⚠️  No se encontró routes/api.php, usando routes/web.php');
            $routesPath = base_path('routes/web.php');
        }

        $routesContent = File::get($routesPath);
        $useStatements = "\n// Use statements generados por AutoProduce\n";
        $routeStatements = "\n// Rutas generadas por AutoProduce\n";

        foreach ($this->modelsCreated as $model) {
            $resourceName = Str::plural(Str::snake(class_basename($model)));
            $controllerName = class_basename($model) . 'Controller';
            $fullController = "App\\Http\\Controllers\\$controllerName";

            // Verificar si la ruta ya existe
            if (strpos($routesContent, "Route::apiResource('$resourceName'") !== false) {
                continue;
            }

            // Agregar use solo si no existe ya
            if (strpos($routesContent, "use $fullController;") === false) {
                $useStatements .= "use $fullController;\n";
            }

            // Agregar la ruta
            $routeStatements .= "Route::apiResource('$resourceName', $controllerName::class);\n";
        }

        // Agregar al archivo
        $newContent = $useStatements . $routeStatements;

        if (trim($newContent) !== "// Use statements generados por AutoProduce\n// Rutas generadas por AutoProduce") {
            File::append($routesPath, $newContent);
            $this->info('✅ Rutas agregadas a: ' . $routesPath);
        } else {
            $this->info('ℹ️  No se agregaron nuevas rutas (ya existen).');
        }
    }

    private function step7_CreateMakeSmartSeed()
    {
        $this->info('📦 Creando comando MakeSmartSeed...');

        $commandPath = app_path('Console/Commands/MakeSmartSeed.php');

        if (File::exists($commandPath) && !$this->option('force')) {
            $this->warn('⚠️  El comando MakeSmartSeed ya existe en: ' . $commandPath);
            return;
        }

        // Crear directorio si no existe
        if (!File::isDirectory(app_path('Console/Commands'))) {
            File::makeDirectory(app_path('Console/Commands'), 0755, true);
        }

        // Escribir stub
        File::put($commandPath, $this->getMakeSmartSeedStub());

        $this->info("✅ MakeSmartSeed creado en: {$commandPath}");
        $this->warn('⚠️  IMPORTANTE: Debes implementar los métodos del seeder manualmente.');

        // Recargar para que el comando exista enseguida
        Artisan::call('optimize:clear');
        shell_exec('composer dump-autoload');
    }


    private function step8_ExecuteMakeSmartSeed()
    {
        $this->info('🔧 Ejecutando MakeSmartSeed...');

        if (!$this->confirm('¿Deseas ejecutar los datos de prueba?', false)) {
            $this->info('ℹ️  No se ejecutaron los datos de prueba.');
            $this->warn('⚠️  Recuerda ejecutar MakeSmartSeed manualmente cuando lo necesites.');
            return;
        }

        // Llamar en nuevo proceso para que Laravel registre el comando correctamente
        $this->info('▶️ Ejecutando: php artisan make:smart-seed');
        passthru('php artisan make:smart-seed');

        $this->info('✅ MakeSmartSeed ejecutado.');
    }



    private function showFinalSummary()
    {
        $this->newLine();
        $this->info('═══════════════════════════════════════════════════════');
        $this->info('🎉 AUTOPRODUCE COMPLETADO');
        $this->info('═══════════════════════════════════════════════════════');
        $this->newLine();

        $this->line('📊 RESUMEN:');
        $this->line('   • Modelos generados: ' . count($this->modelsCreated));
        $this->line('   • Relaciones 1:1 detectadas: ' . count($this->oneToOneRelations));
        $this->line('   • Trait CrudBasic: app/Traits/CrudBasic.php');
        $this->line('   • Rutas generadas en: routes/api.php (o web.php)');
        $this->line('   • Comando MakeSmartSeed: app/Console/Commands/MakeSmartSeed.php');
        $this->newLine();

        if (count($this->modelsCreated) > 0) {
            $this->info('📦 MODELOS CREADOS:');
            foreach ($this->modelsCreated as $model) {
                $this->line('   ✓ ' . class_basename($model));
            }
            $this->newLine();
        }

        if (count($this->oneToOneRelations) > 0) {
            $this->info('🔗 RELACIONES 1:1 DETECTADAS:');
            foreach ($this->oneToOneRelations as $relation) {
                $this->line('   ✓ ' . Str::studly(Str::singular($relation['table'])) . ' -> ' . Str::studly(Str::singular($relation['related_table'])));
            }
            $this->newLine();
        }

        $this->warn('⚠️  WARNINGS:');
        $this->line('   1. Verificar que app/Traits/CrudBasic.php tenga el código completo');
        $this->line('   2. Verificar que MakeSmartModels.php tenga el código completo');
        $this->line('   3. Revisar las rutas generadas en routes/api.php');
        $this->line('   2. Verificar que MakeSmartSeed.php tenga el código completo');
        $this->newLine();

        $this->info('✨ ¡Todo listo! Tu API está estructurada y lista para desarrollo.');
    }

    // Métodos auxiliares

    private function checkIfModelsExist()
    {
        $models = File::glob(app_path('Models/*.php'));
        return count($models) > 2; // Más de 2 porque User.php siempre existe
    }

    private function getCreatedModels()
    {
        $models = [];
        $modelFiles = File::glob(app_path('Models/*.php'));

        foreach ($modelFiles as $file) {
            $className = 'App\\Models\\' . basename($file, '.php');
            if (class_exists($className) && basename($file) !== 'User.php') {
                $models[] = $className;
            }
        }

        return $models;
    }

    private function getMakeSmartModelsStub()
    {
        return <<<'EOO'
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MakeSmartModels extends Command
{
    protected $signature = 'make:smart-models 
                            {--overwrite : Overwrite existing models} 
                            {--debug : Show debug information}
                            {--detect-has-one : Attempt to detect hasOne relationships from unique indexes}';
    protected $description = 'Genera automáticamente los modelos con fillables y relaciones a partir de las migraciones';

    private array $tables = [];
    private array $models = [];
    private array $pivotTables = [];
    
    // Tablas internas de Laravel que no deben generar modelos
    private array $excludedTables = [
        'migrations',
        'password_reset_tokens',
        'password_resets',
        'failed_jobs',
        'personal_access_tokens',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'sessions',
    ];

    public function handle(): int
    {
        $this->info('🚀 Analizando migraciones...');

        // Paso 1: Analizar todas las migraciones
        $this->parseMigrations();

        if (empty($this->tables)) {
            $this->error('No se encontraron tablas en las migraciones.');
            return Command::FAILURE;
        }

        $this->info("📊 Se encontraron " . count($this->tables) . " tablas (excluyendo tablas internas de Laravel)");
        
        if ($this->option('debug')) {
            $this->showDebugInfo();
        }

        // Paso 2: Identificar tablas pivot
        $this->identifyPivotTables();
        
        if ($this->option('debug') && !empty($this->pivotTables)) {
            $this->warn("Tablas pivot (no se generarán modelos): " . implode(', ', $this->pivotTables));
        }

        // Paso 3: Detectar relaciones
        $this->detectRelationships();

        // Paso 4: Generar modelos
        $this->generateModels();

        $this->newLine();
        $this->info('✅ Modelos generados exitosamente en app/Models/');
        
        return Command::SUCCESS;
    }

    private function parseMigrations(): void
    {
        $migrationsPath = database_path('migrations');
        $files = File::glob($migrationsPath . '/*.php');
        sort($files); // Orden cronológico

        foreach ($files as $file) {
            $content = File::get($file);
            $this->parseMigrationFile($content, basename($file));
        }
    }

    private function parseMigrationFile(string $content, string $filename): void
    {
        // Detectar Schema::create
        preg_match_all('/Schema::create\s*\(\s*[\'"](\w+)[\'"]\s*,\s*function/i', $content, $creates);
        
        foreach ($creates[1] as $tableName) {
            // Excluir tablas internas de Laravel
            if (in_array($tableName, $this->excludedTables)) {
                continue;
            }
            
            if (!isset($this->tables[$tableName])) {
                $this->tables[$tableName] = [
                    'columns' => [],
                    'foreign_keys' => [],
                    'indexes' => [],
                ];
            }

            // Extraer el bloque de la tabla
            preg_match('/Schema::create\s*\(\s*[\'"]' . preg_quote($tableName) . '[\'"]\s*,\s*function\s*\([^)]*\)\s*{([^}]+(?:{[^}]*}[^}]*)*)/s', $content, $tableBlock);
            
            if (isset($tableBlock[1])) {
                $this->parseTableSchema($tableName, $tableBlock[1]);
            }
        }
    }

    private function parseTableSchema(string $tableName, string $schema): void
    {
        // Detectar columnas
        preg_match_all('/\$table->(\w+)\s*\(\s*[\'"](\w+)[\'"]/', $schema, $columns);
        
        for ($i = 0; $i < count($columns[1]); $i++) {
            $type = $columns[1][$i];
            $name = $columns[2][$i];
            
            // Ignorar solo timestamps y tokens, NO el id primario ni otros ids
            if (in_array($name, ['created_at', 'updated_at', 'deleted_at', 'remember_token'])) {
                continue;
            }
            
            // Ignorar solo el id si es autoincremental (id, bigIncrements, increments)
            if ($name === 'id' && in_array($type, ['id', 'bigIncrements', 'increments'])) {
                continue;
            }

            $this->tables[$tableName]['columns'][$name] = [
                'type' => $type,
                'nullable' => $this->isNullable($schema, $name),
                'unique' => $this->isUnique($schema, $name),
            ];
        }

        // Detectar foreign keys explícitas
        preg_match_all('/\$table->foreign\s*\(\s*[\'"](\w+)[\'"]\s*\)->references\s*\(\s*[\'"](\w+)[\'"]\s*\)->on\s*\(\s*[\'"](\w+)[\'"]/', $schema, $foreignKeys);
        
        for ($i = 0; $i < count($foreignKeys[1]); $i++) {
            $column = $foreignKeys[1][$i];
            $referencedColumn = $foreignKeys[2][$i];
            $referencedTable = $foreignKeys[3][$i];
            
            $this->tables[$tableName]['foreign_keys'][$column] = [
                'references' => $referencedColumn,
                'on' => $referencedTable,
            ];
        }

        // Detectar foreign keys por convención (columnas terminadas en _id)
        foreach ($this->tables[$tableName]['columns'] as $columnName => $columnData) {
            if (Str::endsWith($columnName, '_id') && !isset($this->tables[$tableName]['foreign_keys'][$columnName])) {
                // Extraer el nombre base sin _id
                $baseName = Str::substr($columnName, 0, -3);
                
                // Inferir la tabla referenciada - intentar varias estrategias
                $referencedTable = $this->inferReferencedTable($baseName, $tableName);
                
                if ($referencedTable) {
                    $this->tables[$tableName]['foreign_keys'][$columnName] = [
                        'references' => 'id',
                        'on' => $referencedTable,
                        'inferred' => true,
                    ];
                }
            }
        }
    }

    private function isUnique(string $schema, string $columnName): bool
    {
        // Buscar si la columna tiene ->unique()
        return (bool) preg_match('/[\'"]' . preg_quote($columnName) . '[\'"][^;]*->unique\s*\(/', $schema);
    }

    private function isNullable(string $schema, string $columnName): bool
    {
        // Buscar si la columna tiene ->nullable()
        return (bool) preg_match('/[\'"]' . preg_quote($columnName) . '[\'"][^;]*->nullable\s*\(/', $schema);
    }

    private function identifyPivotTables(): void
    {
        foreach ($this->tables as $tableName => $tableData) {
            $foreignKeys = $tableData['foreign_keys'];
            $columns = $tableData['columns'];

            // Criterios para identificar una tabla pivot:
            // 1. Exactamente 2 foreign keys
            // 2. Nombre de tabla sigue convención pivot (tabla1_tabla2 o tabla2_tabla1)
            // 3. Máximo 4 columnas (las 2 FKs + timestamps opcionales)
            // 4. Las tablas referenciadas son diferentes
            
            if (count($foreignKeys) === 2) {
                $fkTables = array_column($foreignKeys, 'on');
                
                // Verificar que no sea la misma tabla dos veces (auto-referencia)
                if ($fkTables[0] === $fkTables[1]) {
                    continue;
                }
                
                // Verificar convención de nombres pivot
                $isPivotByName = $this->isPivotTableByName($tableName, $fkTables);
                
                // Verificar cantidad de columnas (pivot típicamente tiene pocas columnas)
                $hasOnlyPivotColumns = count($columns) <= 4;
                
                // Es pivot si cumple ambas condiciones O si el nombre es claramente pivot
                if ($isPivotByName && $hasOnlyPivotColumns) {
                    $this->pivotTables[] = $tableName;
                    
                    if ($this->option('debug')) {
                        $this->info("🔗 Tabla pivot detectada: {$tableName} (conecta {$fkTables[0]} y {$fkTables[1]})");
                    }
                } else if ($this->option('debug')) {
                    $reason = !$isPivotByName ? 'nombre no sigue convención pivot' : 'tiene más de 4 columnas';
                    $this->line("   ℹ️  {$tableName} tiene 2 FKs pero NO es pivot ({$reason})");
                }
            }
        }
    }
    
    /**
     * Verifica si el nombre de la tabla sigue la convención pivot
     * Ej: post_tag, tag_post, product_user, etc.
     */
    private function isPivotTableByName(string $tableName, array $referencedTables): bool
    {
        // Si la tabla no tiene underscore, probablemente no es pivot
        if (!Str::contains($tableName, '_')) {
            return false;
        }
        
        // Obtener nombres singulares de las tablas referenciadas
        $singular1 = Str::singular($referencedTables[0]);
        $singular2 = Str::singular($referencedTables[1]);
        
        // Verificar ambas combinaciones posibles
        $combo1 = $singular1 . '_' . $singular2;
        $combo2 = $singular2 . '_' . $singular1;
        
        // También verificar en plural (menos común pero posible)
        $combo3 = $referencedTables[0] . '_' . $referencedTables[1];
        $combo4 = $referencedTables[1] . '_' . $referencedTables[0];
        
        return $tableName === $combo1 || 
               $tableName === $combo2 ||
               $tableName === $combo3 ||
               $tableName === $combo4;
    }

    private function detectRelationships(): void
    {
        foreach ($this->tables as $tableName => $tableData) {
            // Ignorar tablas pivot para la generación de modelos
            if (in_array($tableName, $this->pivotTables)) {
                continue;
            }

            $modelName = $this->getModelName($tableName);
            
            $this->models[$modelName] = [
                'table' => $tableName,
                'fillable' => array_keys($tableData['columns']),
                'belongsTo' => [],
                'hasMany' => [],
                'hasOne' => [],
                'belongsToMany' => [],
            ];

            // Detectar belongsTo (esta tabla tiene foreign keys)
            $relationNames = []; // Para evitar duplicados
            foreach ($tableData['foreign_keys'] as $column => $fkData) {
                $relatedTable = $fkData['on'];
                $relatedModel = $this->getModelName($relatedTable);
                
                // Generar nombre de relación único
                $relationName = $this->generateUniqueRelationName($column, $relatedModel, $relationNames);
                $relationNames[] = $relationName;
                
                $this->models[$modelName]['belongsTo'][] = [
                    'name' => $relationName,
                    'model' => $relatedModel,
                    'foreign_key' => $column,
                ];

                // NO remover la foreign key del fillable - puede ser necesaria
            }

            // Detectar hasMany/hasOne (otras tablas tienen foreign keys a esta)
            $hasRelationNames = []; // Para evitar duplicados en hasMany
            foreach ($this->tables as $otherTableName => $otherTableData) {
                if ($otherTableName === $tableName || in_array($otherTableName, $this->pivotTables)) {
                    continue;
                }

                foreach ($otherTableData['foreign_keys'] as $column => $fkData) {
                    if ($fkData['on'] === $tableName) {
                        $relatedModel = $this->getModelName($otherTableName);
                        
                        // Generar nombre base de la relación (nombre de la tabla en plural)
                        $baseRelationName = Str::camel($otherTableName);
                        
                        // Si ya existe una relación con este nombre, NO duplicarla
                        // Una tabla solo debe tener UNA relación hasMany/hasOne por tabla relacionada
                        // sin importar cuántas FKs tenga
                        if (in_array($baseRelationName, $hasRelationNames)) {
                            if ($this->option('debug')) {
                                $this->line("   ⚠️  Evitando duplicado: {$modelName}->hasMany({$relatedModel}) ya existe");
                            }
                            continue;
                        }
                        
                        $hasRelationNames[] = $baseRelationName;
                        
                        // Detectar si es hasOne (FK es única)
                        $isHasOne = false;
                        if ($this->option('detect-has-one') && isset($otherTableData['columns'][$column])) {
                            $isHasOne = $otherTableData['columns'][$column]['unique'] ?? false;
                        }
                        
                        if ($isHasOne) {
                            // Convertir a singular para hasOne
                            $singularRelationName = Str::camel(Str::singular($otherTableName));
                            $this->models[$modelName]['hasOne'][] = [
                                'name' => $singularRelationName,
                                'model' => $relatedModel,
                                'foreign_key' => $column,
                            ];
                            
                            if ($this->option('debug')) {
                                $this->info("   🔗 Detectada relación hasOne: {$modelName}->hasOne({$relatedModel}) [FK única]");
                            }
                        } else {
                            // Por defecto hasMany
                            $this->models[$modelName]['hasMany'][] = [
                                'name' => $baseRelationName,
                                'model' => $relatedModel,
                                'foreign_key' => $column,
                            ];
                        }
                    }
                }
            }

            // Detectar belongsToMany (a través de tablas pivot)
            $manyToManyNames = []; // Para evitar duplicados
            foreach ($this->pivotTables as $pivotTable) {
                $pivotForeignKeys = $this->tables[$pivotTable]['foreign_keys'];
                $fkTables = array_column($pivotForeignKeys, 'on');

                if (in_array($tableName, $fkTables)) {
                    $otherTable = $fkTables[0] === $tableName ? $fkTables[1] : $fkTables[0];
                    $otherModel = $this->getModelName($otherTable);
                    $relationName = Str::camel($otherTable);
                    
                    // Evitar duplicados
                    if (!in_array($relationName, $manyToManyNames)) {
                        $manyToManyNames[] = $relationName;
                        
                        $this->models[$modelName]['belongsToMany'][] = [
                            'name' => $relationName,
                            'model' => $otherModel,
                            'pivot_table' => $pivotTable,
                        ];
                    }
                }
            }
        }
    }

    private function generateModels(): void
    {
        $modelsPath = app_path('Models');
        
        if (!File::isDirectory($modelsPath)) {
            File::makeDirectory($modelsPath, 0755, true);
        }

        foreach ($this->models as $modelName => $modelData) {
            $filename = $modelsPath . '/' . $modelName . '.php';

            if (File::exists($filename) && !$this->option('overwrite')) {
                $this->warn("⚠️  El modelo {$modelName} ya existe. Usa --overwrite para sobrescribir.");
                continue;
            }

            $content = $this->generateModelContent($modelName, $modelData);
            File::put($filename, $content);
            
            $this->info("✓ Generado: {$modelName}.php");
        }
    }

    private function generateModelContent(string $modelName, array $data): string
    {
        $uses = ['Illuminate\Database\Eloquent\Factories\HasFactory', 'Illuminate\Database\Eloquent\Model'];
        $relations = [];

        // BelongsTo relations
        foreach ($data['belongsTo'] as $relation) {
            $uses[] = "App\\Models\\{$relation['model']}";
            $relations[] = $this->generateBelongsTo($relation);
        }

        // HasMany relations
        foreach ($data['hasMany'] as $relation) {
            $uses[] = "App\\Models\\{$relation['model']}";
            $relations[] = $this->generateHasMany($relation);
        }

        // HasOne relations
        foreach ($data['hasOne'] as $relation) {
            $uses[] = "App\\Models\\{$relation['model']}";
            $relations[] = $this->generateHasOne($relation);
        }

        // BelongsToMany relations
        foreach ($data['belongsToMany'] as $relation) {
            $uses[] = "App\\Models\\{$relation['model']}";
            $relations[] = $this->generateBelongsToMany($relation);
        }

        $uses = array_unique($uses);
        sort($uses);

        $usesStr = implode("\n", array_map(fn($use) => "use {$use};", $uses));
        $fillableStr = $this->formatFillable($data['fillable']);
        $relationsStr = implode("\n\n", $relations);

        return <<<PHP
<?php

namespace App\Models;

{$usesStr}

class {$modelName} extends Model
{
    use HasFactory;

    protected \$table = '{$data['table']}';

    protected \$fillable = [
{$fillableStr}
    ];
{$relationsStr}
}

PHP;
    }

    private function formatFillable(array $columns): string
    {
        if (empty($columns)) {
            return '';
        }

        return '        ' . implode(",\n        ", array_map(fn($col) => "'{$col}'", $columns));
    }

    private function generateBelongsTo(array $relation): string
    {
        return <<<PHP

    /**
     * Relación belongsTo con {$relation['model']}
     * Foreign key: {$relation['foreign_key']}
     */
    public function {$relation['name']}()
    {
        return \$this->belongsTo({$relation['model']}::class, '{$relation['foreign_key']}');
    }
PHP;
    }

    private function generateHasMany(array $relation): string
    {
        return <<<PHP

    /**
     * Relación hasMany con {$relation['model']}
     * Foreign key: {$relation['foreign_key']}
     */
    public function {$relation['name']}()
    {
        return \$this->hasMany({$relation['model']}::class, '{$relation['foreign_key']}');
    }
PHP;
    }

    private function generateHasOne(array $relation): string
    {
        return <<<PHP

    /**
     * Relación hasOne con {$relation['model']}
     * Foreign key: {$relation['foreign_key']}
     */
    public function {$relation['name']}()
    {
        return \$this->hasOne({$relation['model']}::class, '{$relation['foreign_key']}');
    }
PHP;
    }

    private function generateBelongsToMany(array $relation): string
    {
        return <<<PHP

    /**
     * Relación belongsToMany con {$relation['model']}
     * Tabla pivot: {$relation['pivot_table']}
     */
    public function {$relation['name']}()
    {
        return \$this->belongsToMany({$relation['model']}::class, '{$relation['pivot_table']}');
    }
PHP;
    }

    private function getModelName(string $tableName): string
    {
        // Singularizar primero
        $singular = Str::singular($tableName);
        
        // Detectar si es una sigla/acrónimo (todas mayúsculas o muy corta después de singularizar)
        // Si la tabla es 'eps' y se singulariza a 'ep', y la tabla original no cambia mucho,
        // es probable que sea una sigla
        
        // Caso 1: Si quedó de 2-3 caracteres y la tabla original también era corta, es sigla
        if (strlen($singular) <= 3 && strlen($tableName) <= 4) {
            // Usar el nombre de la tabla directamente (sin singularizar)
            return Str::studly($tableName);
        }
        
        // Caso 2: Si la singularización solo quitó una 's', verificar si es sigla
        if ($singular . 's' === $tableName && strlen($singular) <= 3) {
            // Es una sigla plural (eps, ips, etc), usar el nombre completo
            return Str::studly($tableName);
        }
        
        // Caso normal: usar singular
        return Str::studly($singular);
    }

    /**
     * Genera un nombre de relación único evitando duplicados
     * Maneja casos como sender_profile_id y receiver_profile_id
     */
    private function generateUniqueRelationName(string $column, string $relatedModel, array $existingNames): string
    {
        // Remover el sufijo _id
        $baseName = str_replace('_id', '', $column);
        
        // Convertir a camelCase
        $relationName = Str::camel($baseName);
        
        // Si no existe duplicado, retornar directamente
        if (!in_array($relationName, $existingNames)) {
            return $relationName;
        }
        
        // Si ya existe, significa que hay múltiples FK al mismo modelo
        // Usar el nombre completo de la columna sin el _id
        // Ejemplo: sender_profile_id -> senderProfile
        return Str::camel($baseName);
    }

    /**
     * Infiere la tabla referenciada desde el nombre de columna
     * Maneja casos como: user_id, OrderDetail_order_id, etc.
     */
    private function inferReferencedTable(string $baseName, string $currentTable): ?string
    {
        // Caso 1: Nombre simple (user_id -> users)
        $simplePlural = Str::plural(Str::snake($baseName));
        if (isset($this->tables[$simplePlural])) {
            return $simplePlural;
        }
        
        // Caso 2: Nombre compuesto con underscore (OrderDetail_order_id -> orders)
        // Tomar solo la última parte después del último underscore
        if (Str::contains($baseName, '_')) {
            $parts = explode('_', $baseName);
            $lastPart = array_pop($parts);
            $lastPartPlural = Str::plural(Str::snake($lastPart));
            
            if (isset($this->tables[$lastPartPlural])) {
                return $lastPartPlural;
            }
            
            // Si no existe, intentar con toda la parte antes del último underscore
            $firstPart = implode('_', $parts);
            $firstPartPlural = Str::plural(Str::snake($firstPart));
            
            if (isset($this->tables[$firstPartPlural])) {
                return $firstPartPlural;
            }
        }
        
        // Caso 3: Buscar en snake_case
        $snakePlural = Str::plural(Str::snake($baseName));
        if (isset($this->tables[$snakePlural])) {
            return $snakePlural;
        }
        
        // Caso 4: Singular directo
        $singular = Str::singular(Str::snake($baseName));
        if (isset($this->tables[$singular])) {
            return $singular;
        }
        
        // No se pudo inferir con certeza
        return null;
    }

    /**
     * Muestra información de debug sobre las tablas detectadas
     */
    private function showDebugInfo(): void
    {
        $this->newLine();
        $this->warn('=== INFORMACIÓN DE DEBUG ===');
        $this->newLine();
        
        $this->info('Tablas detectadas:');
        foreach ($this->tables as $tableName => $tableData) {
            $columnCount = count($tableData['columns']);
            $fkCount = count($tableData['foreign_keys']);
            $this->line("  • {$tableName} ({$columnCount} columnas, {$fkCount} FKs)");
        }
        
        $this->newLine();
    }
}
EOO;
    }

    private function getCrudBasicTraitStub()
    {
        return <<<'PHP'
<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

trait CrudBasic
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        // TODO: Implementar listado
        $model = $this->getModelClass();
        $data = $model::all();
        
        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        // TODO: Implementar validación y creación
        $model = $this->getModelClass();
        $data = $model::create($request->all());
        
        return response()->json([
            'success' => true,
            'data' => $data
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id): JsonResponse
    {
        // TODO: Implementar búsqueda
        $model = $this->getModelClass();
        $data = $model::findOrFail($id);
        
        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        // TODO: Implementar validación y actualización
        $model = $this->getModelClass();
        $data = $model::findOrFail($id);
        $data->update($request->all());
        
        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id): JsonResponse
    {
        // TODO: Implementar eliminación
        $model = $this->getModelClass();
        $data = $model::findOrFail($id);
        $data->delete();
        
        return response()->json([
            'success' => true,
            'message' => 'Recurso eliminado correctamente'
        ]);
    }

    /**
     * Get the model class for the controller.
     */
    private function getModelClass()
    {
        $controllerName = class_basename($this);
        $modelName = str_replace('Controller', '', $controllerName);
        
        return "App\\Models\\{$modelName}";
    }
}
PHP;
    }
    private function getCrudAdvancedTraitStub()
    {
        return <<<'PHP'
<?php

namespace App\Traits;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache, DB, Log, Schema, Validator};
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Throwable;

/**
 * Trait CrudTrait
 *
 * Proporciona operaciones CRUD completas para controladores de Laravel con:
 * - Detección automática de modelos
 * - Integración opcional con HasSmartScopes
 * - Soporte para relaciones many-to-many
 * - Validación automática de campos
 * - Manejo consistente de errores
 * - Transacciones automáticas
 *
 * @property object|null $service Servicio opcional para lógica de negocio
 * @property string|null $storeRequest FormRequest para validación en store
 * @property string|null $updateRequest FormRequest para validación en update
 * @property string|null $resourceName Nombre del recurso (detectado automáticamente)
 * @property bool $applySmartScopes Habilitar scopes automáticos en index
 */
trait CrudAdvanced
{
    // ==================== CONFIGURACIÓN ====================

    private const CACHE_DURATION_RELATIONS = 3600;
    private const CACHE_DURATION_COLUMNS = 3600;

    private const EXCLUDED_SYSTEM_FIELDS = [
        'id',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    private const EXCLUDED_RELATION_METHODS = [
        'notifications',
        'toArray',
        'toJson',
        'save',
        'delete',
        'update',
        'fill',
        'fresh',
        'load',
        'refresh',
    ];

    protected ?object $service = null;
    protected ?string $storeRequest = null;
    protected ?string $updateRequest = null;
    protected ?string $resourceName = null;
    protected ?string $modelClass = null;
    protected bool $applySmartScopes = true;

    // ==================== CONFIGURACIÓN PÚBLICA ====================

    /**
     * Configura el trait con opciones personalizadas.
     */
    public function configureCrud(
        ?object $service = null,
        ?string $storeRequest = null,
        ?string $updateRequest = null,
        ?string $resourceName = null,
        bool $applySmartScopes = true
    ): void {
        $this->service = $service;
        $this->storeRequest = $storeRequest;
        $this->updateRequest = $updateRequest;
        $this->resourceName = $resourceName;
        $this->applySmartScopes = $applySmartScopes;

        if ($resourceName !== null) {
            $this->modelClass = $this->detectModelClass();
        }
    }

    // ==================== OPERACIONES CRUD PÚBLICAS ====================

    /**
     * Lista todos los registros con soporte completo para HasSmartScopes.
     *
     * Ejemplos:
     * - GET /api/resource
     * - GET /api/resource?included=author,comments
     * - GET /api/resource?filter[status]=active&sort=-created_at
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Usar servicio si está configurado
            if ($this->hasServiceMethod('all')) {
                return $this->successResponse(
                    $this->service->all(),
                    "{$this->getResourceName()} obtenidos correctamente"
                );
            }

            $query = $this->createModelQuery();

            // Aplicar scopes inteligentes si están disponibles
            if ($this->shouldApplySmartScopes()) {
                $query = $this->applySmartScopes($query);
            }

            $data = $this->executeQueryWithPagination($query);

            return $this->successResponse(
                $data,
                "{$this->getResourceName()} obtenidos correctamente"
            );

        } catch (Exception $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * Muestra un registro específico con soporte para included.
     *
     * Ejemplo:
     * - GET /api/resource/1?included=author,comments
     */
    public function show(string|int $id): JsonResponse
    {
        try {
            $query = $this->createModelQuery();

            // Aplicar relaciones si están disponibles
            if ($this->shouldApplyIncludedScope()) {
                $query->included();
            }

            $record = $query->find($id);

            if ($record === null) {
                return $this->notFoundResponse();
            }

            return $this->successResponse(
                $record,
                "{$this->getResourceName()} obtenido correctamente"
            );

        } catch (Exception $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * Crea un nuevo registro con soporte para relaciones many-to-many.
     */
    public function store(Request $request): JsonResponse
    {
        DB::beginTransaction();

        try {
            $validated = $this->validateRequest($request, 'store');
            $relationData = $this->extractManyToManyRelations($validated);

            // Crear registro usando servicio o directamente
            $record = $this->hasServiceMethod('create')
                ? $this->service->create($validated)
                : $this->createRecord($validated);

            // Sincronizar relaciones many-to-many
            $this->syncManyToManyRelations($record, $relationData);

            DB::commit();

            // Recargar con relaciones
            $record = $this->reloadRecordWithRelations($record, $relationData);

            return $this->successResponse(
                $record,
                "{$this->getResourceName()} creado correctamente",
                201
            );

        } catch (ValidationException $e) {
            DB::rollBack();
            return $this->validationErrorResponse($e);

        } catch (Exception $e) {
            DB::rollBack();
            return $this->errorResponse($e);
        }
    }

    /**
     * Actualiza un registro existente con soporte para relaciones many-to-many.
     */
    public function update(Request $request, string|int $id): JsonResponse
    {
        DB::beginTransaction();

        try {
            $record = $this->findRecordOrFail($id);
            $validated = $this->validateRequest($request, 'update');
            $relationData = $this->extractManyToManyRelations($validated);

            // Actualizar registro usando servicio o directamente
            if ($this->hasServiceMethod('update')) {
                $record = $this->service->update($record, $validated);
            } else {
                $record->update($validated);
            }

            // Sincronizar relaciones many-to-many
            $this->syncManyToManyRelations($record, $relationData);

            DB::commit();

            // Recargar con relaciones
            $record = $this->reloadRecordWithRelations($record, $relationData);

            return $this->successResponse(
                $record,
                "{$this->getResourceName()} actualizado correctamente"
            );

        } catch (ValidationException $e) {
            DB::rollBack();
            return $this->validationErrorResponse($e);

        } catch (Exception $e) {
            DB::rollBack();
            return $this->errorResponse($e);
        }
    }

    /**
     * Elimina un registro con detach automático de relaciones many-to-many.
     */
    public function destroy(string|int $id): JsonResponse
    {
        DB::beginTransaction();

        try {
            $record = $this->findRecordOrFail($id);

            // Detach relaciones many-to-many antes de eliminar
            $this->detachAllManyToManyRelations($record);

            // Eliminar usando servicio o directamente
            $this->hasServiceMethod('delete')
                ? $this->service->delete($record)
                : $record->delete();

            DB::commit();

            return $this->successResponse(
                null,
                "{$this->getResourceName()} eliminado correctamente"
            );

        } catch (Exception $e) {
            DB::rollBack();
            return $this->errorResponse($e);
        }
    }

    // ==================== MÉTODOS PRIVADOS: MODELO ====================

    /**
     * Obtiene la clase del modelo, inicializándola si es necesario.
     */
    private function getModelClass(): string
    {
        if ($this->modelClass === null) {
            $this->resourceName = $this->resourceName ?? $this->detectResourceName();
            $this->modelClass = $this->detectModelClass();
        }

        return $this->modelClass;
    }

    /**
     * Detecta el nombre del recurso desde el nombre del controlador.
     */
    private function detectResourceName(): string
    {
        $className = class_basename(static::class);
        return str_replace('Controller', '', $className);
    }

    /**
     * Detecta y valida la clase del modelo.
     *
     * @throws Exception Si el modelo no existe
     */
    private function detectModelClass(): string
    {
        $modelName = $this->getResourceName();
        $modelClass = "App\\Models\\{$modelName}";

        if (!class_exists($modelClass)) {
            throw new Exception("Modelo {$modelClass} no encontrado");
        }

        return $modelClass;
    }

    /**
     * Crea una nueva instancia de query del modelo.
     */
    private function createModelQuery()
    {
        $modelClass = $this->getModelClass();
        return app($modelClass)->newQuery();
    }

    /**
     * Crea un nuevo registro en la base de datos.
     */
    private function createRecord(array $validated): Model
    {
        $modelClass = $this->getModelClass();
        $record = new $modelClass();
        $record->fill($validated);
        $record->save();

        return $record;
    }

    /**
     * Busca un registro por ID o lanza excepción 404.
     *
     * @throws Exception Si no se encuentra el registro
     */
    private function findRecordOrFail(string|int $id): Model
    {
        $record = $this->createModelQuery()->find($id);

        if ($record === null) {
            throw new Exception("{$this->getResourceName()} no encontrado", 404);
        }

        return $record;
    }

    /**
     * Obtiene el nombre del recurso con lazy initialization.
     */
    private function getResourceName(): string
    {
        if ($this->resourceName === null) {
            $this->resourceName = $this->detectResourceName();
        }

        return $this->resourceName;
    }

    // ==================== MÉTODOS PRIVADOS: SMART SCOPES ====================

    /**
     * Verifica si el modelo tiene el trait HasSmartScopes.
     */
    private function modelHasSmartScopes(): bool
    {
        $modelClass = $this->getModelClass();
        $traits = class_uses_recursive($modelClass);

        return in_array('App\Traits\HasSmartScopes', $traits, true) ||
               in_array('App\\Traits\\HasSmartScopes', $traits, true);
    }

    /**
     * Determina si se deben aplicar los smart scopes.
     */
    private function shouldApplySmartScopes(): bool
    {
        return $this->applySmartScopes && $this->modelHasSmartScopes();
    }

    /**
     * Determina si se debe aplicar el scope included.
     */
    private function shouldApplyIncludedScope(): bool
    {
        return $this->shouldApplySmartScopes() && request()->has('included');
    }

    /**
     * Aplica todos los smart scopes disponibles al query.
     */
    private function applySmartScopes($query)
    {
        $availableScopes = ['included', 'filter', 'sort', 'search', 'fields', 'dateFilter'];

        foreach ($availableScopes as $scope) {
            if ($this->scopeExists($scope)) {
                $query->$scope();
            }
        }

        return $query;
    }

    /**
     * Verifica si un scope existe en el modelo.
     */
    private function scopeExists(string $scopeName): bool
    {
        $modelClass = $this->getModelClass();
        $methodName = 'scope' . ucfirst($scopeName);

        return method_exists($modelClass, $methodName);
    }

    /**
     * Ejecuta el query con paginación inteligente si está disponible.
     */
    private function executeQueryWithPagination($query)
    {
        return $this->shouldApplySmartScopes() && $this->scopeExists('getOrPaginate')
            ? $query->getOrPaginate()
            : $query->get();
    }

    // ==================== MÉTODOS PRIVADOS: RELACIONES ====================

    /**
     * Extrae y separa los datos de relaciones many-to-many del array validado.
     */
    private function extractManyToManyRelations(array &$validated): array
    {
        $modelClass = $this->getModelClass();
        $modelInstance = new $modelClass();
        $manyToManyRelations = $this->getManyToManyRelations($modelInstance);

        $relationData = [];

        foreach ($manyToManyRelations as $relation) {
            if (isset($validated[$relation])) {
                $relationData[$relation] = $validated[$relation];
                unset($validated[$relation]);

                $this->logRelationExtraction($relation, true);
            }
        }

        return $relationData;
    }

    /**
     * Sincroniza las relaciones many-to-many de un modelo.
     */
    private function syncManyToManyRelations(Model $record, array $relationData): void
    {
        foreach ($relationData as $relation => $ids) {
            if (!method_exists($record, $relation)) {
                $this->logRelationWarning($relation, 'método no existe');
                continue;
            }

            $normalizedIds = $this->normalizeRelationIds($ids);

            if (empty($normalizedIds)) {
                $this->logRelationWarning($relation, 'IDs inválidos');
                continue;
            }

            try {
                $record->$relation()->sync($normalizedIds);
                $this->logRelationSync($relation, $normalizedIds);
            } catch (Exception $e) {
                $this->logRelationError($relation, $e);
                throw $e;
            }
        }
    }

    /**
     * Desvincula todas las relaciones many-to-many de un modelo.
     */
    private function detachAllManyToManyRelations(Model $record): void
    {
        $manyToManyRelations = $this->getManyToManyRelations($record);

        foreach ($manyToManyRelations as $relation) {
            try {
                if (method_exists($record, $relation)) {
                    $record->$relation()->detach();
                }
            } catch (Exception $e) {
                Log::warning("No se pudo hacer detach de la relación {$relation}: {$e->getMessage()}");
            }
        }
    }

    /**
     * Recarga un registro con sus relaciones.
     */
    private function reloadRecordWithRelations(Model $record, array $relationData): Model
    {
        if ($this->shouldApplyIncludedScope()) {
            return $this->createModelQuery()
                ->included()
                ->find($record->getKey());
        }

        $manyToManyRelations = array_keys($relationData);

        return !empty($manyToManyRelations)
            ? $record->load($manyToManyRelations)
            : $record->fresh();
    }

    /**
     * Normaliza los IDs de relación a un array de enteros.
     */
    private function normalizeRelationIds(mixed $ids): array
    {
        if (is_array($ids)) {
            return array_filter($ids, fn($id) => is_numeric($id));
        }

        if (is_string($ids) && str_contains($ids, ',')) {
            return array_map('intval', array_filter(explode(',', $ids), 'is_numeric'));
        }

        if (is_numeric($ids)) {
            return [(int) $ids];
        }

        return [];
    }

    /**
     * Obtiene las relaciones many-to-many definidas en el modelo con cache.
     */
    private function getManyToManyRelations(Model $model): array
    {
        $cacheKey = $this->getRelationsCacheKey($model);

        return Cache::remember($cacheKey, self::CACHE_DURATION_RELATIONS, function () use ($model) {
            return $this->detectManyToManyRelations($model);
        });
    }

    /**
     * Detecta relaciones many-to-many inspeccionando el código del modelo.
     */
    private function detectManyToManyRelations(Model $model): array
    {
        $relations = [];
        $methods = get_class_methods($model);

        foreach ($methods as $method) {
            if ($this->shouldSkipMethod($method)) {
                continue;
            }

            if ($this->isManyToManyRelation($model, $method)) {
                $relations[] = $method;
                Log::info("Relación N:M detectada: {$method}");
            }
        }

        return $relations;
    }

    /**
     * Determina si un método debe ser ignorado en la detección de relaciones.
     */
    private function shouldSkipMethod(string $method): bool
    {
        return str_starts_with($method, '__') ||
               str_starts_with($method, 'get') ||
               str_starts_with($method, 'set') ||
               str_starts_with($method, 'scope') ||
               in_array($method, self::EXCLUDED_RELATION_METHODS, true);
    }

    /**
     * Verifica si un método es una relación many-to-many mediante reflexión.
     */
    private function isManyToManyRelation(Model $model, string $method): bool
    {
        try {
            $reflection = new ReflectionMethod($model, $method);

            if (!$this->isValidRelationMethod($reflection)) {
                return false;
            }

            return $this->methodContainsBelongsToMany($reflection);

        } catch (Throwable $e) {
            Log::warning("Error al inspeccionar método {$method}: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Valida que un método sea elegible para ser una relación.
     */
    private function isValidRelationMethod(ReflectionMethod $reflection): bool
    {
        return $reflection->isPublic() &&
               $reflection->getNumberOfRequiredParameters() === 0 &&
               !$reflection->isStatic();
    }

    /**
     * Verifica si el código fuente de un método contiene 'belongsToMany'.
     */
    private function methodContainsBelongsToMany(ReflectionMethod $reflection): bool
    {
        $filename = $reflection->getFileName();
        $startLine = $reflection->getStartLine();
        $endLine = $reflection->getEndLine();

        if (!$filename || !$startLine || !$endLine) {
            return false;
        }

        $sourceLines = file($filename);
        $methodBody = implode('', array_slice($sourceLines, $startLine, $endLine - $startLine));

        return stripos($methodBody, 'belongsToMany') !== false;
    }

    /**
     * Genera la clave de cache para las relaciones de un modelo.
     */
    private function getRelationsCacheKey(Model $model): string
    {
        return 'many_to_many_relations_' . get_class($model);
    }

    // ==================== MÉTODOS PRIVADOS: VALIDACIÓN ====================

    /**
     * Valida la petición usando FormRequest personalizado o reglas dinámicas.
     */
    private function validateRequest(Request $request, string $action): array
    {
        $requestClass = $this->getValidationRequestClass($action);

        if ($requestClass !== null && class_exists($requestClass)) {
            return app($requestClass)->validated();
        }

        return $this->validateWithDynamicRules($request, $action);
    }

    /**
     * Obtiene la clase FormRequest según la acción.
     */
    private function getValidationRequestClass(string $action): ?string
    {
        return $action === 'update'
            ? ($this->updateRequest ?? $this->storeRequest)
            : $this->storeRequest;
    }

    /**
     * Valida usando reglas generadas dinámicamente.
     *
     * @throws ValidationException
     */
    private function validateWithDynamicRules(Request $request, string $action): array
    {
        $rules = $this->generateValidationRules($action);
        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }

    /**
     * Genera reglas de validación automáticamente desde el modelo.
     */
    private function generateValidationRules(string $action): array
    {
        $modelClass = $this->getModelClass();
        $modelInstance = new $modelClass();

        $fields = $this->getValidatableFields($modelInstance);
        $rules = $this->buildFieldRules($fields, $modelInstance, $action);

        // Agregar reglas para relaciones many-to-many
        $manyToManyRelations = $this->getManyToManyRelations($modelInstance);
        foreach ($manyToManyRelations as $relation) {
            $rules[$relation] = 'sometimes|nullable|array';
        }

        return $rules;
    }

    /**
     * Obtiene los campos validables del modelo.
     */
    private function getValidatableFields(Model $model): array
    {
        $table = $model->getTable();
        $fillable = $model->getFillable();

        $fields = !empty($fillable)
            ? $fillable
            : Schema::getColumnListing($table);

        return array_diff($fields, self::EXCLUDED_SYSTEM_FIELDS);
    }

    /**
     * Construye las reglas de validación para los campos.
     */
    private function buildFieldRules(array $fields, Model $model, string $action): array
    {
        $rules = [];
        $table = $model->getTable();

        foreach ($fields as $field) {
            if (!Schema::hasColumn($table, $field)) {
                continue;
            }

            $columnType = Schema::getColumnType($table, $field);
            $rule = $this->generateFieldRule($field, $columnType, $table, $action);

            if ($rule !== null) {
                $rules[$field] = $rule;
            }
        }

        return $rules;
    }

    /**
     * Genera la regla de validación para un campo específico.
     */
    private function generateFieldRule(
        string $field,
        string $type,
        string $table,
        string $action
    ): ?string {
        $baseRule = $action === 'update' ? 'sometimes' : 'required';

        // Campos de relación (foreign keys)
        if ($this->isForeignKeyField($field)) {
            return $this->buildForeignKeyRule($field, $baseRule);
        }

        // Campos booleanos
        if ($this->isBooleanField($field, $type)) {
            return "{$baseRule}|boolean";
        }

        // Campos de email
        if ($this->isEmailField($field)) {
            return "{$baseRule}|email|max:255";
        }

        // Reglas según tipo de columna
        return $this->getRuleByColumnType($type, $baseRule);
    }

    /**
     * Determina si un campo es una foreign key.
     */
    private function isForeignKeyField(string $field): bool
    {
        return str_ends_with($field, '_id');
    }

    /**
     * Construye la regla para un campo foreign key.
     */
    private function buildForeignKeyRule(string $field, string $baseRule): string
    {
        $relatedTable = str_replace('_id', 's', $field);

        if (Schema::hasTable($relatedTable)) {
            return "{$baseRule}|integer|exists:{$relatedTable},id";
        }

        return "{$baseRule}|integer";
    }

    /**
     * Determina si un campo es booleano.
     */
    private function isBooleanField(string $field, string $type): bool
    {
        return str_starts_with($field, 'is_') || $type === 'boolean';
    }

    /**
     * Determina si un campo es de email.
     */
    private function isEmailField(string $field): bool
    {
        return str_contains($field, 'email');
    }

    /**
     * Obtiene la regla de validación según el tipo de columna.
     */
    private function getRuleByColumnType(string $type, string $baseRule): string
    {
        return match ($type) {
            'integer', 'bigint', 'smallint' => "{$baseRule}|integer",
            'decimal', 'float', 'double' => "{$baseRule}|numeric",
            'date' => "{$baseRule}|date",
            'datetime', 'timestamp' => "{$baseRule}|date",
            'text', 'longtext' => "{$baseRule}|string",
            'string' => "{$baseRule}|string|max:255",
            default => "{$baseRule}|string|max:255"
        };
    }

    // ==================== MÉTODOS PRIVADOS: SERVICIOS ====================

    /**
     * Verifica si el servicio tiene un método específico.
     */
    private function hasServiceMethod(string $method): bool
    {
        return $this->service !== null && method_exists($this->service, $method);
    }

    // ==================== MÉTODOS PRIVADOS: RESPUESTAS ====================

    /**
     * Genera una respuesta JSON exitosa.
     */
    private function successResponse(
        mixed $data,
        string $message,
        int $status = 200
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    /**
     * Genera una respuesta JSON de error 404.
     */
    private function notFoundResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => "{$this->getResourceName()} no encontrado",
        ], 404);
    }

    /**
     * Genera una respuesta JSON de error de validación.
     */
    private function validationErrorResponse(ValidationException $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Error de validación',
            'errors' => $e->errors(),
        ], 422);
    }

    /**
     * Genera una respuesta JSON de error genérico.
     */
    private function errorResponse(Exception $e): JsonResponse
    {
        $this->logError($e);

        $statusCode = $this->getExceptionStatusCode($e);

        return response()->json([
            'success' => false,
            'message' => $this->getExceptionMessage($e),
            'trace' => $this->shouldShowTrace() ? $e->getTraceAsString() : null,
        ], $statusCode);
    }

    /**
     * Obtiene el código de estado HTTP desde una excepción.
     */
    private function getExceptionStatusCode(Exception $e): int
    {
        return (int) ($e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500);
    }

    /**
     * Obtiene el mensaje apropiado de una excepción.
     */
    private function getExceptionMessage(Exception $e): string
    {
        return config('app.debug')
            ? $e->getMessage()
            : 'Ha ocurrido un error en el servidor';
    }

    /**
     * Determina si se debe mostrar el trace de la excepción.
     */
    private function shouldShowTrace(): bool
    {
        return (bool) config('app.debug');
    }

    // ==================== MÉTODOS PRIVADOS: LOGGING ====================

    /**
     * Registra un error en los logs.
     */
    private function logError(Exception $e): void
    {
        Log::error('CrudTrait Error: ' . $e->getMessage(), [
            'exception' => get_class($e),
            'trace' => $e->getTraceAsString(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
    }

    /**
     * Registra la extracción de una relación.
     */
    private function logRelationExtraction(string $relation, bool $success): void
    {
        if ($success) {
            Log::info("Relación '{$relation}' extraída correctamente");
        }
    }

    /**
     * Registra la sincronización de una relación.
     */
    private function logRelationSync(string $relation, array $ids): void
    {
        Log::info("Relación '{$relation}' sincronizada con IDs: " . implode(',', $ids));
    }

    /**
     * Registra una advertencia sobre una relación.
     */
    private function logRelationWarning(string $relation, string $reason): void
    {
        Log::warning("Problema con la relación '{$relation}': {$reason}");
    }

    /**
     * Registra un error en una relación.
     */
    private function logRelationError(string $relation, Exception $e): void
    {
        Log::error("Error al sincronizar la relación '{$relation}': {$e->getMessage()}");
    }

    // ==================== MÉTODOS PÚBLICOS: UTILIDADES ====================

    /**
     * Limpia el cache de relaciones del modelo.
     */
    public function clearRelationsCache(): void
    {
        $modelClass = $this->getModelClass();
        $modelInstance = new $modelClass();
        $cacheKey = $this->getRelationsCacheKey($modelInstance);

        Cache::forget($cacheKey);
    }
}

/**
 * =============================================================================
 * GUÍA DE USO - CrudTrait Refactorizado
 * =============================================================================
 *
 * USO BÁSICO (Detección automática):
 *
 * class PublicationController extends Controller
 * {
 *     use CrudTrait;
 *
 *     // ¡Listo! El trait detecta automáticamente el modelo "Publication"
 * }
 *
 * -----------------------------------------------------------------------------
 *
 * USO CON CONFIGURACIÓN PERSONALIZADA:
 *
 * class PublicationController extends Controller
 * {
 *     use CrudTrait;
 *
 *     public function __construct()
 *     {
 *         $this->configureCrud(
 *             service: app(PublicationService::class),
 *             storeRequest: StorePublicationRequest::class,
 *             updateRequest: UpdatePublicationRequest::class,
 *             resourceName: 'Publication',
 *             applySmartScopes: true
 *         );
 *     }
 * }
 *
 * -----------------------------------------------------------------------------
 *
 * INTEGRACIÓN CON HasSmartScopes:
 *
 * El trait detecta automáticamente si tu modelo usa HasSmartScopes.
 * Si lo detecta, aplicará automáticamente estos scopes en el método index():
 *
 * - included()   -> Carga de relaciones
 * - filter()     -> Filtrado avanzado
 * - sort()       -> Ordenamiento
 * - search()     -> Búsqueda global
 * - fields()     -> Selección de campos
 * - dateFilter() -> Filtros de fecha
 *
 * Si NO detecta HasSmartScopes, simplemente devolverá todos los registros
 * sin aplicar scopes (comportamiento seguro por defecto).
 *
 * -----------------------------------------------------------------------------
 *
 * ENDPOINTS GENERADOS AUTOMÁTICAMENTE:
 *
 * GET    /api/resources           -> index()   (Lista todos)
 * GET    /api/resources/{id}      -> show()    (Muestra uno)
 * POST   /api/resources           -> store()   (Crea nuevo)
 * PUT    /api/resources/{id}      -> update()  (Actualiza)
 * DELETE /api/resources/{id}      -> destroy() (Elimina)
 *
 * -----------------------------------------------------------------------------
 *
 * EJEMPLOS DE PETICIONES CON SMART SCOPES:
 *
 * # Filtrado simple
 * GET /api/publications?filter[title]=Laravel
 *
 * # Filtrado avanzado
 * GET /api/publications?filter[status][in]=published,draft&filter[views][gte]=100
 *
 * # Ordenamiento
 * GET /api/publications?sort=-created_at,title
 *
 * # Búsqueda global
 * GET /api/publications?search=tutorial
 *
 * # Relaciones incluidas
 * GET /api/publications?included=author,comments.user,tags
 *
 * # Paginación
 * GET /api/publications?perPage=20&page=2
 *
 * # Combinación de parámetros
 * GET /api/publications?included=author&filter[status]=published&sort=-views&perPage=15
 *
 * -----------------------------------------------------------------------------
 *
 * SOPORTE PARA RELACIONES MANY-TO-MANY:
 *
 * El trait detecta automáticamente relaciones belongsToMany y las maneja:
 *
 * POST /api/publications
 * {
 *     "title": "Mi publicación",
 *     "content": "Contenido...",
 *     "tags": [1, 2, 3],      // ← Sincroniza automáticamente
 *     "categories": [5, 8]     // ← Sincroniza automáticamente
 * }
 *
 * PUT /api/publications/1
 * {
 *     "title": "Título actualizado",
 *     "tags": [2, 4, 6]        // ← Sincroniza automáticamente
 * }
 *
 * DELETE /api/publications/1  // ← Hace detach automático antes de eliminar
 *
 * -----------------------------------------------------------------------------
 *
 * VALIDACIÓN AUTOMÁTICA:
 *
 * Si no defines FormRequests, el trait genera reglas automáticamente:
 *
 * - Detecta tipos de columnas (integer, string, date, etc.)
 * - Valida foreign keys con exists
 * - Detecta campos booleanos (is_active, etc.)
 * - Valida emails automáticamente
 * - Maneja relaciones N:M como arrays opcionales
 *
 * -----------------------------------------------------------------------------
 *
 * RESPUESTAS JSON CONSISTENTES:
 *
 * Éxito:
 * {
 *     "success": true,
 *     "message": "Publication creado correctamente",
 *     "data": { ... }
 * }
 *
 * Error de validación:
 * {
 *     "success": false,
 *     "message": "Error de validación",
 *     "errors": {
 *         "title": ["El campo título es obligatorio"]
 *     }
 * }
 *
 * Error genérico:
 * {
 *     "success": false,
 *     "message": "Ha ocurrido un error en el servidor",
 *     "trace": "..." // Solo en modo debug
 * }
 *
 * =============================================================================
 */
PHP;
    }

    private function getMakeSmartSeedStub()
    {
        return <<<'PHP'
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
PHP;
    }
}
