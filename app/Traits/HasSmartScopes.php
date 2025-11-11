<?php

declare(strict_types=1);

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{
    BelongsTo,
    BelongsToMany,
    HasMany,
    HasManyThrough,
    HasOne,
    HasOneThrough,
    MorphMany,
    MorphOne,
    MorphTo,
    MorphToMany,
    Relation
};
use Illuminate\Support\Facades\{Cache, Schema};
use Illuminate\Support\Str;
use ReflectionMethod;
use Throwable;

/**
 * Trait HasSmartScopes
 *
 * Proporciona scopes avanzados para queries Eloquent con soporte para:
 * - Carga dinámica de relaciones (included)
 * - Filtrado avanzado con múltiples operadores (filter)
 * - Ordenamiento por columnas y relaciones (sort)
 * - Búsqueda global (search)
 * - Paginación inteligente (getOrPaginate)
 * - Selección de campos específicos (fields)
 * - Filtros de fecha (dateFilter)
 *
 * @property array $searchable Columnas disponibles para búsqueda global
 * @property string $defaultSort Ordenamiento por defecto
 * @property int $maxPerPage Máximo de registros por página
 */
trait HasSmartScopes
{
    // ==================== CONFIGURACIÓN ====================
    
    private const SCHEMA_CACHE_DURATION = 3600;
    private const DEFAULT_MAX_PER_PAGE = 100;
    
    private const FILTER_OPERATORS = [
        'eq' => '=',
        'ne' => '!=',
        'gt' => '>',
        'gte' => '>=',
        'lt' => '<',
        'lte' => '<=',
        'like' => 'LIKE',
        'not_like' => 'NOT LIKE',
        'in' => 'IN',
        'not_in' => 'NOT IN',
        'between' => 'BETWEEN',
        'null' => 'NULL',
        'not_null' => 'NOT NULL',
        'starts' => 'STARTS',
        'ends' => 'ENDS',
    ];
    
    private const DATE_FILTER_TYPES = [
        'from', 'to', 'today', 'yesterday', 
        'last_days', 'this_month', 'last_month'
    ];

    private array $appliedJoins = [];

    // ==================== SCOPES PÚBLICOS ====================

    /**
     * Scope: Carga relaciones dinámicamente con validación y conteos.
     *
     * Ejemplos:
     * - ?included=author,comments.user
     * - ?included=posts:id,title|comments:limit(5)
     * - ?included=author_count
     */
    public function scopeIncluded(Builder $query): void
    {
        $includedParam = $this->getRequestParam('included');
        
        if (empty($includedParam)) {
            return;
        }

        $relations = array_map('trim', explode(',', $includedParam));
        [$validRelations, $countsToLoad] = $this->parseIncludedRelations($relations);

        if (!empty($validRelations)) {
            $query->with($validRelations);
        }

        if (!empty($countsToLoad)) {
            $query->withCount($countsToLoad);
        }
    }

    /**
     * Scope: Filtros avanzados con múltiples operadores.
     *
     * Ejemplos:
     * - ?filter[name]=Juan (LIKE %Juan%)
     * - ?filter[age][gte]=18 (age >= 18)
     * - ?filter[status][in]=active,pending
     * - ?filter[created_at][between]=2024-01-01,2024-12-31
     */
    public function scopeFilter(Builder $query): void
    {
        $filters = $this->getRequestParam('filter');
        
        if (!is_array($filters) || empty($filters)) {
            return;
        }

        $columns = $this->getCachedTableColumns();
        $table = $this->getTable();

        foreach ($filters as $column => $value) {
            if (!$this->isValidColumn($column, $columns)) {
                continue;
            }

            $this->applyColumnFilter($query, $table, $column, $value);
        }
    }

    /**
     * Scope: Ordenamiento múltiple con validación.
     *
     * Ejemplos:
     * - ?sort=name (ASC por defecto)
     * - ?sort=-created_at (DESC)
     * - ?sort=status,-created_at (múltiple)
     * - ?sort=author.name (por relación)
     */
    public function scopeSort(Builder $query): void
    {
        $sortParam = $this->getRequestParam('sort') 
            ?? $this->getDefaultSort();

        if (empty($sortParam)) {
            return;
        }

        $this->appliedJoins = [];
        $sorts = array_map('trim', explode(',', $sortParam));
        $columns = $this->getCachedTableColumns();

        foreach ($sorts as $field) {
            $this->applySingleSort($query, $field, $columns);
        }
    }

