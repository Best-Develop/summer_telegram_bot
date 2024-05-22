<?php

declare(strict_types=1);

namespace App\Http\Telegraph;

use App\Models\Bonus;
use App\Models\ProductDelivery;
use PHPQRCode\QRcode;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Organization;
use App\Models\SummerActionGift;
use App\Models\SummerActionGiftOrganization;
use Illuminate\Support\Facades\Log;
use App\Models\SummerActionGiftUser;
use App\Models\SummerActionGiftPlayer;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use DefStudio\Telegraph\Keyboard\ReplyButton;
use DefStudio\Telegraph\Keyboard\ReplyKeyboard;
use DefStudio\Telegraph\Handlers\WebhookHandler;
use Illuminate\Support\Facades\Cache;

class SummerActionBotHandler extends WebhookHandler
{
    protected $name = 'phone';

    /**
     * @var array Command Aliases
     */
    protected $aliases = ['phonenumber'];

    /**
     * @var string Command Description
     */
    protected $description = 'Get phone number.';
    public function start()
    {
        $data = $this->message->toArray();
        $this->reply(
            'Assalom alekum xurmatli ' . (
                ($data['from']['first_name'] . ' ' . $data['from']['last_name']) == ' ' ? 'mijoz' : $data['from']['first_name'] . ' ' . $data['from']['last_name'])
                . ".\r\nIshonchli mijoz aksiyasi telegram botiga xush kelibsiz."
        );
        $fromId =  $data['from']['id'];

        $profile = Cache::remember("profile_" . $fromId, 300, function () use ($fromId) {
            return SummerActionGiftUser::where('profile_id', $fromId)->first();
        });

        if (!$profile) {
            $this->chat->message('Telefon raqamingizni yuboring')
                ->replyKeyboard(ReplyKeyboard::make()
                    ->row([
                        ReplyButton::make('Telefon raqamingizni yuboring')->requestContact(),
                    ])
                    ->oneTime())
                ->send();
        } else {
            $clientId = $profile['client_id'];
            $client = Cache::remember("cached_client_" . $clientId, 300, function () use ($clientId) {
                return Client::select('id', 'fio')
                    ->where("inps", "!=", NULL)
                    ->whereRaw('LENGTH(inps) = 14')
                    ->where("main_phone_number", "!=", NULL)
                    ->where("id", $clientId)
                    ->orderBy("id", "DESC")
                    ->first();
            });
            $this->chat->markdown("*Ro'yxatdan o'tgan mijoz:* \n" . $client['fio'])
                ->replyKeyboard(ReplyKeyboard::make()->buttons([
                    ReplyButton::make('🪪 Shartnoma raqamini yuborish 🪪'),
                    ReplyButton::make('🎁 Mening yutuqlarim 🎁')
                ]))->send();
        }
    }

