<?php

namespace App\Models;
use App\Traits\HasSmartScopes;
use Illuminate\Database\Eloquent\Model;

class Publication extends Model
{
    use HasSmartScopes;

    protected $fillable = ['id', 'title', 'type', 'severity', 'location', 'description', 'url_imagen', 'date', 'profile_id'];

    public function categories(){
        return $this->belongsToMany(Category::class);
    }

    public function notifications(){
        return $this->hasMany(Notification::class);
    }
}
