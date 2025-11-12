<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MakeSmartModels extends Command
{
    protected $signature = 'make:smart-models {--overwrite : Overwrite existing models}';
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

        // Paso 2: Identificar tablas pivot
        $this->identifyPivotTables();

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
                $referencedTable = Str::plural(Str::substr($columnName, 0, -3));
                
                // Verificar si la tabla referenciada podría existir
                $this->tables[$tableName]['foreign_keys'][$columnName] = [
                    'references' => 'id',
                    'on' => $referencedTable,
                    'inferred' => true,
                ];
            }
        }
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

            // Una tabla pivot tiene exactamente 2 foreign keys y pocas columnas adicionales
            if (count($foreignKeys) === 2 && count($columns) <= 4) {
                $fkTables = array_column($foreignKeys, 'on');
                
                // Verificar que no sea la misma tabla dos veces (auto-referencia)
                if ($fkTables[0] !== $fkTables[1]) {
                    $this->pivotTables[] = $tableName;
                    $this->info("🔗 Tabla pivot detectada: {$tableName}");
                }
            }
        }
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
                        
                        // Generar nombre único para la relación
                        $baseRelationName = Str::camel($otherTableName);
                        $relationName = $baseRelationName;
                        
                        // Si hay múltiples FK desde la misma tabla, agregar prefijo del campo
                        $counter = 1;
                        while (in_array($relationName, $hasRelationNames)) {
                            // Usar el prefijo de la columna para diferenciar
                            $prefix = str_replace('_id', '', $column);
                            $prefix = str_replace('_' . Str::snake(Str::singular($tableName)), '', $prefix);
                            $relationName = Str::camel($prefix . '_' . $otherTableName);
                            $counter++;
                            
                            // Fallback si sigue duplicado
                            if ($counter > 2) {
                                $relationName = $baseRelationName . $counter;
                            }
                        }
                        
                        $hasRelationNames[] = $relationName;
                        
                        // Por defecto hasMany, pero podríamos detectar hasOne con unique indexes
                        $this->models[$modelName]['hasMany'][] = [
                            'name' => $relationName,
                            'model' => $relatedModel,
                            'foreign_key' => $column,
                        ];
                    }
                }
            }

            // Detectar belongsToMany (a través de tablas pivot)
            foreach ($this->pivotTables as $pivotTable) {
                $pivotForeignKeys = $this->tables[$pivotTable]['foreign_keys'];
                $fkTables = array_column($pivotForeignKeys, 'on');

                if (in_array($tableName, $fkTables)) {
                    $otherTable = $fkTables[0] === $tableName ? $fkTables[1] : $fkTables[0];
                    $otherModel = $this->getModelName($otherTable);
                    $relationName = Str::camel($otherTable);

                    $this->models[$modelName]['belongsToMany'][] = [
                        'name' => $relationName,
                        'model' => $otherModel,
                        'pivot_table' => $pivotTable,
                    ];
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
        return Str::studly(Str::singular($tableName));
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
}