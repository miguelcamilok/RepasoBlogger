<?php

namespace App\Services\Impl;

use App\Models\Notification;
use App\Services\NotificationService;

class NotificationServiceImpl implements NotificationService
{
    function all()
    {
        return Notification::included()->filter()->sort()->getOrPaginate();
    }

    function show($id)
    {
        return Notification::with(['publication'])->find($id);
    }

    function create(array $data)
    {
        return Notification::create($data);
    }

    function update($id, array $data)
    {
        $Notification = Notification::find($id);
        if (!$Notification) {
            return null;
        }

        $Notification->update($data);
        return $Notification;
    }

    function delete($id)
    {
        $Notification = Notification::find($id);
        if (!$Notification) {
            return false;
        }

        return $Notification->delete();
    }
}