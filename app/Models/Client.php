<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    use HasFactory;
    public function scopeFindByPhoneNumber($query, $phoneNumber)
    {
        // Remove non-numeric characters from the phone number
        $phoneNumber = preg_replace('/[^0-9]/', '', $phoneNumber);

        // If the number is in the shorter format, add the country code
        if (strlen($phoneNumber) === 9) {
            $phoneNumber = '998' . $phoneNumber;
        }

        // Format the phone number
        $formattedPhoneNumber = '+' . substr($phoneNumber, 0, 3) . ' (' . substr($phoneNumber, 3, 2) . ') ' . substr($phoneNumber, 5, 3) . '-' . substr($phoneNumber, 8, 2) . '-' . substr($phoneNumber, 10);

        return $query->where(function ($subQuery) use ($formattedPhoneNumber, $phoneNumber) {
            $subQuery->where('main_phone_number', $formattedPhoneNumber)
                ->orWhere('main_phone_number', '+' . $phoneNumber);
        })
            ->where("inps", "!=", NULL)
            ->whereRaw('LENGTH(inps) = 14')
            ->where("main_phone_number", "!=", NULL)
            ->orderBy("id", "DESC");
    }
}
