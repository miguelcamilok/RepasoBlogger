<?php

declare(strict_types=1);

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
trait CrudTrait
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