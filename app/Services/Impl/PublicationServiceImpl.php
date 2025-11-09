<?php

namespace App\Services\Impl;

use App\Models\Publication;
use App\Services\PublicationService;
use Illuminate\Support\Facades\DB;

class PublicationServiceImpl implements PublicationService
{
    function all()
    {
        return Publication::included()->filter()->sort()->getOrPaginate();
    }

    function show($id)
    {
        return Publication::with(['categories', 'profile'])->find($id);
    }

    function create(array $data)
    {
        DB::beginTransaction();
        
        try {
            // Separar las categorías del resto de datos
            $categories = $data['categories'] ?? [];
            unset($data['categories']);
            
            // Crear la publicación
            $publication = Publication::create($data);
            
            // Sincronizar categorías en la tabla pivote
            if (!empty($categories)) {
                $publication->categories()->attach($categories);
            }
            
            DB::commit();
            
            // Retornar con las relaciones cargadas
            return $publication->load('categories');
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    function update($id, array $data)
    {
        DB::beginTransaction();
        
        try {
            $publication = Publication::find($id);
            
            if (!$publication) {
                return null;
            }
            
            // Separar las categorías del resto de datos
            $categories = $data['categories'] ?? null;
            unset($data['categories']);
            
            // Actualizar la publicación
            $publication->update($data);
            
            // Sincronizar categorías (sync reemplaza todas las relaciones)
            if ($categories !== null) {
                $publication->categories()->sync($categories);
            }
            
            DB::commit();
            
            // Retornar con las relaciones cargadas
            return $publication->load('categories');
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    function delete($id)
    {
        $publication = Publication::find($id);
        
        if (!$publication) {
            return false;
        }
        
        // Desasociar categorías antes de eliminar
        $publication->categories()->detach();
        
        return $publication->delete();
    }
}