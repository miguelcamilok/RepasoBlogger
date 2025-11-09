<?php

namespace App\Models;

use App\Traits\HasSmartScopes;
use Illuminate\Database\Eloquent\Model;


class Category extends Model
{
    use HasSmartScopes;

    protected $fillable = ['id', 'type_publication'];

    public function publication()
    {
        return $this->belongsToMany(Publication::class);
    }
}
