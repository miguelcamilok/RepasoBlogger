<?php

namespace App\Http\Controllers;

use App\Http\Requests\Message\StoreMessageRequest;
use App\Http\Requests\Message\UpdateMessageRequest;
use App\Services\Impl\MessageServiceImpl;
use App\Traits\CrudTrait;

class MessageController extends Controller
{
    use CrudTrait;

    public function __construct(MessageServiceImpl $MessageService)
    {
        $this->configureCrud(
            service: $MessageService,
            storeRequest: StoreMessageRequest::class,
            updateRequest: UpdateMessageRequest::class,
            resourceName: 'Message'
        );
    }
}
