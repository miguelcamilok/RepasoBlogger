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