    /**
     * Scope: Paginación inteligente con límites.
     *
     * Ejemplos:
     * - ?perPage=15
     * - ?page=2&perPage=20
     */
    public function scopeGetOrPaginate(Builder $query)
    {
        $perPage = $this->getValidatedPerPage();

        return $perPage > 0
            ? $query->paginate($perPage)->appends(request()->query())
            : $query->get();
    }

    /**
     * Scope: Búsqueda global en múltiples campos.
     *
     * Ejemplo:
     * - ?search=juan
     */
    public function scopeSearch(Builder $query, ?string $term = null): void
    {
        $searchTerm = $term ?? $this->getRequestParam('search');

        if (empty($searchTerm)) {
            return;
        }

        $searchableColumns = $this->getSearchableColumns();
        $table = $this->getTable();

        $query->where(function (Builder $subQuery) use ($searchTerm, $searchableColumns, $table) {
            foreach ($searchableColumns as $column) {
                $subQuery->orWhere("{$table}.{$column}", 'LIKE', "%{$searchTerm}%");
            }
        });
    }

    /**
     * Scope: Selecciona campos específicos (sparse fieldsets).
     *
     * Ejemplo:
     * - ?fields=id,name,email
     */
    public function scopeFields(Builder $query): void
    {
        $fieldsParam = $this->getRequestParam('fields');

        if (empty($fieldsParam)) {
            return;
        }

        $columns = $this->getCachedTableColumns();
        $requestedFields = array_map('trim', explode(',', $fieldsParam));
        $validFields = $this->getValidFieldsWithPrimaryKey($requestedFields, $columns);

        if (!empty($validFields)) {
            $table = $this->getTable();
            $qualifiedFields = array_map(
                fn(string $field): string => "{$table}.{$field}",
                $validFields
            );
            
            $query->select($qualifiedFields);
        }
    }

    /**
     * Scope: Filtros de fecha inteligentes.
     *
     * Ejemplos:
     * - ?date[created_at][from]=2024-01-01
     * - ?date[created_at][to]=2024-12-31
     * - ?date[created_at][today]=true
     * - ?date[created_at][last_days]=7
     */
    public function scopeDateFilter(Builder $query): void
    {
        $dateFilters = $this->getRequestParam('date');

        if (!is_array($dateFilters) || empty($dateFilters)) {
            return;
        }

        $columns = $this->getCachedTableColumns();
        $table = $this->getTable();

        foreach ($dateFilters as $column => $filters) {
            if (!$this->isValidColumn($column, $columns) || !is_array($filters)) {
                continue;
            }

            $this->applyDateFilters($query, "{$table}.{$column}", $filters);
        }
    }

    // ==================== MÉTODOS PRIVADOS: INCLUDED ====================

    /**
     * Parsea las relaciones incluidas y separa los counts.
     *
     * @return array [relaciones_válidas, counts_a_cargar]
     */
    private function parseIncludedRelations(array $relations): array
    {
        $validRelations = [];
        $countsToLoad = [];

        foreach ($relations as $relationPath) {
            if ($this->isCountRelation($relationPath)) {
                $relationName = $this->extractCountRelationName($relationPath);
                
                if ($this->isValidRelation($this, $relationName)) {
                    $countsToLoad[] = $relationName;
                }
                continue;
            }

            [$relation, $constraints] = $this->parseRelationConstraints($relationPath);

            if ($this->isValidNestedRelation($this, explode('.', $relation))) {
                $validRelations = $this->addRelationToList($validRelations, $relation, $constraints);
            }
        }

        return [$validRelations, $countsToLoad];
    }

    /**
     * Determina si una relación es de tipo count.
     */
    private function isCountRelation(string $relationPath): bool
    {
        return str_ends_with($relationPath, '_count');
    }

    /**
     * Extrae el nombre de la relación desde un count.
     */
    private function extractCountRelationName(string $relationPath): string
    {
        return substr($relationPath, 0, -6);
    }

    /**
     * Añade una relación a la lista de relaciones válidas.
     */
    private function addRelationToList(array $relations, string $relation, mixed $constraints): array
    {
        if ($constraints !== null) {
            $relations[$relation] = $constraints;
        } else {
            $relations[] = $relation;
        }

        return $relations;
    }

