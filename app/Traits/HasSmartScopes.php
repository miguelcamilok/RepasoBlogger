<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionMethod;

trait HasSmartScopes
{
    /**
     * Cache duration para metadatos de la tabla (en segundos)
     */
    protected int $schemaCacheDuration = 3600;

    /**
     * Operadores permitidos para filtros avanzados
     */
    protected array $allowedFilterOperators = [
        'eq' => '=',           // igual
        'ne' => '!=',          // no igual
        'gt' => '>',           // mayor que
        'gte' => '>=',         // mayor o igual
        'lt' => '<',           // menor que
        'lte' => '<=',         // menor o igual
        'like' => 'LIKE',      // contiene
        'not_like' => 'NOT LIKE',
        'in' => 'IN',          // en lista
        'not_in' => 'NOT IN',  // no en lista
        'between' => 'BETWEEN', // entre valores
        'null' => 'NULL',      // es nulo
        'not_null' => 'NOT NULL', // no es nulo
        'starts' => 'STARTS',  // comienza con
        'ends' => 'ENDS',      // termina con
    ];

    /**
     * Scope para cargar relaciones dinámicamente con validación y conteos
     * 
     * Ejemplos:
     * ?included=author,comments.user
     * ?included=posts:id,title|comments:limit(5)
     */
    public function scopeIncluded(Builder $query): void
    {
        $included = request('included');
        if (!$included) {
            return;
        }

        $relations = explode(',', $included);
        $valid = [];
        $withCount = [];

        foreach ($relations as $relationPath) {
            $relationPath = trim($relationPath);
            
            // Detectar si es un count (author_count)
            if (str_ends_with($relationPath, '_count')) {
                $relationName = substr($relationPath, 0, -6);
                if ($this->isValidRelation($this, $relationName)) {
                    $withCount[] = $relationName;
                }
                continue;
            }

            // Procesar relación con posibles constraints
            [$relation, $constraints] = $this->parseRelationConstraints($relationPath);
            
            if ($this->isValidNestedRelation($this, explode('.', $relation))) {
                if ($constraints) {
                    $valid[$relation] = $constraints;
                } else {
                    $valid[] = $relation;
                }
            }
        }

        if (!empty($valid)) {
            $query->with($valid);
        }

        if (!empty($withCount)) {
            $query->withCount($withCount);
        }
    }

    /**
     * Scope para filtros avanzados con múltiples operadores
     * 
     * Ejemplos:
     * ?filter[name]=Juan                          (LIKE %Juan%)
     * ?filter[age][gte]=18                        (age >= 18)
     * ?filter[status][in]=active,pending          (status IN ('active','pending'))
     * ?filter[created_at][between]=2024-01-01,2024-12-31
     * ?filter[email][null]=true                   (email IS NULL)
     * ?filter[name][starts]=Dr.                   (name LIKE 'Dr.%')
     */
    public function scopeFilter(Builder $query): void
    {
        $filters = request('filter');
        if (!$filters || !is_array($filters)) {
            return;
        }

        $columns = $this->getCachedTableColumns();

        foreach ($filters as $column => $value) {
            // Filtro simple: ?filter[name]=value
            if (!is_array($value)) {
                if (in_array($column, $columns)) {
                    $query->where($column, 'LIKE', "%{$value}%");
                }
                continue;
            }

            // Filtros avanzados: ?filter[column][operator]=value
            foreach ($value as $operator => $operatorValue) {
                if (!in_array($column, $columns)) {
                    continue;
                }

                $this->applyFilterOperator($query, $column, $operator, $operatorValue);
            }
        }
    }

    /**
     * Scope para ordenamiento múltiple con validación
     * 
     * Ejemplos:
     * ?sort=name                    (ASC por defecto)
     * ?sort=-created_at             (DESC)
     * ?sort=status,-created_at      (múltiple ordenamiento)
     * ?sort=author.name             (ordenar por relación - si está cargada)
     */
    public function scopeSort(Builder $query): void
    {
        $sort = request('sort');
        if (!$sort) {
            // Ordenamiento por defecto si no se especifica
            if (property_exists($this, 'defaultSort')) {
                $sort = $this->defaultSort;
            } else {
                return;
            }
        }

        $columns = $this->getCachedTableColumns();
        $sorts = explode(',', $sort);

        foreach ($sorts as $field) {
            $field = trim($field);
            $direction = str_starts_with($field, '-') ? 'desc' : 'asc';
            $column = ltrim($field, '-');

            // Ordenamiento por relación (tabla.columna)
            if (str_contains($column, '.')) {
                $this->applySortByRelation($query, $column, $direction);
                continue;
            }

            // Ordenamiento por columna simple
            if (in_array($column, $columns)) {
                $query->orderBy($column, $direction);
            }
        }
    }

