<?php

namespace App\Models;
use App\Traits\HasSmartScopes;
use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    use HasSmartScopes;

    protected $fillable = ['id', 'content', 'is_read', 'sendes_profile_id', 'receive_profile_id'];

    public function profile(){
        return $this->belongsTo(Profile::class);
    }
}
