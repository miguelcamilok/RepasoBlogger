<?php

namespace App\Http\Controllers;

use App\Http\Requests\Notification\StoreNotificationRequest;
use App\Http\Requests\Notification\UpdateNotificationRequest;
use App\Services\Impl\NotificationServiceImpl;
use App\Traits\CrudTrait;

class NotificationController extends Controller
{
    use CrudTrait;

    public function __construct(NotificationServiceImpl $NotificationService)
    {
        $this->configureCrud(
            service: $NotificationService,
            storeRequest: StoreNotificationRequest::class,
            updateRequest: UpdateNotificationRequest::class,
            resourceName: 'Notification'
        );
    }
}
