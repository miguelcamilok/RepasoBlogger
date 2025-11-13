<?php

namespace App\Traits;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

trait CrudBasic
{
    public function index()
    {
        try {
            $m = $this->model();
            $q = $m->newQuery();

            foreach (get_class_methods($m) as $mtd)
                if (Str::startsWith($mtd, 'scope')) {
                    $s = Str::camel(Str::replaceFirst('scope', '', $mtd));
                    if (request()->has($s)) $q->$s(request($s));
                }

            if ($inc = $this->getIncludes()) $q->with($inc);

            // ✅ Solo aplica paginate si perPage está presente
            return response()->json(
                request()->has('perPage')
                    ? $q->paginate(request('perPage'))
                    : $q->get()
            );
        } catch (\Throwable $e) {
            return $this->error('Error al listar', $e);
        }
    }

    public function show($id)
    {
        $q = $this->model()::query();
        if ($inc = $this->getIncludes()) $q->with($inc);
        $r = $q->find($id);
        return $r ? response()->json($r) : response()->json(['error' => 'No encontrado'], 404);
    }

    public function store(Request $r)
    {
        DB::beginTransaction();
        try {
            [$main, $pivots] = $this->splitPivotData($this->validateData($r));
            $rec = $this->model()::create($main);
            $this->syncPivots($rec, $pivots);
            DB::commit();
            return response()->json(['message' => 'Creado correctamente', 'data' => $this->loadIncludes($rec)], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->error('Error al crear', $e);
        }
    }

    public function update(Request $r, $id)
    {
        $rec = $this->model()::find($id);
        if (!$rec) return response()->json(['error' => 'No encontrado'], 404);

        DB::beginTransaction();
        try {
            [$main, $pivots] = $this->splitPivotData($this->validateData($r, true));
            $rec->update($main);
            $this->syncPivots($rec, $pivots);
            DB::commit();
            return response()->json(['message' => 'Actualizado correctamente', 'data' => $this->loadIncludes($rec)]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->error('Error al actualizar', $e);
        }
    }

    public function destroy($id)
    {
        $rec = $this->model()::find($id);
        if (!$rec) return response()->json(['error' => 'No encontrado'], 404);

        DB::beginTransaction();
        try {
            $this->detachPivots($rec);
            $rec->delete();
            DB::commit();
            return response()->json(['message' => 'Eliminado correctamente']);
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->error('Error al eliminar', $e);
        }
    }

    private function model()
    {
        $c = "App\\Models\\" . str_replace('Controller', '', class_basename(static::class));
        if (!class_exists($c)) throw new \Exception("Modelo {$c} no encontrado");
        return new $c;
    }

    private function validateData(Request $r, bool $update = false): array
    {
        $t = $this->model()->getTable();
        $cols = Schema::getColumnListing($t);
        $rules = collect($cols)
            ->reject(fn($c) => in_array($c, ['id', 'created_at', 'updated_at', 'deleted_at']))
            ->mapWithKeys(fn($c) => [$c => $this->inferRule($c, $update)])
            ->toArray();

        foreach ($r->all() as $k => $v)
            if (is_array($v) || is_numeric($v)) $rules[$k] = 'sometimes';

        return Validator::make($r->all(), $rules)->validate();
    }

    private function inferRule(string $c, bool $u): string
    {
        $b = $u ? 'sometimes' : 'required';
        return match (true) {
            Str::endsWith($c, '_id') => "$b|integer|exists:" . Str::plural(str_replace('_id', '', $c)) . ",id",
            Str::startsWith($c, 'is_') => "$b|boolean",
            Str::contains($c, 'email') => "$b|email|max:255",
            Str::contains($c, 'date') => "$b|date",
            default => "$b|string|max:255"
        };
    }

    private function splitPivotData(array $d): array
    {
        $main = $pivot = [];
        foreach ($d as $k => $v) (is_array($v) || Str::endsWith($k, 's')) ? $pivot[$k] = (array)$v : $main[$k] = $v;
        return [$main, $pivot];
    }

    private function syncPivots($rec, array $p)
    {
        foreach ($p as $rel => $ids) try {
            if (method_exists($rec, $rel)) $rec->$rel()->sync($ids);
            else {
                $t = $rec->getTable();
                $pv = collect([
                    Str::snake(Str::singular($rel)) . "_{$t}",
                    "{$t}_" . Str::snake(Str::singular($rel))
                ])->first(fn($tb) => Schema::hasTable($tb));
                if ($pv)
                    foreach ($ids as $id)
                        DB::table($pv)->insert([
                            Str::singular($t) . '_id' => $rec->id,
                            Str::singular($rel) . '_id' => $id
                        ]);
            }
        } catch (\Throwable) {
        }
    }

    private function detachPivots($r)
    {
        foreach (get_class_methods($r) as $m) try {
            $rel = $r->$m();
            if (is_object($rel) && method_exists($rel, 'detach')) $rel->detach();
        } catch (\Throwable) {
        }
    }

    private function loadIncludes($r)
    {
        return $this->getIncludes() ? $r->load($this->getIncludes()) : $r->fresh();
    }

    private function getIncludes(): array
    {
        return array_filter(explode(',', request('included', request('include', ''))));
    }

    private function error(string $m, \Throwable $e)
    {
        return response()->json(['error' => $m, 'details' => $e->getMessage()], 500);
    }
}
