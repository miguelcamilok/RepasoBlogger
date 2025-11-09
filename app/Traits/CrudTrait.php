<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

trait CrudTrait
{
    /**
     * Servicio que maneja la lógica de negocio
     * 
     * @var object
     */
    protected object $service;

    /**
     * Request de validación para store
     * 
     * @var string|null
     */
    protected ?string $storeRequest = null;

    /**
     * Request de validación para update
     * 
     * @var string|null
     */
    protected ?string $updateRequest = null;

    /**
     * Nombre del recurso para mensajes
     * 
     * @var string
     */
    protected string $resourceName = 'Recurso';

    /**
     * Configura el trait con el servicio y requests
     */
    protected function configureCrud(
        object $service,
        ?string $storeRequest = null,
        ?string $updateRequest = null,
        string $resourceName = 'Recurso'
    ): void {
        $this->service = $service;
        $this->storeRequest = $storeRequest;
        $this->updateRequest = $updateRequest;
        $this->resourceName = $resourceName;
    }

    /**
     * Lista todos los registros con paginación, filtros y relaciones
     */
    public function index(): JsonResponse
    {
        try {
            $data = $this->service->all();

            return response()->json([
                'success' => true,
                'message' => "{$this->resourceName} obtenidos correctamente",
                'data' => $data
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener registros: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Muestra un registro específico con sus relaciones
     */
    public function show($id): JsonResponse
    {
        try {
            $record = $this->service->show($id);

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
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener el registro: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Crea un nuevo registro manejando relaciones
     */
    public function store(): JsonResponse
    {
        try {
            // Validar con FormRequest si está definido
            $validated = $this->storeRequest 
                ? app($this->storeRequest)->validated()
                : request()->all();

            $record = $this->service->create($validated);

            return response()->json([
                'success' => true,
                'message' => "{$this->resourceName} creado correctamente",
                'data' => $record
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear el registro: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualiza un registro existente sincronizando relaciones
     */
    public function update($id): JsonResponse
    {
        try {
            // Validar con FormRequest si está definido
            $requestClass = $this->updateRequest ?: $this->storeRequest;
            $validated = $requestClass 
                ? app($requestClass)->validated()
                : request()->all();

            $record = $this->service->update($id, $validated);

            if (!$record) {
                return response()->json([
                    'success' => false,
                    'message' => "{$this->resourceName} no encontrado"
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => "{$this->resourceName} actualizado correctamente",
                'data' => $record
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el registro: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Elimina un registro (soft delete si está disponible)
     */
    public function destroy($id): JsonResponse
    {
        try {
            $deleted = $this->service->delete($id);

            if (!$deleted) {
                return response()->json([
                    'success' => false,
                    'message' => "{$this->resourceName} no encontrado"
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => "{$this->resourceName} eliminado correctamente"
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar el registro: ' . $e->getMessage()
            ], 500);
        }
    }
}