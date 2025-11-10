<?php

namespace App\Traits;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Exception;

trait CrudTrait
{
    /**
     * Servicio que maneja la lógica de negocio (opcional)
     * 
     * @var object|null
     */
    protected ?object $service = null;

    /**
     * Request de validación para store (opcional)
     * 
     * @var string|null
     */
    protected ?string $storeRequest = null;

    /**
     * Request de validación para update (opcional)
     * 
     * @var string|null
     */
    protected ?string $updateRequest = null;

    /**
     * Nombre del recurso para mensajes y detección del modelo
     * 
     * @var string|null
     */
    protected ?string $resourceName = null;

    /**
     * Instancia del modelo detectado automáticamente
     * 
     * @var string|null
     */
    protected ?string $modelClass = null;

    /**
     * Habilitar aplicación automática de scopes en index
     * 
     * @var bool
     */
    protected bool $applySmartScopes = true;

    /**
     * SOLUCIÓN: Obtiene la clase del modelo, inicializándola si es necesario
     * Este método se llama en cada operación para asegurar que el modelo esté disponible
     */
    protected function getModelClass(): string
    {
        if ($this->modelClass === null) {
            $this->resourceName = $this->resourceName ?? $this->detectResourceName();
            $this->modelClass = $this->detectModel();
        }
        
        return $this->modelClass;
    }

    /**
     * Configura el trait con opciones personalizadas
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
        
        // Forzar inicialización del modelo si se proporciona resourceName
        if ($resourceName !== null) {
            $this->modelClass = $this->detectModel();
        }
    }

    /**
     * Detecta el nombre del recurso desde el nombre del controlador
     */
    protected function detectResourceName(): string
    {
        $className = class_basename(get_class($this));
        return str_replace('Controller', '', $className);
    }

    /**
     * Detecta y retorna la clase del modelo
     */
    protected function detectModel(): string
    {
        $modelName = $this->resourceName ?? $this->detectResourceName();
        $modelClass = "App\\Models\\{$modelName}";

        if (!class_exists($modelClass)) {
            throw new Exception("Modelo {$modelClass} no encontrado");
        }

        return $modelClass;
    }