    /**
     * Parsea constraints de relaciones (select, limit, etc.).
     *
     * Ejemplo: "comments:id,text|limit(5)"
     *
     * @return array [nombre_relación, constraints_closure|null]
     */
    private function parseRelationConstraints(string $relationPath): array
    {
        if (!str_contains($relationPath, ':') && !str_contains($relationPath, '|')) {
            return [$relationPath, null];
        }

        [$relation, $constraintsString] = explode(':', $relationPath, 2);

        if (empty($constraintsString)) {
            return [$relation, null];
        }

        return [$relation, $this->buildConstraintsClosure($constraintsString)];
    }

    /**
     * Construye el closure de constraints para una relación.
     */
    private function buildConstraintsClosure(string $constraintsString): callable
    {
        return function (Builder $query) use ($constraintsString): void {
            if (str_contains($constraintsString, '|')) {
                [$fields, $extras] = explode('|', $constraintsString, 2);
                $query->select(array_map('trim', explode(',', $fields)));

                if (preg_match('/limit\((\d+)\)/', $extras, $matches)) {
                    $query->limit((int) $matches[1]);
                }
            } else {
                $query->select(array_map('trim', explode(',', $constraintsString)));
            }
        };
    }

    // ==================== MÉTODOS PRIVADOS: FILTER ====================

    /**
     * Aplica un filtro a una columna específica.
     */
    private function applyColumnFilter(Builder $query, string $table, string $column, mixed $value): void
    {
        // Filtro simple: ?filter[name]=value
        if (!is_array($value)) {
            $query->where("{$table}.{$column}", 'LIKE', "%{$value}%");
            return;
        }

        // Filtros avanzados: ?filter[column][operator]=value
        foreach ($value as $operator => $operatorValue) {
            $this->applyFilterOperator($query, $table, $column, $operator, $operatorValue);
        }
    }

    /**
     * Aplica un operador de filtrado específico.
     */
    private function applyFilterOperator(
        Builder $query,
        string $table,
        string $column,
        string $operator,
        mixed $value
    ): void {
        $operator = strtolower($operator);

        if (!isset(self::FILTER_OPERATORS[$operator])) {
            return;
        }

        $qualifiedColumn = "{$table}.{$column}";

        match ($operator) {
            'null' => $this->applyNullFilter($query, $qualifiedColumn, $value),
            'not_null' => $this->applyNotNullFilter($query, $qualifiedColumn, $value),
            'in' => $query->whereIn($qualifiedColumn, $this->normalizeArrayValue($value)),
            'not_in' => $query->whereNotIn($qualifiedColumn, $this->normalizeArrayValue($value)),
            'between' => $query->whereBetween($qualifiedColumn, $this->normalizeArrayValue($value)),
            'starts' => $query->where($qualifiedColumn, 'LIKE', "{$value}%"),
            'ends' => $query->where($qualifiedColumn, 'LIKE', "%{$value}"),
            'like' => $query->where($qualifiedColumn, 'LIKE', "%{$value}%"),
            'not_like' => $query->where($qualifiedColumn, 'NOT LIKE', "%{$value}%"),
            default => $query->where($qualifiedColumn, self::FILTER_OPERATORS[$operator], $value)
        };
    }

    /**
     * Aplica filtro NULL según el valor booleano.
     */
    private function applyNullFilter(Builder $query, string $column, mixed $value): void
    {
        $value ? $query->whereNull($column) : $query->whereNotNull($column);
    }

    /**
     * Aplica filtro NOT NULL según el valor booleano.
     */
    private function applyNotNullFilter(Builder $query, string $column, mixed $value): void
    {
        $value ? $query->whereNotNull($column) : $query->whereNull($column);
    }

    /**
     * Normaliza un valor a array (útil para IN, NOT IN, BETWEEN).
     */
    private function normalizeArrayValue(mixed $value): array
    {
        return is_array($value) ? $value : explode(',', (string) $value);
    }

    // ==================== MÉTODOS PRIVADOS: SORT ====================

