<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SummerActionGiftOrganization extends Model
{
    use HasFactory;
    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
    public function summer_action_gift()
    {
        return $this->belongsTo(SummerActionGift::class);
    }
}
