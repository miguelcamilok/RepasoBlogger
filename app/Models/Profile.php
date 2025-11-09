<?php

namespace App\Models;
use App\Traits\HasSmartScopes;
use GuzzleHttp\Psr7\Message;
use Illuminate\Database\Eloquent\Model;

class Profile extends Model
{
    use HasSmartScopes;

    protected $fillable = ['id', 'photo', 'vereda', 'phone', 'user_id', 'role_id'];

    public function messages(){
        return $this->hasMany(Message::class);
    }

    public function user(){
        return $this->belongsTo(User::class);
    }

    public function role(){
        return $this->belongsTo(Role::class);
    }
}
