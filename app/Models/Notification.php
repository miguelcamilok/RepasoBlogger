<?php

namespace App\Models;
use App\Traits\HasSmartScopes;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    use HasSmartScopes;

    protected $fillable = ['id', 'event_notification', 'publication_id'];

    public function publication(){
        return $this->belongsTo(Publication::class);
    }
}