    public function handleChatMessage(\Illuminate\Support\Stringable $text): void
    {
        $message = $this->message->toArray();
        // Log::info(json_encode($message));
        $phoneNumber = isset($message['contact']['phone_number']) ? $message['contact']['phone_number'] : null;
        $client = Cache::remember("client_id_" . $phoneNumber, 300, function () use ($phoneNumber) {
            return Client::select(['id', 'fio'])->whereRaw("regexp_replace(main_phone_number, '[^0-9]', '', 'g') LIKE ?", [$phoneNumber])
                ->whereActive(true)
                ->whereNotNull('province_id')
                ->whereNotNull('region_id')
                ->whereNotNull('village_id')
                ->first();
        });

        $_chatId = $message['chat']['id'];
        $summerActionGiftUserClient = Cache::remember("summer_action_gift_user_" . $message['chat']['id'], 300, function () use ($_chatId) {
            return SummerActionGiftUser::where('profile_id', $_chatId)->first();
        });

        $clientId = $phoneNumber
            ? $client?->id
            : $summerActionGiftUserClient?->client_id;

        $registeredClientIds = $this->getClientIds($clientId);
        $myContracts = Contract::with([
            'product_deliveries' => fn ($query) => $query->select("id", "contract_id", "status")
        ])
            ->whereDate('date', '>=', Contract::BEGIN_DATE)
            ->whereDate('date', '<=', Contract::END_DATE)
            ->whereDoesntHave('summer_action_gift_player')
            ->whereHas('product_deliveries', function ($query) {
                $query->where('status', '!=', ProductDelivery::STATUS_DELETED)->select("id", "contract_id", "status");
            })
            ->whereIn('client_id', $registeredClientIds)
            ->where('product_price', '>=', 4000000)
            ->select("id", "date", "client_id", "closed", "organization_id")
            ->get();

        if ($message['text'] == '' && $phoneNumber) {
            if (isset($client) && $message['from']['id'] == $message['contact']['user_id']) {
                $this->chat->markdown("*Ro'yxatdan o'tgan mijoz:* \n" . $client['fio'])
                    ->replyKeyboard(ReplyKeyboard::make()->buttons([
                        ReplyButton::make('🪪 Shartnoma raqamini yuborish 🪪'),
                        ReplyButton::make('🎁 Mening yutuqlarim 🎁')
                    ]))->send();
                if (!SummerActionGiftUser::where('phone_number', $phoneNumber)->exists())
                    SummerActionGiftUser::create([
                        'phone_number' => $message['contact']['phone_number'],
                        'client_id' => $client['id'],
                        'registered_date' => now(),
                        'profile_id' => $message['from']['id']
                    ]);
            } else {
                $this->chat->html("<b>Noto'g'ri raqam yuborildi. ( Shaxsiy raqamingizni yuboring )</b>")->send();
            }
        } else if ($message['text'] == '🎁 Mening yutuqlarim 🎁') {
            $registeredClientIds = $this->getClientIds($clientId);
            $myGifts = SummerActionGiftPlayer::with('contract:id,client_id', 'gift:id,name,photo_name', 'organization:id,organization')
                ->whereHas('contract', function ($query) use ($registeredClientIds) {
                    $query->whereIn('client_id', $registeredClientIds);
                })
                ->orderBy('id')
                ->get();

            if (count($myGifts) != 0) {
                foreach ($myGifts as $myGift) {
                    $name = $myGift['gift']['name'];
                    $photo = $myGift['gift']['photo_name'];
                    $code = $myGift['generated_code'];
                    $contractId = $myGift['contract_id'];
                    $organization = $myGift['organization']['organization'];
                    $date = $myGift['created_at']->format('Y-m-d H:i:s');
                    $imageName = uniqid();
                    if ($photo == SummerActionGift::EVOS_NAME) {
                        QRcode::png("$code", "storage/" . $imageName . ".png");
                        $photo = 'storage/' . $imageName . '.png';
                        $code = '';
                    }
                    $this->chat
                        ->html("<b>Yutuq: $name ( $code )\nSovg'a yutilgan sana: $date \nFilial: ✅ $organization ✅ \nShartnoma ID: $contractId</b>")
                        ->photo(public_path($photo))
                        ->send();
                    if (file_exists($photo) && $code == '') {
                        unlink($photo);
                    }
                }
            } else {
                $this->chat->html('<b>Sizda yutuqlar mavjud emas</b>')->replyKeyboard(ReplyKeyboard::make()->buttons([
                    ReplyButton::make('🪪 Shartnoma raqamini yuborish 🪪'),
                    ReplyButton::make('🎁 Mening yutuqlarim 🎁')
                ]))->send();
            }
        } else if ($message['text'] == '🪪 Shartnoma raqamini yuborish 🪪') {
            if (count($myContracts) == 0) {
                $this->chat->message('Sizda aktiv shartnoma mavjud emas')->replyKeyboard(ReplyKeyboard::make()->buttons([
                    ReplyButton::make('🪪 Shartnoma raqamini yuborish 🪪'),
                    ReplyButton::make('🎁 Mening yutuqlarim 🎁')
                ]))->send();
            } else {
                $this->chat->html('Shartnoma raqamini tanlang')->replyKeyboard(
                    ReplyKeyboard::make()
                        ->when(isset($myContracts[0]), fn (ReplyKeyboard $keyboard) => $keyboard->button(strval($myContracts[0]['id']) . "-raqamli shartnoma 📄"))
                        ->when(isset($myContracts[1]), fn (ReplyKeyboard $keyboard) => $keyboard->button(strval($myContracts[1]['id']) . "-raqamli shartnoma 📄"))
                        ->when(isset($myContracts[2]), fn (ReplyKeyboard $keyboard) => $keyboard->button(strval($myContracts[2]['id']) . "-raqamli shartnoma 📄"))
                        ->when(isset($myContracts[3]), fn (ReplyKeyboard $keyboard) => $keyboard->button(strval($myContracts[3]['id']) . "-raqamli shartnoma 📄"))
                        ->when(isset($myContracts[4]), fn (ReplyKeyboard $keyboard) => $keyboard->button(strval($myContracts[4]['id']) . "-raqamli shartnoma 📄"))
                        ->button('⬅️ Orqaga ⬅️')
                )->send();
            }
        } else if (in_array(str_replace(['+', '-'], '', filter_var($message['text'], FILTER_SANITIZE_NUMBER_INT)), collect($myContracts)->pluck('id')->toArray())) {
            $this->chat->message("O'yin boshlanmoqda ...")
                ->send();
            sleep(2);
            $this->chat->message("Yutuqni qabul qiling ...")
                ->send();
            sleep(1);
            $this->chat->message(" 🎉")
                ->send();
            $organizationId = collect($myContracts)->where('id', str_replace(['+', '-'], '', filter_var($message['text'], FILTER_SANITIZE_NUMBER_INT)))->first()['organization_id'];
            $contractId = explode("-", $message['text'])[0];
            $organization = Cache::remember("organization_id_" . $organizationId, 300, function () use ($organizationId) {
                return Organization::find($organizationId);
            });
            $contract = Cache::remember("contract_" . $contractId, 300, function () use ($contractId) {
                return Contract::find($contractId);
            });
            $giftOrganizations = $this->getOrganizationGift($organizationId);
            $prize = $giftOrganizations->random();
            $giftSummerId = $prize['summer_action_gift_id'];
            $gift = Cache::remember("summer_action_gift_" . $giftSummerId, 300, function () use ($giftSummerId) {
                return SummerActionGift::find($giftSummerId);
            });
            $prizeName = $prize['name'];
            $organizationName = $organization->organization;
            $winner = $this->saveGift($gift, $contract);
            $registeredClientIds = $this->getClientIds($clientId);
            $myContracts = Contract::with('product_deliveries:id,contract_id,status')
                ->whereDate('date', '>=', Contract::BEGIN_DATE)
                ->whereDate('date', '<=', Contract::END_DATE)
                ->whereDoesntHave('summer_action_gift_player')
                ->whereHas('product_deliveries', function ($query) {
                    $query->where('status', '!=', ProductDelivery::STATUS_DELETED);
                })
                ->select('id', 'organization_id')
                ->whereIn('client_id', $registeredClientIds)
                ->where('product_price', '>=', 4000000)
                ->select("id", "date", "client_id", "closed", "organization_id")
                ->get();
            $this->chat->html("<b>Yutuq: $prizeName (" . $winner['generated_code'] . " )\nSovg'a yutilgan sana: "
                . $winner['created_at']->format('Y-m-d H:i:s') . " \nFilial: ✅ " . $organizationName . " ✅\nShartnoma ID: $contractId</b>")
                ->photo(public_path($prize['photo_name']))
                ->replyKeyboard(
                    ReplyKeyboard::make()
                        ->when(isset($myContracts[0]), fn (ReplyKeyboard $keyboard) => $keyboard->button(strval($myContracts[0]['id']) . "-raqamli shartnoma 📄"))
                        ->when(isset($myContracts[1]), fn (ReplyKeyboard $keyboard) => $keyboard->button(strval($myContracts[1]['id']) . "-raqamli shartnoma 📄"))
                        ->when(isset($myContracts[2]), fn (ReplyKeyboard $keyboard) => $keyboard->button(strval($myContracts[2]['id']) . "-raqamli shartnoma 📄"))
                        ->when(isset($myContracts[3]), fn (ReplyKeyboard $keyboard) => $keyboard->button(strval($myContracts[3]['id']) . "-raqamli shartnoma 📄"))
                        ->when(isset($myContracts[4]), fn (ReplyKeyboard $keyboard) => $keyboard->button(strval($myContracts[4]['id']) . "-raqamli shartnoma 📄"))
                        ->button('⬅️ Orqaga ⬅️')
                )
                ->send();
            $this->chat->message(strval($prize['description']))->send();
        } elseif ($message['text'] == '⬅️ Orqaga ⬅️') {
            $this->chat->message("Kerakli bo'limni tanlang")->replyKeyboard(ReplyKeyboard::make()->buttons([
                ReplyButton::make('🪪 Shartnoma raqamini yuborish 🪪'),
                ReplyButton::make('🎁 Mening yutuqlarim 🎁')
            ]))->send();
        } else {
            $this->chat->html("<b>Noto'g'ri ma'lumot kiritildi. ( Kutilmagan ishlar bo'lyabdi )</b>")->send();
        }
    }