    /**
     * Aplica un ordenamiento individual.
     */
    private function applySingleSort(Builder $query, string $field, array $columns): void
    {
        [$column, $direction] = $this->parseSortField($field);

        if (str_contains($column, '.')) {
            $this->applySortByRelation($query, $column, $direction);
        } elseif ($this->isValidColumn($column, $columns)) {
            $query->orderBy("{$this->getTable()}.{$column}", $direction);
        }
    }

    /**
     * Parsea un campo de ordenamiento extrayendo dirección.
     *
     * @return array [columna, dirección]
     */
    private function parseSortField(string $field): array
    {
        $direction = str_starts_with($field, '-') ? 'desc' : 'asc';
        $column = ltrim($field, '-');

        return [$column, $direction];
    }

    /**
     * Aplica ordenamiento por columna de relación con soporte para relaciones anidadas.
     */
    private function applySortByRelation(Builder $query, string $path, string $direction): void
    {
        $segments = explode('.', $path);
        $finalColumn = array_pop($segments);

        $joinChain = $this->buildJoinChain($segments, $finalColumn);

        if (empty($joinChain)) {
            return;
        }

        $this->ensureBaseSelect($query);
        $this->applyJoinChain($query, $joinChain);

        $lastTable = end($joinChain)['table'];
        $query->orderBy("{$lastTable}.{$finalColumn}", $direction);

        $this->applyGroupByToAvoidDuplicates($query);
    }

    /**
     * Construye la cadena de joins para relaciones anidadas.
     */
    private function buildJoinChain(array $segments, string $finalColumn): array
    {
        $currentModel = $this;
        $currentTable = $this->getTable();
        $joinChain = [];

        foreach ($segments as $index => $relationName) {
            $relationInstance = $this->getRelationInstance($currentModel, $relationName);

            if ($relationInstance === null) {
                return [];
            }

            $relatedModel = $relationInstance->getRelated();
            $relatedTable = $relatedModel->getTable();

            // Validar columna final en la última relación
            if ($index === array_key_last($segments)) {
                if (!$this->columnExistsInTable($finalColumn, $relatedTable)) {
                    return [];
                }
            }

            $joinData = $this->buildJoinForRelation(
                $relationInstance,
                $currentTable,
                $relatedTable,
                $currentModel
            );

            if ($joinData === null) {
                return [];
            }

            $joinChain[] = $joinData;
            $currentModel = $relatedModel;
            $currentTable = $relatedTable;
        }

        return $joinChain;
    }

    /**
     * Asegura que el query tenga un select base.
     */
    private function ensureBaseSelect(Builder $query): void
    {
        if (empty($query->getQuery()->columns)) {
            $query->select("{$this->getTable()}.*");
        }
    }

    /**
     * Aplica todos los joins de la cadena evitando duplicados.
     */
    private function applyJoinChain(Builder $query, array $joinChain): void
    {
        foreach ($joinChain as $join) {
            if (isset($join['pivot'])) {
                $this->applyPivotJoin($query, $join);
            } else {
                $this->applyStandardJoin($query, $join);
            }
        }
    }

    /**
     * Aplica un join con tabla pivote (BelongsToMany, MorphToMany).
     */
    private function applyPivotJoin(Builder $query, array $join): void
    {
        $pivotKey = "{$join['pivot']['table']}.{$join['pivot']['first']}";

        if (!in_array($pivotKey, $this->appliedJoins, true)) {
            $query->leftJoin(
                $join['pivot']['table'],
                $join['pivot']['first'],
                '=',
                $join['pivot']['second']
            );
            $this->appliedJoins[] = $pivotKey;
        }

        $this->applyStandardJoin($query, $join);
    }

    /**
     * Aplica un join estándar.
     */
    private function applyStandardJoin(Builder $query, array $join): void
    {
        $joinKey = "{$join['table']}.{$join['first']}";

        if (!in_array($joinKey, $this->appliedJoins, true)) {
            $query->leftJoin(
                $join['table'],
                $join['first'],
                '=',
                $join['second']
            );
            $this->appliedJoins[] = $joinKey;
        }
    }

    /**
     * Aplica GROUP BY para evitar duplicados (ONLY_FULL_GROUP_BY).
     */
    private function applyGroupByToAvoidDuplicates(Builder $query): void
    {
        $baseTable = $this->getTable();
        $columns = Schema::getColumnListing($baseTable);
        
        $groupColumns = array_map(
            fn(string $col): string => "{$baseTable}.{$col}",
            $columns
        );

        $query->groupBy($groupColumns);
    }

