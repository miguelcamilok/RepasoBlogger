<?php

namespace App\Services\Impl;

use App\Models\Role;
use App\Services\RoleService;

class RoleServiceImpl implements RoleService
{
    function all()
    {
        return Role::included()->filter()->sort()->getOrPaginate();
    }

    function show($id)
    {
        return Role::with(['profiles'])->find($id);
    }

    function create(array $data)
    {
        return Role::create($data);
    }

    function update($id, array $data)
    {
        $Role = Role::find($id);
        if (!$Role) {
            return null;
        }

        $Role->update($data);
        return $Role;
    }

    function delete($id)
    {
        $Role = Role::find($id);
        if (!$Role) {
            return false;
        }

        return $Role->delete();
    }
}