<?php

namespace App\Models;
use App\Traits\HasSmartScopes;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasSmartScopes;

    protected $fillable = ['id', 'firstname', 'lastname', 'email', 'email_verification', 'location', 'password'];

    public function profiles(){
        return $this->hasMany(Profile::class);
    }
}
