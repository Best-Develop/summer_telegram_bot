<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SummerActionGiftPlayer extends Model
{
    use HasFactory;
    protected $guarded = [];
    public function contract()
    {
        return $this->belongsTo(Contract::class, 'contract_id', 'id');
    }
    public function gift()
    {
        return $this->belongsTo(SummerActionGift::class, 'summer_action_gift_id', 'id');
    }
    public function organization()
    {
        return $this->belongsTo(Organization::class, 'organization_id', 'id');
    }
}
