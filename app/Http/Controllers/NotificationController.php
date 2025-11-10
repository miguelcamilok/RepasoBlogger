<?php

namespace App\Http\Controllers;

use App\Http\Requests\Notification\StoreNotificationRequest;
use App\Http\Requests\Notification\UpdateNotificationRequest;
use App\Services\Impl\NotificationServiceImpl;
use App\Traits\CrudTrait;

class NotificationController extends Controller
{
    use CrudTrait;
}