    /**
     * Scope para paginación inteligente con límites
     * 
     * Ejemplos:
     * ?perPage=15
     * ?page=2&perPage=20
     */
    public function scopeGetOrPaginate(Builder $query)
    {
        $perPage = intval(request('perPage', 0));
        $maxPerPage = property_exists($this, 'maxPerPage') ? $this->maxPerPage : 100;

        // Limitar perPage al máximo permitido
        if ($perPage > $maxPerPage) {
            $perPage = $maxPerPage;
        }

        return $perPage > 0
            ? $query->paginate($perPage)->appends(request()->query())
            : $query->get();
    }

    /**
     * Scope para búsqueda global en múltiples campos
     * 
     * Ejemplo:
     * ?search=juan
     */
    public function scopeSearch(Builder $query, ?string $term = null): void
    {
        $search = $term ?? request('search');
        
        if (!$search) {
            return;
        }

        $searchableColumns = property_exists($this, 'searchable') 
            ? $this->searchable 
            : $this->getCachedTableColumns();

        $query->where(function ($q) use ($search, $searchableColumns) {
            foreach ($searchableColumns as $column) {
                $q->orWhere($column, 'LIKE', "%{$search}%");
            }
        });
    }

    /**
     * Scope para seleccionar campos específicos (sparse fieldsets)
     * 
     * Ejemplo:
     * ?fields=id,name,email
     */
    public function scopeFields(Builder $query): void
    {
        $fields = request('fields');
        
        if (!$fields) {
            return;
        }

        $columns = $this->getCachedTableColumns();
        $requestedFields = explode(',', $fields);
        $validFields = array_intersect($requestedFields, $columns);

        // Siempre incluir la clave primaria
        if (!in_array($this->getKeyName(), $validFields)) {
            $validFields[] = $this->getKeyName();
        }

        if (!empty($validFields)) {
            $query->select($validFields);
        }
    }

    /**
     * Scope para filtros de fecha inteligentes
     * 
     * Ejemplo:
     * ?date[created_at][from]=2024-01-01
     * ?date[created_at][to]=2024-12-31
     * ?date[created_at][today]=true
     * ?date[created_at][last_days]=7
     */
    public function scopeDateFilter(Builder $query): void
    {
        $dateFilters = request('date');
        
        if (!$dateFilters || !is_array($dateFilters)) {
            return;
        }

        $columns = $this->getCachedTableColumns();

        foreach ($dateFilters as $column => $filters) {
            if (!in_array($column, $columns) || !is_array($filters)) {
                continue;
            }

            foreach ($filters as $type => $value) {
                match ($type) {
                    'from' => $query->whereDate($column, '>=', $value),
                    'to' => $query->whereDate($column, '<=', $value),
                    'today' => $value ? $query->whereDate($column, today()) : null,
                    'yesterday' => $value ? $query->whereDate($column, today()->subDay()) : null,
                    'last_days' => $query->whereDate($column, '>=', today()->subDays((int)$value)),
                    'this_month' => $value ? $query->whereMonth($column, now()->month)
                        ->whereYear($column, now()->year) : null,
                    'last_month' => $value ? $query->whereMonth($column, now()->subMonth()->month)
                        ->whereYear($column, now()->subMonth()->year) : null,
                    default => null
                };
            }
        }
    }

