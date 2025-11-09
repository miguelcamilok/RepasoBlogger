<?php

namespace App\Http\Controllers;

use App\Http\Requests\Category\StoreCategoryRequest;
use App\Http\Requests\Category\UpdateCategoryRequest;
use App\Services\Impl\CategoryServiceImpl;
use App\Traits\CrudTrait;

class CategoryController extends Controller
{
    use CrudTrait;

    public function __construct(CategoryServiceImpl $CategoryService)
    {
        $this->configureCrud(
            service: $CategoryService,
            storeRequest: StoreCategoryRequest::class,
            updateRequest: UpdateCategoryRequest::class,
            resourceName: 'Category'
        );
    }
}
