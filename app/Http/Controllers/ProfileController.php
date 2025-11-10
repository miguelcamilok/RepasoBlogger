<?php

namespace App\Http\Controllers;

use App\Http\Requests\Profile\StoreProfileRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Services\Impl\ProfileServiceImpl;
use App\Traits\CrudTrait;

class ProfileController extends Controller
{
    use CrudTrait;
}
