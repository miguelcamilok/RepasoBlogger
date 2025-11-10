<?php

namespace App\Http\Controllers;

use App\Http\Requests\Category\StoreCategoryRequest;
use App\Http\Requests\Category\UpdateCategoryRequest;
use App\Services\Impl\CategoryServiceImpl;
use App\Traits\CrudTrait;

class CategoryController extends Controller
{
    use CrudTrait;
}
