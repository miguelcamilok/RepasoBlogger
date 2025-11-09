<?php

namespace App\Models;
use App\Traits\HasSmartScopes;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use HasSmartScopes;

    protected $fillable = ['id', 'name_role'];
    
    public function profiles(){
        return $this->hasMany(Profile::class);
    }
}
