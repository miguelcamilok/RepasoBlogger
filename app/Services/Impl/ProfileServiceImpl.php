<?php

namespace App\Services\Impl;

use App\Models\Profile;
use App\Services\ProfileService;

class ProfileServiceImpl implements ProfileService
{
    function all()
    {
        return Profile::included()->filter()->sort()->getOrPaginate();
    }

    function show($id)
    {
        return Profile::with(['messages', 'user', 'role'])->find($id);
    }

    function create(array $data)
    {
        return Profile::create($data);
    }

    function update($id, array $data)
    {
        $Profile = Profile::find($id);
        if (!$Profile) {
            return null;
        }

        $Profile->update($data);
        return $Profile;
    }

    function delete($id)
    {
        $Profile = Profile::find($id);
        if (!$Profile) {
            return false;
        }

        return $Profile->delete();
    }
}