    /**
     * Lista todos los registros con soporte completo para HasSmartScopes
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $modelClass = $this->getModelClass();
            $query = app($modelClass)->newQuery();
            if ($this->service && method_exists($this->service, 'all')) {
                $data = $this->service->all();
                
                return response()->json([
                    'success' => true,
                    'message' => "{$this->resourceName} obtenidos correctamente",
                    'data' => $data
                ], 200);
            }

            // Si hay servicio configurado con método all, usarlo
            if ($this->service && method_exists($this->service, 'all')) {
                $data = $this->service->all();
                
                return response()->json([
                    'success' => true,
                    'message' => "{$this->resourceName} obtenidos correctamente",
                    'data' => $data
                ], 200);
            }

            // Crear query base

            // Aplicar scopes inteligentes si está habilitado
            if ($this->applySmartScopes && $this->modelHasSmartScopes($modelClass)) {
                $query->included()
                      ->filter()
                      ->sort()
                      ->search()
                      ->fields()
                      ->dateFilter();
            }

            // Obtener datos con paginación inteligente
            $data = $this->modelHasSmartScopes($modelClass) && method_exists($modelClass, 'scopeGetOrPaginate')
                ? $query->getOrPaginate()
                : $query->get();

            return response()->json([
                'success' => true,
                'message' => "{$this->resourceName} obtenidos correctamente",
                'data' => $data
            ], 200);

        } catch (Exception $e) {
            return $this->handleError($e);
        }
    }

    /**
     * Muestra un registro específico con soporte para included
     */
    public function show($id): JsonResponse
    {
        try {
            $modelClass = $this->getModelClass();
            $query = app($modelClass)->newQuery();

            // Aplicar relaciones si están disponibles
            if ($this->applySmartScopes && $this->modelHasSmartScopes($modelClass) && request()->has('included')) {
                $query->included();
            }

            $record = $query->find($id);

            if (!$record) {
                return response()->json([
                    'success' => false,
                    'message' => "{$this->resourceName} no encontrado"
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => "{$this->resourceName} obtenido correctamente",
                'data' => $record
            ], 200);

        } catch (Exception $e) {
            return $this->handleError($e);
        }
    }

    /**
     * Crea un nuevo registro con soporte para relaciones N:M
     */
    public function store(Request $request): JsonResponse
    {
        DB::beginTransaction();
        
        try {
            $modelClass = $this->getModelClass();
            
            // Validar datos
            $validated = $this->validateRequest($request, 'store');

            // Separar relaciones many-to-many ANTES de crear el registro
            $relationData = $this->extractRelationData($validated, $modelClass);

            // Si hay servicio con método create, usarlo
            if ($this->service && method_exists($this->service, 'create')) {
                $record = $this->service->create($validated);
            } else {
                // SOLUCIÓN DEFINITIVA: Crear instancia con conexión y guardar
                // $validated ya NO tiene las relaciones, solo campos de la tabla
                $record = new $modelClass();
                $record->fill($validated);
                $record->save();
            }

            // Sincronizar relaciones many-to-many DESPUÉS de crear el registro
            if (!empty($relationData)) {
                $this->syncRelations($record, $relationData);
            }

            DB::commit();

            // Recargar el registro con las relaciones
            if ($this->applySmartScopes && $this->modelHasSmartScopes($modelClass) && request()->has('included')) {
                $record = app($modelClass)->newQuery()
                    ->included()
                    ->find($record->id);
            } else {
                // Recargar con todas las relaciones many-to-many
                $manyToManyRelations = array_keys($relationData);
                if (!empty($manyToManyRelations)) {
                    $record = $record->load($manyToManyRelations);
                } else {
                    $record = $record->fresh();
                }
            }

            return response()->json([
                'success' => true,
                'message' => "{$this->resourceName} creado correctamente",
                'data' => $record
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);

        } catch (Exception $e) {
            DB::rollBack();
            return $this->handleError($e);
        }
    }

    /**
     * Actualiza un registro existente con soporte para relaciones N:M
     */
    public function update(Request $request, $id): JsonResponse
    {
        DB::beginTransaction();
        
        try {
            $modelClass = $this->getModelClass();
            $record = app($modelClass)->newQuery()->find($id);

            if (!$record) {
                return response()->json([
                    'success' => false,
                    'message' => "{$this->resourceName} no encontrado"
                ], 404);
            }

            // Validar datos
            $validated = $this->validateRequest($request, 'update');

            // Separar relaciones many-to-many de los datos principales
            $relationData = $this->extractRelationData($validated, $modelClass);

            // Si hay servicio con método update, usarlo
            if ($this->service && method_exists($this->service, 'update')) {
                $record = $this->service->update($record, $validated);
            } else {
                // Actualizar registro directamente
                $record->update($validated);
            }

            // Sincronizar relaciones many-to-many
            if (!empty($relationData)) {
                $this->syncRelations($record, $relationData);
            }

            DB::commit();

            // Recargar el registro con las relaciones
            if ($this->applySmartScopes && $this->modelHasSmartScopes($modelClass) && request()->has('included')) {
                $record = app($modelClass)->newQuery()
                    ->included()
                    ->find($record->id);
            } else {
                // Recargar con todas las relaciones many-to-many
                $manyToManyRelations = array_keys($relationData);
                if (!empty($manyToManyRelations)) {
                    $record = $record->load($manyToManyRelations);
                } else {
                    $record = $record->fresh();
                }
            }

            return response()->json([
                'success' => true,
                'message' => "{$this->resourceName} actualizado correctamente",
                'data' => $record
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);

        } catch (Exception $e) {
            DB::rollBack();
            return $this->handleError($e);
        }
    }

    /**
     * Elimina un registro
     */
    public function destroy($id): JsonResponse
    {
        DB::beginTransaction();
        
        try {
            $modelClass = $this->getModelClass();
            $record = app($modelClass)->newQuery()->find($id);

            if (!$record) {
                return response()->json([
                    'success' => false,
                    'message' => "{$this->resourceName} no encontrado"
                ], 404);
            }

            // Detach todas las relaciones many-to-many antes de eliminar
            $manyToManyRelations = $this->getManyToManyRelations($record);
            foreach ($manyToManyRelations as $relation) {
                try {
                    $record->$relation()->detach();
                } catch (\Exception $e) {
                    Log::warning("No se pudo hacer detach de la relación {$relation}: " . $e->getMessage());
                }
            }

            // Si hay servicio con método delete, usarlo
            if ($this->service && method_exists($this->service, 'delete')) {
                $this->service->delete($record);
            } else {
                $record->delete();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "{$this->resourceName} eliminado correctamente"
            ], 200);

        } catch (Exception $e) {
            DB::rollBack();
            return $this->handleError($e);
        }
    }

    /**
     * Verifica si el modelo tiene el trait HasSmartScopes
     */
    protected function modelHasSmartScopes(string $modelClass): bool
    {
        $traits = class_uses_recursive($modelClass);
        
        return in_array('App\Traits\HasSmartScopes', $traits) ||
               in_array('App\\Traits\\HasSmartScopes', $traits);
    }

    /**
     * Extrae y separa los datos de relaciones many-to-many
     */
    protected function extractRelationData(array &$validated, string $modelClass): array
    {
        $modelInstance = new $modelClass;
        $relationData = [];
        
        // Detectar relaciones many-to-many del modelo
        $manyToManyRelations = $this->getManyToManyRelations($modelInstance);
        
        // Log para debug
        Log::info('Relaciones N:M detectadas: ' . implode(', ', $manyToManyRelations));
        Log::info('Datos validados antes de extraer relaciones: ' . json_encode(array_keys($validated)));
        
        foreach ($manyToManyRelations as $relation) {
            // Verificar si los datos contienen esta relación
            if (isset($validated[$relation])) {
                $relationData[$relation] = $validated[$relation];
                unset($validated[$relation]);
                Log::info("Relación '{$relation}' extraída correctamente");
            }
        }
        
        Log::info('Datos validados después de extraer relaciones: ' . json_encode(array_keys($validated)));
        
        return $relationData;
    }

    /**
     * Sincroniza las relaciones many-to-many
     */
    protected function syncRelations(Model $record, array $relationData): void
    {
        foreach ($relationData as $relation => $ids) {
            // Verificar que el método de relación existe
            if (!method_exists($record, $relation)) {
                Log::warning("El método de relación '{$relation}' no existe en el modelo " . get_class($record));
                continue;
            }

            // Normalizar los IDs
            $normalizedIds = $this->normalizeRelationIds($ids);

            if (empty($normalizedIds)) {
                Log::warning("No se proporcionaron IDs válidos para la relación '{$relation}'");
                continue;
            }

            // Sincronizar la relación
            try {
                $record->$relation()->sync($normalizedIds);
                Log::info("Relación '{$relation}' sincronizada correctamente con IDs: " . implode(',', $normalizedIds));
            } catch (\Exception $e) {
                Log::error("Error al sincronizar la relación '{$relation}': " . $e->getMessage());
                throw $e;
            }
        }
    }

    /**
     * Normaliza los IDs de relación a un array
     */
    protected function normalizeRelationIds(mixed $ids): array
    {
        // Si ya es un array, retornarlo filtrado
        if (is_array($ids)) {
            return array_filter($ids, fn($id) => is_numeric($id));
        }

        // Si es un string con comas, convertir a array
        if (is_string($ids) && str_contains($ids, ',')) {
            return array_map('intval', array_filter(explode(',', $ids), 'is_numeric'));
        }

        // Si es un número o string numérico, convertir a array
        if (is_numeric($ids)) {
            return [(int)$ids];
        }

        return [];
    }

    /**
     * Obtiene las relaciones many-to-many definidas en el modelo
     * Método mejorado que inspecciona el código del método sin invocarlo
     */
    protected function getManyToManyRelations(Model $model): array
    {
        $relations = [];
        $methods = get_class_methods($model);
        
        foreach ($methods as $method) {
            // Ignorar métodos mágicos, getters, y métodos de Eloquent/Laravel
            if (str_starts_with($method, '__') || 
                str_starts_with($method, 'get') ||
                str_starts_with($method, 'set') ||
                str_starts_with($method, 'scope') ||
                in_array($method, ['notifications', 'toArray', 'toJson', 'save', 'delete', 'update'])) {
                continue;
            }

            try {
                $reflection = new \ReflectionMethod($model, $method);
                
                // Verificar que es un método público y no requiere parámetros
                if (!$reflection->isPublic() || 
                    $reflection->getNumberOfRequiredParameters() > 0 ||
                    $reflection->isStatic()) {
                    continue;
                }

                // Obtener el código fuente del método
                $filename = $reflection->getFileName();
                $start_line = $reflection->getStartLine();
                $end_line = $reflection->getEndLine();
                
                if ($filename && $start_line && $end_line) {
                    $length = $end_line - $start_line;
                    $source = file($filename);
                    $body = implode("", array_slice($source, $start_line, $length));
                    
                    // Buscar si contiene 'belongsToMany'
                    if (stripos($body, 'belongsToMany') !== false) {
                        $relations[] = $method;
                        Log::info("Relación N:M detectada: {$method}");
                    }
                }
            } catch (\Throwable $e) {
                // Log del error para debug
                Log::warning("Error al inspeccionar método {$method}: " . $e->getMessage());
                continue;
            }
        }
        
        return $relations;
    }

    /**
     * Valida la petición usando FormRequest personalizado o reglas dinámicas
     */
    protected function validateRequest(Request $request, string $action = 'store'): array
    {
        // Si hay FormRequest configurado, usarlo
        $requestClass = $action === 'update' 
            ? ($this->updateRequest ?? $this->storeRequest)
            : $this->storeRequest;

        if ($requestClass && class_exists($requestClass)) {
            return app($requestClass)->validated();
        }

        // Generar reglas dinámicamente
        $rules = $this->generateValidationRules($action);

        // Validar con las reglas generadas
        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            throw new \Illuminate\Validation\ValidationException($validator);
        }

        return $validator->validated();
    }

    /**
     * Genera reglas de validación automáticamente desde el modelo
     */
    protected function generateValidationRules(string $action = 'store'): array
    {
        $rules = [];
        $modelClass = $this->getModelClass();
        $modelInstance = new $modelClass;
        $table = $modelInstance->getTable();

        // Obtener campos fillable o columnas de la tabla
        $fields = !empty($modelInstance->getFillable()) 
            ? $modelInstance->getFillable()
            : Schema::getColumnListing($table);

        // Filtrar campos del sistema
        $excludeFields = ['id', 'created_at', 'updated_at', 'deleted_at'];
        $fields = array_diff($fields, $excludeFields);

        foreach ($fields as $field) {
            if (!Schema::hasColumn($table, $field)) {
                continue;
            }
            
            $columnType = Schema::getColumnType($table, $field);
            $rule = $this->getRuleForField($field, $columnType, $table, $action);
            
            if ($rule) {
                $rules[$field] = $rule;
            }
        }

        // Agregar reglas para relaciones many-to-many
        $manyToManyRelations = $this->getManyToManyRelations($modelInstance);
        foreach ($manyToManyRelations as $relation) {
            // Las relaciones N:M son opcionales
            $rules[$relation] = 'sometimes|nullable';
        }

        return $rules;
    }

    /**
     * Genera la regla de validación para un campo específico
     */
    protected function getRuleForField(string $field, string $type, string $table, string $action): string
    {
        $baseRule = $action === 'update' ? 'sometimes' : 'required';
        
        // Campos de relación (foreign keys)
        if (str_ends_with($field, '_id')) {
            $relatedTable = str_replace('_id', 's', $field);
            if (Schema::hasTable($relatedTable)) {
                return "{$baseRule}|integer|exists:{$relatedTable},id";
            }
            return "{$baseRule}|integer";
        }

        // Campos booleanos
        if (str_starts_with($field, 'is_') || $type === 'boolean') {
            return "{$baseRule}|boolean";
        }

        // Campos de email
        if (str_contains($field, 'email')) {
            return "{$baseRule}|email|max:255";
        }

        // Según el tipo de columna
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

    /**
     * Maneja errores de forma consistente
     */
    protected function handleError(Exception $e): JsonResponse
    {
        Log::error('CrudTrait Error: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);

        return response()->json([
            'error' => true,
            'message' => config('app.debug') ? $e->getMessage() : 'Ha ocurrido un error en el servidor',
            'trace' => config('app.debug') ? $e->getTraceAsString() : null
        ], 500);
    }
}

/**
 * =============================================================================
 * INSTRUCCIONES DE USO
 * =============================================================================
 * 
 * En tu controlador, simplemente usa el trait SIN constructor:
 * 
 * use App\Traits\CrudTrait;
 * 
 * class PublicationController extends Controller
 * {
 *     use CrudTrait;
 *     
 *     // ¡NO necesitas agregar nada más!
 *     // El trait detecta automáticamente el modelo "Publication"
 * }
 * 
 * Si quieres personalizar, puedes agregar un constructor:
 * 
 * class PublicationController extends Controller
 * {
 *     use CrudTrait;
 *     
 *     public function __construct()
 *     {
 *         // Opcional: configurar manualmente
 *         $this->configureCrud(
 *             resourceName: 'Publication',
 *             applySmartScopes: true
 *         );
 *     }
 * }
 * 
 * =============================================================================
 */