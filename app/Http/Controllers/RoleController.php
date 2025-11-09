<?php

namespace App\Http\Controllers;

use App\Http\Requests\Role\StoreRoleRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use App\Services\Impl\RoleServiceImpl;
use App\Traits\CrudTrait;

class RoleController extends Controller
{
    use CrudTrait;

    public function __construct(RoleServiceImpl $RoleService)
    {
        $this->configureCrud(
            service: $RoleService,
            storeRequest: StoreRoleRequest::class,
            updateRequest: UpdateRoleRequest::class,
            resourceName: 'Role'
        );
    }
}