    /**
     * Aplica operadores de filtrado avanzado
     */
    protected function applyFilterOperator(Builder $query, string $column, string $operator, mixed $value): void
    {
        $operator = strtolower($operator);

        if (!isset($this->allowedFilterOperators[$operator])) {
            return;
        }

        match ($operator) {
            'null' => $value ? $query->whereNull($column) : $query->whereNotNull($column),
            'not_null' => $value ? $query->whereNotNull($column) : $query->whereNull($column),
            'in' => $query->whereIn($column, is_array($value) ? $value : explode(',', $value)),
            'not_in' => $query->whereNotIn($column, is_array($value) ? $value : explode(',', $value)),
            'between' => $query->whereBetween($column, is_array($value) ? $value : explode(',', $value)),
            'starts' => $query->where($column, 'LIKE', "{$value}%"),
            'ends' => $query->where($column, 'LIKE', "%{$value}"),
            'like' => $query->where($column, 'LIKE', "%{$value}%"),
            'not_like' => $query->where($column, 'NOT LIKE', "%{$value}%"),
            default => $query->where($column, $this->allowedFilterOperators[$operator], $value)
        };
    }

    /**
     * Aplica ordenamiento por columna de relación
     */
    protected function applySortByRelation(Builder $query, string $column, string $direction): void
    {
        [$relation, $relationColumn] = explode('.', $column, 2);
        $method = Str::camel($relation);

        if (!method_exists($this, $method)) {
            return;
        }

        $relationInstance = $this->$method();
        
        if (!$relationInstance instanceof Relation) {
            return;
        }

        $relatedTable = $relationInstance->getRelated()->getTable();
        $relatedColumns = Schema::getColumnListing($relatedTable);

        if (!in_array($relationColumn, $relatedColumns)) {
            return;
        }

        // Join para ordenar por relación
        $query->join(
            $relatedTable,
            $this->getTable() . '.' . $relationInstance->getForeignKeyName(),
            '=',
            $relatedTable . '.' . $relationInstance->getOwnerKeyName()
        )->orderBy($relatedTable . '.' . $relationColumn, $direction);
    }

    /**
     * Parsea constraints de relaciones (select, limit, etc.)
     * 
     * Ejemplo: "comments:id,text|limit(5)"
     */
    protected function parseRelationConstraints(string $relationPath): array
    {
        if (!str_contains($relationPath, ':') && !str_contains($relationPath, '|')) {
            return [$relationPath, null];
        }

        $parts = explode(':', $relationPath, 2);
        $relation = $parts[0];
        $constraints = isset($parts[1]) ? $parts[1] : null;

        if (!$constraints) {
            return [$relation, null];
        }

        return [$relation, function ($query) use ($constraints) {
            // Procesar select de campos: "id,title,content"
            if (str_contains($constraints, '|')) {
                [$fields, $extras] = explode('|', $constraints, 2);
                $query->select(explode(',', $fields));
                
                // Procesar limit: "limit(5)"
                if (preg_match('/limit\((\d+)\)/', $extras, $matches)) {
                    $query->limit((int)$matches[1]);
                }
            } else {
                $query->select(explode(',', $constraints));
            }
        }];
    }

    /**
     * Valida si una relación existe y es accesible
     */
    protected function isValidRelation(Model $model, string $relation): bool
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

            $return = $reflection->invoke($model);
            
            return $return instanceof Relation;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Valida relaciones anidadas (ej: "author.posts.comments")
     */
    protected function isValidNestedRelation(Model $model, array $segments): bool
    {
        $current = array_shift($segments);
        $method = Str::camel($current);

        if (!method_exists($model, $method)) {
            return false;
        }

        try {
            $reflection = new ReflectionMethod($model, $method);

            if ($reflection->getNumberOfParameters() > 0) {
                return false;
            }

            $return = $reflection->invoke($model);

            if (!$return instanceof Relation) {
                return false;
            }

            return empty($segments)
                ? true
                : $this->isValidNestedRelation($return->getRelated(), $segments);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Obtiene las columnas de la tabla con cache
     */
    protected function getCachedTableColumns(): array
    {
        $cacheKey = 'table_columns_' . $this->getTable();

        return Cache::remember($cacheKey, $this->schemaCacheDuration, function () {
            return Schema::getColumnListing($this->getTable());
        });
    }

    /**
     * Obtiene las columnas de la tabla sin cache (legacy)
     */
    protected function getTableColumns(): array
    {
        return $this->getCachedTableColumns();
    }

    /**
     * Limpia el cache de columnas de la tabla
     */
    public function clearSchemaCache(): void
    {
        $cacheKey = 'table_columns_' . $this->getTable();
        Cache::forget($cacheKey);
    }
}