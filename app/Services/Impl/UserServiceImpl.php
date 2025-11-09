<?php

namespace App\Services\Impl;

use App\Models\User;
use App\Services\UserService;

class UserServiceImpl implements UserService
{
    function all()
    {
        return User::included()->filter()->sort()->getOrPaginate();
    }

    function show($id)
    {
        return User::with(['profiles'])->find($id);
    }

    function create(array $data)
    {
        return User::create($data);
    }

    function update($id, array $data)
    {
        $User = User::find($id);
        if (!$User) {
            return null;
        }

        $User->update($data);
        return $User;
    }

    function delete($id)
    {
        $User = User::find($id);
        if (!$User) {
            return false;
        }

        return $User->delete();
    }
}