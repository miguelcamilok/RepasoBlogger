<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

trait HasSmartBasic
{
    public function scopeIncluded(Builder $query): void
    {
        $included = request('included');
        if (!$included) return;

        $relations = array_map('trim', explode(',', $included));
        $query->with($relations);
    }

    public function scopeFilter(Builder $query): void
    {
        $filters = request('filter');
        if (!is_array($filters) || empty($filters)) return;

        $columns = Schema::getColumnListing($this->getTable());

        foreach ($filters as $column => $value) {
            if (!in_array($column, $columns, true)) continue;

            $query->where($column, 'LIKE', "%{$value}%");
        }
    }

    public function scopeSort(Builder $query): void
    {
        $sortParam = request('sort');
        if (!$sortParam) return;

        $sorts = array_map('trim', explode(',', $sortParam));
        $columns = Schema::getColumnListing($this->getTable());

        foreach ($sorts as $field) {
            $direction = str_starts_with($field, '-') ? 'desc' : 'asc';
            $column = ltrim($field, '-');

            if (in_array($column, $columns, true)) {
                $query->orderBy($column, $direction);
            }
        }
    }
    
    public function scopePaginateIfNeeded(Builder $query)
    {
        return request()->has('perPage')
            ? $query->paginate((int) request('perPage', 15))
            : $query->get();
    }
}