    /**
     * Construye los parámetros de join según el tipo de relación.
     *
     * @return array|null Array con 'table', 'first', 'second' y opcionalmente 'pivot'
     */
    private function buildJoinForRelation(
        Relation $relation,
        string $parentTable,
        string $relatedTable,
        Model $parentModel
    ): ?array {
        return match (true) {
            $relation instanceof BelongsTo => [
                'table' => $relatedTable,
                'first' => "{$parentTable}.{$relation->getForeignKeyName()}",
                'second' => "{$relatedTable}.{$relation->getOwnerKeyName()}",
            ],

            $relation instanceof HasOne,
            $relation instanceof HasMany => [
                'table' => $relatedTable,
                'first' => "{$relatedTable}.{$relation->getForeignKeyName()}",
                'second' => "{$parentTable}.{$relation->getLocalKeyName()}",
            ],

            $relation instanceof BelongsToMany => [
                'pivot' => [
                    'table' => $relation->getTable(),
                    'first' => "{$parentTable}.{$parentModel->getKeyName()}",
                    'second' => "{$relation->getTable()}.{$relation->getForeignPivotKeyName()}",
                ],
                'table' => $relatedTable,
                'first' => "{$relation->getTable()}.{$relation->getRelatedPivotKeyName()}",
                'second' => "{$relatedTable}.{$relation->getRelated()->getKeyName()}",
            ],

            $relation instanceof HasOneThrough,
            $relation instanceof HasManyThrough => [
                'table' => $relatedTable,
                'first' => "{$parentTable}.{$parentModel->getKeyName()}",
                'second' => "{$relatedTable}.{$relation->getFirstKeyName()}",
            ],

            $relation instanceof MorphOne,
            $relation instanceof MorphMany => [
                'table' => $relatedTable,
                'first' => "{$relatedTable}.{$relation->getForeignKeyName()}",
                'second' => "{$parentTable}.{$parentModel->getKeyName()}",
            ],

            $relation instanceof MorphToMany => [
                'pivot' => [
                    'table' => $relation->getTable(),
                    'first' => "{$parentTable}.{$parentModel->getKeyName()}",
                    'second' => "{$relation->getTable()}.{$relation->getForeignPivotKeyName()}",
                ],
                'table' => $relatedTable,
                'first' => "{$relation->getTable()}.{$relation->getRelatedPivotKeyName()}",
                'second' => "{$relatedTable}.{$relation->getRelated()->getKeyName()}",
            ],

            $relation instanceof MorphTo => null,

            default => null,
        };
    }

    // ==================== MÉTODOS PRIVADOS: DATE FILTER ====================

    /**
     * Aplica múltiples filtros de fecha a una columna.
     */
    private function applyDateFilters(Builder $query, string $qualifiedColumn, array $filters): void
    {
        foreach ($filters as $type => $value) {
            if (!in_array($type, self::DATE_FILTER_TYPES, true)) {
                continue;
            }

            match ($type) {
                'from' => $query->whereDate($qualifiedColumn, '>=', $value),
                'to' => $query->whereDate($qualifiedColumn, '<=', $value),
                'today' => $value ? $query->whereDate($qualifiedColumn, today()) : null,
                'yesterday' => $value ? $query->whereDate($qualifiedColumn, today()->subDay()) : null,
                'last_days' => $query->whereDate($qualifiedColumn, '>=', today()->subDays((int) $value)),
                'this_month' => $value ? $this->applyCurrentMonthFilter($query, $qualifiedColumn) : null,
                'last_month' => $value ? $this->applyLastMonthFilter($query, $qualifiedColumn) : null,
                default => null
            };
        }
    }

    /**
     * Aplica filtro del mes actual.
     */
    private function applyCurrentMonthFilter(Builder $query, string $column): void
    {
        $query->whereMonth($column, now()->month)
              ->whereYear($column, now()->year);
    }

    /**
     * Aplica filtro del mes pasado.
     */
    private function applyLastMonthFilter(Builder $query, string $column): void
    {
        $lastMonth = now()->subMonth();
        $query->whereMonth($column, $lastMonth->month)
              ->whereYear($column, $lastMonth->year);
    }

    // ==================== MÉTODOS PRIVADOS: VALIDACIÓN ====================

