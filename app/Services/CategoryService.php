<?php

namespace App\Services;

interface CategoryService
{
    function all();
    function show($id);
    function create(array $data);
    function update($id, array $data);
    function delete($id);
}
