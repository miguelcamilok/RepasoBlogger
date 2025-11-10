<?php

namespace App\Http\Controllers;

use App\Http\Requests\Role\StoreRoleRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use App\Services\Impl\RoleServiceImpl;
use App\Traits\CrudTrait;

class RoleController extends Controller
{
    use CrudTrait;
}
