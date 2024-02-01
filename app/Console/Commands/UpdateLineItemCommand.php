<?php

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Calculate line item when it's update
 */
class UpdateLineItemCommand extends Command
{
    protected mixed $lineItems = [];
    public mixed $gbpRate;
    public mixed $usdRate;
    public mixed $brlRate;
    public mixed $eurRate;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:update-line-item';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This command update a line item with new calculation';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {


            $url = 'https://tassidicambio.bancaditalia.it/terzevalute-wf-web/rest/v1.0/latestRates?lang=en';

            $headers = array(
                'Content-Type: application/json',
                'Accept: application/json',
                'Cookie: TS01d56941=011612bbe971772c1151f4f7694f8194413033bc9eb7ee896aab9234f54bc46ccfbcca818ef82635119943081ad6fa8317fb8e1864'
            );

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            $response = curl_exec($ch);
            curl_close($ch);

            $res = json_decode($response, 2);
            $rates = $res['latestRates'];
            $this->gbpRate = collect($rates)->where('isoCode', 'GBP')->value('eurRate');
            $this->usdRate = collect($rates)->where('isoCode', 'USD')->value('eurRate');
            $this->brlRate = collect($rates)->where('isoCode', 'BRL')->value('eurRate');
            $this->eurRate = collect($rates)->where('isoCode', 'EUR')->value('eurRate');


            //GET ALL LINE ITEM DATA OF LAST 5 MINUTE AND STORE INTO ONE GLOBAL VARIABLE
            $_5mintAgoTime = now()->subMinutes(4)->toIso8601String(); //TODO : UPDATE WITH 4 MIN
            $this->getLineItems($_5mintAgoTime);


            //FLATTEN ALL LINE ITEMS
            $this->lineItems = Arr::flatten($this->lineItems, 1);

            //LOOP ON EACH LINE ITEMS AND UPDATE IT
            foreach ($this->lineItems as $lineItem) {
                $rawData = [];
                $lineItemProperty = $lineItem["properties"];

                if ($lineItemProperty['opt_out'] == "Yes" || $lineItemProperty['opt_out'] == "true") {
                    $rawData['opt_out_acv'] = $this->optOutAcv($lineItemProperty);
                }
                $rawData['acv_combined'] = $this->acvCombined($lineItemProperty);
                $rawData['weighted_value'] =$this->weightAndForecast($lineItemProperty);
                $rawData['price_in_company_currency'] = $this->acvInCompanyCurrency($lineItemProperty);
                $rawData['custom_updated_time'] = now()->toIso8601String();

                $payload = [
                    "properties" => $rawData
                ];

                //UPDATE A LINE ITEM
                $this->updateLineItem($lineItem["id"], $payload);
            }
        }catch (Exception $e){
            Log::error("UpdateLineItemCommand Exception $e");
        }
    }

    /**
     * @param $_5mintAgoTime
     * @param int $after
     * @return void
     */
    public function getLineItems($_5mintAgoTime, int $after = 0)
    {
        try {
            $payload = [
                "limit"        => 100,
                "archived"     => False,
                "sorts"        => [
                    "hs_lastmodifieddate"
                ],
                "properties"   => [
                    "opt_out",
                    "hs_acv",
                    "ramp_acv_exchange_rate",
                    "opt_out_window",
                    "price_in_company_currency",
                    "opt_out_acv",
                    "acv_combined",
                    "forecast_weight",
                    "line_item_currency",
                    "hs_lastmodifieddate",
                    "custom_updated_time"
                ],
                "filterGroups" => [
                    [
                        "filters" => [
                            [
                                "propertyName" => "hs_lastmodifieddate",
                                "value"        => $_5mintAgoTime,
                                "operator"     => "GT"
                            ],
                            [
                                "propertyName" => "deal_status",
                                "value"        => "Open",
                                "operator"     => "EQ"
                            ],
                            [
                                "propertyName" => "custom_updated_time",
                                "value"        => $_5mintAgoTime,
                                "operator"     => "LT"
                            ],
                        ]
                    ]
                ],
            ];

            if (!empty($after)){
                $payload["after"] = $after;
            }

            $response = Http::withHeaders([
                "Authorization" => "Bearer ".env('HUBSPOT_KEY'),
                "content-type" => "application/json"
            ])->post('https://api.hubapi.com/crm/v3/objects/line_items/search', $payload);


            if ($response->ok()){
                $resData = $response->json()["results"];
                $this->lineItems[] = $resData;

                if (isset($response->json()["paging"])){
                    $after = (int)($response->json()["paging"]["next"]["after"]);
                    $this->getLineItems($_5mintAgoTime, $after);
                }
            }
        }catch (Exception $e){
           Log::info('getAllUpdateLineItems Exception :- ', [$e]);
        }
    }

    /**
     * @param $lineItemId
     * @param $payload
     * @return void
     */
    public function updateLineItem($lineItemId, $payload)
    {
        try {
            Log::info("updated item id :- $lineItemId");
            $res = Http::withHeaders([
                "Authorization" => "Bearer " . env('HUBSPOT_KEY'),
                "content-type"  => "application/json"
            ])->patch("https://api.hubapi.com/crm/v3/objects/line_items/$lineItemId", $payload);
            Log::info('updated line item :- ', [$res->json()]);
        }
        catch (Exception $e) {
            Log::info('updateLineItem Exception :- ', [$lineItemId,$e]);
        }
    }

    private function optOutAcv($lineItem)
    {
        $ramp_acv_exchange_rate=$lineItem["ramp_acv_exchange_rate"] ?? null;
        if ($ramp_acv_exchange_rate=="alpha" || $ramp_acv_exchange_rate==0 || $ramp_acv_exchange_rate=="" ||
            $ramp_acv_exchange_rate=="0" || empty($ramp_acv_exchange_rate)){
            $optOutAcv = floatval($lineItem["price_in_company_currency"])*(100-floatval($lineItem["opt_out_window"]));
        }else{
            $optOutAcv = floatval($lineItem["ramp_acv_exchange_rate"])*(100-floatval($lineItem["opt_out_window"]));
        }
        return $optOutAcv;
    }

    private function acvCombined($lineItem)
    {
        $opt_out_acv=$lineItem["opt_out_acv"] ?? null;
        $ramp_acv_exchange_rate=$lineItem["ramp_acv_exchange_rate"] ?? null;
        if ($opt_out_acv=="alpha" || $opt_out_acv=="" || $opt_out_acv=="0" || $opt_out_acv==0 || empty($opt_out_acv)){
            if ($ramp_acv_exchange_rate=="alpha" || $ramp_acv_exchange_rate==0 || $ramp_acv_exchange_rate=="" ||
                $ramp_acv_exchange_rate=="0" || empty($ramp_acv_exchange_rate)){
                $acv_combined=$lineItem["price_in_company_currency"];
            }else{
                $acv_combined=$lineItem["ramp_acv_exchange_rate"];
            }
        }else{
            $acv_combined=$lineItem["opt_out_acv"];
        }

        return  $acv_combined;
    }

    private function weightAndForecast($lineItem)
    {
        $acvCompanyCurrency = $lineItem['acv_combined'];

        if ($acvCompanyCurrency >= 0) {
            $weightValue = $lineItem['forecast_weight'] * $lineItem['price_in_company_currency'];
        }

        if ($acvCompanyCurrency=="" || $acvCompanyCurrency==" " || $acvCompanyCurrency=="null" || empty($acvCompanyCurrency)){
            $weightValue = "";
        }

        return $weightValue;
    }

    private function acvInCompanyCurrency($lineItem)
    {
        $avc = $lineItem["hs_acv"];

        if ($lineItem["line_item_currency"] == "USD") {
            $acvInCompanyCurrency = floatval($avc) / $this->usdRate;
        }elseif($lineItem["line_item_currency"] == "GBP"){
            $acvInCompanyCurrency = floatval($avc) / $this->gbpRate;
        }elseif($lineItem["line_item_currency"] == "BRL"){
            $acvInCompanyCurrency = floatval($avc) / $this->brlRate;
        }else{
            $acvInCompanyCurrency = floatval($avc) / $this->eurRate;
        }

        return $acvInCompanyCurrency;
    }
}