    /**
     * Valida si una relación existe y es accesible.
     */
    private function isValidRelation(Model $model, string $relation): bool
    {
        $method = Str::camel($relation);

        if (!method_exists($model, $method)) {
            return false;
        }

        try {
            $reflection = new ReflectionMethod($model, $method);

            if ($reflection->getNumberOfParameters() > 0) {
                return false;
            }

            return $reflection->invoke($model) instanceof Relation;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Valida relaciones anidadas.
     */
    private function isValidNestedRelation(Model $model, array $segments): bool
    {
        if (empty($segments)) {
            return true;
        }

        $current = array_shift($segments);
        $relationInstance = $this->getRelationInstance($model, $current);

        if ($relationInstance === null) {
            return false;
        }

        return empty($segments)
            ? true
            : $this->isValidNestedRelation($relationInstance->getRelated(), $segments);
    }

    /**
     * Obtiene una instancia de relación de forma segura.
     */
    private function getRelationInstance(Model $model, string $relation): ?Relation
    {
        $method = Str::camel($relation);

        if (!method_exists($model, $method)) {
            return null;
        }

        try {
            $reflection = new ReflectionMethod($model, $method);

            if ($reflection->getNumberOfParameters() > 0) {
                return null;
            }

            $instance = $reflection->invoke($model);

            return $instance instanceof Relation ? $instance : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Valida si una columna existe en la lista de columnas.
     */
    private function isValidColumn(string $column, array $columns): bool
    {
        return in_array($column, $columns, true);
    }

    /**
     * Valida si una columna existe en una tabla específica.
     */
    private function columnExistsInTable(string $column, string $table): bool
    {
        $columns = Schema::getColumnListing($table);
        return in_array($column, $columns, true);
    }

    // ==================== MÉTODOS PRIVADOS: HELPERS ====================

    /**
     * Obtiene un parámetro del request de forma segura.
     */
    private function getRequestParam(string $key): mixed
    {
        return request($key);
    }

    /**
     * Obtiene el ordenamiento por defecto del modelo.
     */
    private function getDefaultSort(): ?string
    {
        return property_exists($this, 'defaultSort') ? $this->defaultSort : null;
    }

    /**
     * Obtiene las columnas disponibles para búsqueda.
     */
    private function getSearchableColumns(): array
    {
        return property_exists($this, 'searchable')
            ? $this->searchable
            : $this->getCachedTableColumns();
    }

    /**
     * Valida y retorna el perPage del request.
     */
    private function getValidatedPerPage(): int
    {
        $perPage = (int) $this->getRequestParam('perPage');
        $maxPerPage = property_exists($this, 'maxPerPage')
            ? $this->maxPerPage
            : self::DEFAULT_MAX_PER_PAGE;

        if ($perPage > $maxPerPage) {
            return $maxPerPage;
        }

        return max(0, $perPage);
    }

    /**
     * Obtiene campos válidos asegurándose de incluir la clave primaria.
     */
    private function getValidFieldsWithPrimaryKey(array $requestedFields, array $columns): array
    {
        $validFields = array_intersect($requestedFields, $columns);
        $primaryKey = $this->getKeyName();

        if (!in_array($primaryKey, $validFields, true)) {
            $validFields[] = $primaryKey;
        }

        return $validFields;
    }

    /**
     * Obtiene las columnas de la tabla con cache.
     */
    private function getCachedTableColumns(): array
    {
        $cacheKey = $this->getSchemaCacheKey();

        return Cache::remember(
            $cacheKey,
            self::SCHEMA_CACHE_DURATION,
            fn(): array => Schema::getColumnListing($this->getTable())
        );
    }

    /**
     * Genera la clave de cache para el schema de la tabla.
     */
    private function getSchemaCacheKey(): string
    {
        return "table_columns_{$this->getTable()}";
    }

    // ==================== MÉTODOS PÚBLICOS: UTILIDADES ====================

    /**
     * Limpia el cache de columnas de la tabla.
     */
    public function clearSchemaCache(): void
    {
        Cache::forget($this->getSchemaCacheKey());
    }

    /**
     * Método legacy para compatibilidad.
     *
     * @deprecated Use getCachedTableColumns() instead
     */
    protected function getTableColumns(): array
    {
        return $this->getCachedTableColumns();
    }
}