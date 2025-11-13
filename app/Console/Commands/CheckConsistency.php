<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;

class CheckConsistency extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:consistency 
                            {--fix : Crear automáticamente los modelos faltantes}
                            {--json : Exportar resultado en formato JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Analiza la consistencia estructural del proyecto verificando modelos, migraciones y relaciones';

    /**
     * Contadores de problemas encontrados
     */
    private int $errors = 0;
    private int $warnings = 0;
    private array $issues = [];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔍 Analizando consistencia del proyecto...');
        $this->newLine();

        // 1. Verificar migraciones ↔ modelos
        $this->checkMigrationsAndModels();

        // 2. Verificar que los modelos tengan $fillable o $guarded y que coincidan con las columnas
        $this->checkModelsFillableGuarded();

        // 3. Verificar relaciones rotas
        $this->checkBrokenRelations();

        // 4. Detectar tablas pivote sin modelo
        $this->checkPivotTables();

        // Mostrar resumen final
        $this->showSummary();

        // Exportar a JSON si se solicita
        if ($this->option('json')) {
            $this->exportToJson();
        }

        return $this->errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Verifica que todas las migraciones tengan un modelo correspondiente
     */
    private function checkMigrationsAndModels(): void
    {
        $this->info('📋 Verificando migraciones y modelos...');
        
        // Tablas por defecto de Laravel que se deben ignorar
        $defaultLaravelTables = [
            'password_reset_tokens',
            'password_resets',
            'failed_jobs',
            'personal_access_tokens',
            'sessions',
            'cache',
            'cache_locks',
            'jobs',
            'job_batches',
            'migrations'
        ];
        
        $migrationsPath = database_path('migrations');
        $migrationFiles = File::files($migrationsPath);
        $migrationsCount = 0;
        $modelsFound = 0;
        $issuesFound = false;

        // Obtener tablas pivote declaradas
        $declaredPivotTables = $this->getDeclaredPivotTables();

        foreach ($migrationFiles as $file) {
            $content = File::get($file->getPathname());
            
            // Extraer nombres de tablas de Schema::create
            preg_match_all("/Schema::create\(['\"]([^'\"]+)['\"]/", $content, $matches);
            
            foreach ($matches[1] as $tableName) {
                // Ignorar tablas por defecto de Laravel
                if (in_array($tableName, $defaultLaravelTables)) {
                    continue;
                }
                
                // Si es una tabla pivote declarada en relaciones, ignorarla
                if (in_array($tableName, $declaredPivotTables)) {
                    continue;
                }
                
                $migrationsCount++;
                
                // Intentar encontrar el modelo sin singularizar
                $modelName = Str::studly($tableName);
                $modelPath = app_path("Models/{$modelName}.php");
                
                // Si no existe, intentar con singular
                if (!File::exists($modelPath)) {
                    $modelName = Str::studly(Str::singular($tableName));
                    $modelPath = app_path("Models/{$modelName}.php");
                }
                
                if (File::exists($modelPath)) {
                    $modelsFound++;
                } else {
                    // Verificar si parece ser una tabla pivote (tiene 2 foreign keys)
                    $isPivot = $this->isPotentialPivotTable($tableName);
                    
                    if ($isPivot) {
                        $this->addWarning("Posible tabla pivote '{$tableName}' sin modelo (esto es normal si se usa en belongsToMany).");
                    } else {
                        $this->addWarning("Falta el modelo: App\\Models\\{$modelName} (tabla {$tableName})");
                    }
                    
                    $issuesFound = true;
                    
                    // Opción --fix: crear modelo automáticamente solo si no es pivote
                    if ($this->option('fix') && !$isPivot) {
                        $this->createModel($modelName, $tableName);
                    }
                }
            }
        }

        $this->line("✅ {$migrationsCount} migraciones analizadas.");
        $this->line("✅ {$modelsFound} modelos encontrados.");
        
        if (!$issuesFound && $migrationsCount > 0) {
            $this->line('<fg=green>✅ Todas las migraciones tienen su modelo correspondiente.</>');
        }
        
        $this->newLine();
    }

    /**
     * Verifica si una tabla parece ser pivote (tiene exactamente 2 foreign keys)
     */
    private function isPotentialPivotTable(string $tableName): bool
    {
        try {
            if (!Schema::hasTable($tableName)) {
                return false;
            }
            
            $columns = Schema::getColumnListing($tableName);
            $foreignKeys = array_filter($columns, fn($col) => str_ends_with($col, '_id'));
            
            // Si tiene exactamente 2 foreign keys y al menos un guion bajo en el nombre, probablemente es pivote
            return count($foreignKeys) === 2 && substr_count($tableName, '_') >= 1;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Verifica que todos los modelos tengan $fillable o $guarded
     */
    private function checkModelsFillableGuarded(): void
    {
        $this->info('🧱 Verificando $fillable y $guarded en modelos...');
        
        $modelsPath = app_path('Models');
        
        if (!File::exists($modelsPath)) {
            $this->addWarning('No se encontró el directorio app/Models');
            return;
        }

        $modelFiles = File::files($modelsPath);
        $issuesFound = false;

        foreach ($modelFiles as $file) {
            $content = File::get($file->getPathname());
            $modelName = $file->getFilenameWithoutExtension();
            
            // Buscar $fillable o $guarded
            $hasFillable = preg_match('/protected\s+\$fillable\s*=\s*\[(.*?)\]/s', $content, $fillableMatches);
            $hasGuarded = preg_match('/protected\s+\$guarded\s*=\s*\[(.*?)\]/s', $content, $guardedMatches);
            
            if (!$hasFillable && !$hasGuarded) {
                $this->addWarning("El modelo {$modelName} no tiene \$fillable ni \$guarded definido.");
                $issuesFound = true;
            } elseif ($hasFillable) {
                // Verificar si $fillable está vacío
                $fillableContent = trim($fillableMatches[1] ?? '');
                if (empty($fillableContent)) {
                    $this->addWarning("El modelo {$modelName} tiene \$fillable vacío.");
                    $issuesFound = true;
                } else {
                    // Validar que los campos en $fillable coincidan con la migración
                    $fillableIssue = $this->validateFillableAgainstMigration($modelName, $fillableContent);
                    if ($fillableIssue) {
                        $issuesFound = true;
                    }
                }
            } elseif ($hasGuarded) {
                // Verificar si $guarded está vacío (y no es ['*'])
                $guardedContent = trim($guardedMatches[1] ?? '');
                if (empty($guardedContent)) {
                    $this->addWarning("El modelo {$modelName} tiene \$guarded vacío.");
                    $issuesFound = true;
                }
            }
        }
        
        if (!$issuesFound) {
            $this->line('<fg=green>✅ Todos los modelos tienen $fillable o $guarded correctamente definidos.</>');
        }
        
        $this->newLine();
    }

    /**
     * Valida que los campos en $fillable coincidan con las columnas de la migración
     */
    private function validateFillableAgainstMigration(string $modelName, string $fillableContent): bool
    {
        $issuesFound = false;
        
        try {
            // Obtener el nombre de la tabla desde el modelo
            $modelClass = "App\\Models\\{$modelName}";
            if (!class_exists($modelClass)) {
                return false;
            }
            
            $model = new $modelClass();
            $tableName = $model->getTable();
            
            // Verificar si la tabla existe en la base de datos
            if (!Schema::hasTable($tableName)) {
                return false;
            }
            
            // Obtener las columnas reales de la tabla en la base de datos
            $tableColumns = Schema::getColumnListing($tableName);
            
            // Columnas que se deben ignorar (tanto para fillable como para la comparación)
            $ignoredColumns = ['id', 'created_at', 'updated_at', 'deleted_at', 'remember_token', 'email_verified_at'];
            
            // Extraer los campos de $fillable
            preg_match_all("/['\"]([^'\"]+)['\"]/", $fillableContent, $matches);
            $fillableFields = $matches[1] ?? [];
            
            // Verificar campos en $fillable que no existen en la tabla
            foreach ($fillableFields as $field) {
                // Ignorar campos que son automáticos de Laravel
                if (in_array($field, $ignoredColumns)) {
                    $this->addWarning("El modelo {$modelName} tiene '{$field}' en \$fillable, pero es un campo automático que no debería estar ahí.");
                    $issuesFound = true;
                    continue;
                }
                
                if (!in_array($field, $tableColumns)) {
                    $this->addError("El modelo {$modelName} tiene '{$field}' en \$fillable pero no existe en la tabla {$tableName}.");
                    $issuesFound = true;
                }
            }
            
            // Filtrar las columnas de la tabla (remover las ignoradas)
            $relevantTableColumns = array_diff($tableColumns, $ignoredColumns);
            
            // Verificar columnas de la tabla que no están en $fillable
            foreach ($relevantTableColumns as $column) {
                if (!in_array($column, $fillableFields)) {
                    $this->addWarning("El modelo {$modelName} no tiene '{$column}' en \$fillable (existe en tabla {$tableName}).");
                    $issuesFound = true;
                }
            }
            
        } catch (\Exception $e) {
            // Si hay error, no reportar (puede ser tabla que no existe aún)
            return false;
        }
        
        return $issuesFound;
    }

    /**
     * Verifica que las relaciones apunten a modelos existentes
     */
    private function checkBrokenRelations(): void
    {
        $this->info('🔗 Verificando relaciones entre modelos...');
        
        $modelsPath = app_path('Models');
        
        if (!File::exists($modelsPath)) {
            return;
        }

        $modelFiles = File::files($modelsPath);
        $relationMethods = ['belongsTo', 'hasMany', 'hasOne', 'belongsToMany', 'morphTo', 'morphMany', 'morphOne'];
        $issuesFound = false;

        foreach ($modelFiles as $file) {
            $modelName = $file->getFilenameWithoutExtension();
            $modelClass = "App\\Models\\{$modelName}";
            
            if (!class_exists($modelClass)) {
                continue;
            }

            try {
                $reflection = new ReflectionClass($modelClass);
                $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

                foreach ($methods as $method) {
                    // Solo analizar métodos del modelo actual, no heredados
                    if ($method->class !== $modelClass) {
                        continue;
                    }

                    $methodContent = $this->getMethodContent($method);
                    
                    foreach ($relationMethods as $relationType) {
                        if (preg_match("/\\\$this->{$relationType}\(([^)]+)\)/", $methodContent, $matches)) {
                            $hasIssue = $this->validateRelation($modelName, $method->name, $matches[1], $relationType);
                            if ($hasIssue) {
                                $issuesFound = true;
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                $this->addWarning("No se pudo analizar el modelo {$modelName}: {$e->getMessage()}");
                $issuesFound = true;
            }
        }
        
        if (!$issuesFound) {
            $this->line('<fg=green>✅ Todas las relaciones apuntan a modelos existentes.</>');
        }
        
        $this->newLine();
    }

    /**
     * Valida que el modelo relacionado exista
     */
    private function validateRelation(string $modelName, string $methodName, string $relationParam, string $relationType): bool
    {
        // Extraer el nombre de la clase relacionada
        preg_match("/([A-Za-z]+)::class/", $relationParam, $matches);
        
        if (isset($matches[1])) {
            $relatedModel = $matches[1];
            $relatedModelPath = app_path("Models/{$relatedModel}.php");
            
            if (!File::exists($relatedModelPath)) {
                $this->addError("Relación rota: {$modelName}::{$methodName}() apunta a modelo inexistente App\\Models\\{$relatedModel}");
                return true;
            }
        }
        
        return false;
    }

    /**
     * Detecta tablas pivote sin modelo
     */
    private function checkPivotTables(): void
    {
        $this->info('🔁 Detectando tablas pivote...');
        
        // Tablas por defecto de Laravel que se deben ignorar
        $defaultLaravelTables = [
            'password_reset_tokens',
            'password_resets',
            'failed_jobs',
            'personal_access_tokens',
            'sessions',
            'cache',
            'cache_locks',
            'jobs',
            'job_batches',
            'migrations'
        ];
        
        $issuesFound = false;
        
        try {
            // Primero, obtener todas las relaciones belongsToMany y sus tablas pivote
            $declaredPivotTables = $this->getDeclaredPivotTables();
            
            // Obtener todas las tablas usando DB raw query
            $tables = DB::select('SHOW TABLES');
            $databaseName = config('database.connections.' . config('database.default') . '.database');
            $tableKey = "Tables_in_{$databaseName}";

            foreach ($tables as $table) {
                $tableName = $table->$tableKey ?? null;
                
                if (!$tableName || in_array($tableName, $defaultLaravelTables)) {
                    continue;
                }

                // Detectar posibles tablas pivote (usualmente table1_table2)
                if (substr_count($tableName, '_') >= 1) {
                    $columns = Schema::getColumnListing($tableName);
                    $foreignKeys = array_filter($columns, fn($col) => str_ends_with($col, '_id'));

                    // Si tiene exactamente 2 claves foráneas, probablemente es pivote
                    if (count($foreignKeys) === 2) {
                        // Verificar si está declarada en alguna relación belongsToMany
                        if (in_array($tableName, $declaredPivotTables)) {
                            // La tabla pivote tiene relación declarada, todo bien
                            continue;
                        }
                        
                        // Intentar encontrar el modelo
                        $modelName = Str::studly($tableName);
                        $modelPath = app_path("Models/{$modelName}.php");
                        
                        // Si no existe, intentar con singular
                        if (!File::exists($modelPath)) {
                            $modelName = Str::studly(Str::singular($tableName));
                            $modelPath = app_path("Models/{$modelName}.php");
                        }

                        if (!File::exists($modelPath)) {
                            $this->addWarning("Tabla pivote {$tableName} no tiene modelo ni relación declarada.");
                            $issuesFound = true;
                        }
                    }
                }
            }
            
            if (!$issuesFound) {
                $this->line('<fg=green>✅ Todas las tablas pivote están correctamente configuradas.</>');
            }
        } catch (\Exception $e) {
            $this->addWarning("No se pudo verificar tablas pivote: {$e->getMessage()}");
        }
        
        $this->newLine();
    }

    /**
     * Obtiene todas las tablas pivote declaradas en relaciones belongsToMany
     */
    private function getDeclaredPivotTables(): array
    {
        $pivotTables = [];
        $modelsPath = app_path('Models');
        
        if (!File::exists($modelsPath)) {
            return $pivotTables;
        }

        $modelFiles = File::files($modelsPath);

        foreach ($modelFiles as $file) {
            $modelName = $file->getFilenameWithoutExtension();
            $modelClass = "App\\Models\\{$modelName}";
            
            if (!class_exists($modelClass)) {
                continue;
            }

            try {
                $reflection = new ReflectionClass($modelClass);
                $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

                foreach ($methods as $method) {
                    // Solo analizar métodos del modelo actual, no heredados
                    if ($method->class !== $modelClass) {
                        continue;
                    }

                    $methodContent = $this->getMethodContent($method);
                    
                    // Buscar relaciones belongsToMany con tabla pivote especificada
                    if (preg_match("/\\\$this->belongsToMany\([^,)]+,\s*['\"]([^'\"]+)['\"]/", $methodContent, $matches)) {
                        $pivotTables[] = $matches[1];
                    }
                }
            } catch (\Exception $e) {
                continue;
            }
        }
        
        return array_unique($pivotTables);
    }

    /**
     * Muestra el resumen final
     */
    private function showSummary(): void
    {
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('📊 RESUMEN FINAL');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->newLine();

        if ($this->errors === 0 && $this->warnings === 0) {
            $this->line('<fg=green>✅ ¡Excelente! Todas las estructuras son coherentes.</>');
        } else {
            $this->line("<fg=red>❌ {$this->errors} errores encontrados.</>");
            $this->line("<fg=yellow>⚠️  {$this->warnings} advertencias encontradas.</>");
        }

        $this->newLine();
        $this->line("🔎 Resultado: <fg=red>{$this->errors} errores</>, <fg=yellow>{$this->warnings} advertencias</>");
    }

    /**
     * Añade un error al registro
     */
    private function addError(string $message): void
    {
        $this->errors++;
        $this->issues[] = ['type' => 'error', 'message' => $message];
        $this->line("<fg=red>❌ {$message}</>");
    }

    /**
     * Añade una advertencia al registro
     */
    private function addWarning(string $message): void
    {
        $this->warnings++;
        $this->issues[] = ['type' => 'warning', 'message' => $message];
        $this->line("<fg=yellow>⚠️  {$message}</>");
    }

    /**
     * Obtiene el contenido de un método usando Reflection
     */
    private function getMethodContent(ReflectionMethod $method): string
    {
        $filename = $method->getFileName();
        $startLine = $method->getStartLine() - 1;
        $endLine = $method->getEndLine();
        $length = $endLine - $startLine;

        $source = file($filename);
        $body = implode('', array_slice($source, $startLine, $length));

        return $body;
    }

    /**
     * Crea un modelo automáticamente (opción --fix)
     */
    private function createModel(string $modelName, string $tableName): void
    {
        $stub = <<<PHP
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class {$modelName} extends Model
{
    protected \$table = '{$tableName}';
    
    protected \$fillable = [];
}

PHP;

        $path = app_path("Models/{$modelName}.php");
        File::put($path, $stub);
        
        $this->line("<fg=green>✅ Modelo {$modelName} creado automáticamente.</>");
    }

    /**
     * Exporta el resultado a JSON (opción --json)
     */
    private function exportToJson(): void
    {
        $result = [
            'timestamp' => now()->toDateTimeString(),
            'summary' => [
                'errors' => $this->errors,
                'warnings' => $this->warnings,
                'total_issues' => $this->errors + $this->warnings,
            ],
            'issues' => $this->issues,
        ];

        $filename = 'consistency-check-' . now()->format('Y-m-d-His') . '.json';
        $path = storage_path("logs/{$filename}");
        
        File::put($path, json_encode($result, JSON_PRETTY_PRINT));
        
        $this->newLine();
        $this->info("📄 Reporte exportado: {$path}");
    }
}