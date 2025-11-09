<?php

namespace App\Services\Impl;

use App\Models\Category;
use App\Services\CategoryService;

class CategoryServiceImpl implements CategoryService
{
    function all()
    {
        return Category::included()->filter()->sort()->getOrPaginate();
    }

    function show($id)
    {
        return Category::with(['publication'])->find($id);
    }

    function create(array $data)
    {
        return Category::create($data);
    }

    function update($id, array $data)
    {
        $Category = Category::find($id);
        if (!$Category) {
            return null;
        }

        $Category->update($data);
        return $Category;
    }

    function delete($id)
    {
        $Category = Category::find($id);
        if (!$Category) {
            return false;
        }

        return $Category->delete();
    }
}