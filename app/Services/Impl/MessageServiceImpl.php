<?php

namespace App\Services\Impl;

use App\Models\Message;
use App\Services\MessageService;

class MessageServiceImpl implements MessageService
{
    function all()
    {
        return Message::included()->filter()->sort()->getOrPaginate();
    }

    function show($id)
    {
        return Message::with(['profile'])->find($id);
    }

    function create(array $data)
    {
        return Message::create($data);
    }

    function update($id, array $data)
    {
        $Message = Message::find($id);
        if (!$Message) {
            return null;
        }

        $Message->update($data);
        return $Message;
    }

    function delete($id)
    {
        $Message = Message::find($id);
        if (!$Message) {
            return false;
        }

        return $Message->delete();
    }
}