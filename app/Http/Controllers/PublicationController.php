<?php

namespace App\Http\Controllers;

use App\Http\Requests\Publication\StorePublicationRequest;
use App\Http\Requests\Publication\UpdatePublicationRequest;
use App\Services\Impl\PublicationServiceImpl;
use App\Traits\CrudTrait;

class PublicationController extends Controller
{
    use CrudTrait;

    public function __construct(PublicationServiceImpl $PublicationService)
    {
        $this->configureCrud(
            service: $PublicationService,
            storeRequest: StorePublicationRequest::class,
            updateRequest: UpdatePublicationRequest::class,
            resourceName: 'Publication'
        );
    }
}
