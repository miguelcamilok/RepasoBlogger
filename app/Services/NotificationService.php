<?php

namespace App\Services;

interface NotificationService
{
    function all();
    function show($id);
    function create(array $data);
    function update($id, array $data);
    function delete($id);
}
