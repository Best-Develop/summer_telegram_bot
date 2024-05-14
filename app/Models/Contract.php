<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Contract extends Model
{
    use HasFactory;
    public function summer_action_gift_player()
    {
        return $this->belongsTo(SummerActionGiftPlayer::class, 'id', 'contract_id');
    }
    public function product_deliveries()
    {
        return $this->hasMany(ProductDelivery::class, "contract_id", "id");
    }
}
