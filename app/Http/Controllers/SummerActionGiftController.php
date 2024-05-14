<?php

namespace App\Http\Controllers;

use DefStudio\Telegraph\Handlers\WebhookHandler;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Interfaces\SummerActionGiftInterface;

class SummerActionGiftController extends WebhookHandler
{
    public function __construct(
        private readonly SummerActionGiftInterface $summerActionGiftRepository
    ) {
    }

    public function mainMenu(Request $request)
    {
        return $this->summerActionGiftRepository->mainMenu($request);
    }
}