    public function checkPhoneNumberExists($phoneNumber)
    {
        return Cache::remember("checkPhoneNumberExists_" . $phoneNumber, 300, function () use ($phoneNumber) {
            return SummerActionGiftUser::where('phone_number', $phoneNumber)->exists();
        });
    }

    public function getOrganizationGift($organizationId)
    {
        $organizationGifts = Cache::remember("getOrganizationGift_" . $organizationId, 600, function () use ($organizationId) {
            return SummerActionGiftOrganization::with('summer_action_gift')
                ->where('organization_id', $organizationId)
                ->get();
        });

        $data = [];
        foreach ($organizationGifts as $organizationGift) {

            $giftWinner = SummerActionGiftPlayer::where('organization_id', $organizationId)
                ->where('summer_action_gift_id', $organizationGift['summer_action_gift_id'])
                ->count();
            $data[] = [
                'summer_action_gift_id' => $organizationGift['summer_action_gift_id'],
                'name' => $organizationGift['summer_action_gift']['name'],
                'photo_name' => $organizationGift['summer_action_gift']['photo_name'],
                'description' => $organizationGift['summer_action_gift']['description'],
                'residue' => (isset($organizationGift->probably_count) ? $organizationGift->probably_count : 0) - $giftWinner
            ];
        }
        return collect($data)->where('residue', '>', 0);
    }
    public function saveGift(SummerActionGift $gift, Contract $contract)
    {
        $code = $this->generateCode($gift, $contract);
        if (SummerActionGiftPlayer::where('generated_code', $code)->exists()) {
            $code = $this->generateCode($gift, $contract);
        }
        $data = SummerActionGiftPlayer::create([
            'summer_action_gift_id' => $gift['id'],
            'contract_id' => $contract['id'],
            'organization_id' => $contract['organization_id'],
            'is_given' => false,
            'given_date' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'generated_code' => $code
        ]);
        return $data;
    }
    public function generateCode(SummerActionGift $gift, Contract $contract)
    {
        $sequence = SummerActionGiftPlayer::where('summer_action_gift_id', $gift['id'])->count();
        switch ($gift['photo_name']) {
            case 'Evos.png':
                $spreadsheet = IOFactory::load('EVOS.csv');
                $sheetData = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
                $code = $sheetData[$sequence + 1]["A"];
                break;
            case 'Korzinka.png':
                $code = "KOR" . mt_rand(10000, 99999);
                break;
            case 'Merch5.png':
                $code = "R" . mt_rand(1000000, 9999999);
                break;
            case 'Merch8.png':
                $code = "B" . mt_rand(1000000, 9999999);
                break;
            case 'Merch10.png':
                $code = "SH" . mt_rand(1000000, 9999999);
                break;
            case 'Ishonch.png':
                $code = "I" . mt_rand(1000000, 9999999);
                Bonus::create([
                    'client_id' => $contract['client_id'],
                    'contract_id' => $contract['id'],
                    'value' => 100000,
                    'date' => now(),
                    'commentary' => "Summer aksiyasi uchun 100 000 so'm",
                    'is_act' => false,
                    'is_approved' => true,
                    'user_id' => 1164,
                    'status' => ''
                ]);
                break;
            case 'Merch3.png':
                $code = "F" . mt_rand(1000000, 9999999);
                break;
            case 'Merch9.png':
                $code = "K" . mt_rand(1000000, 9999999);
                break;
        }
        return $code;
    }

    // clientId orqali shu inps ga tegishli barcha mijozlar ID larini olish
    public function getClientIds($clientId)
    {
        return  Cache::remember("getClientIds_" . $clientId, 300, function () use ($clientId) {
            $registeredClient = Client::where("id", $clientId)->first();
            if (is_null($registeredClient)) {
                return [];
            }

            return Client::where("inps", $registeredClient?->inps)->pluck("id")->toArray();
        });
    }
}
