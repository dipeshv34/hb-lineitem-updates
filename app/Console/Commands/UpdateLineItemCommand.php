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
        //GET ALL LINE ITEM DATA OF LAST 5 MINUTE AND STORE INTO ONE GLOBAL VARIABLE
        $_5mintAgoTime = now()->subDays(4); //TODO : UPDATE WITH 4 MIN
        $this->getLineItems($_5mintAgoTime);

        //FLATTEN ALL LINE ITEMS
        $this->lineItems = Arr::flatten($this->lineItems, 1);

        //LOOP ON EACH LINE ITEMS AND UPDATE IT
        foreach ($this->lineItems as $lineItem){
            $rawData = [];
            $lineItemProperty = $lineItem["properties"];

            if ($lineItemProperty['opt_out'] == "Yes" || $lineItemProperty['opt_out']=="true"){
                $rawData = $this->optOutAcv($lineItemProperty);
            }
            $rawData = [$this->acvCombined($lineItemProperty), ...$rawData];
            $rawData = [$this->weightAndForecast($lineItemProperty), ...$rawData];
            $rawData = [$this->acvInCompanyCurrency($lineItemProperty), ...$rawData];
            $rawData = [$this->formulaUpdateTime(), ...$rawData];

            $payload = [
                "properties" => $rawData
            ];

            //UPDATE A LINE ITEM
            $this->updateLineItem($lineItem["id"], $payload);
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
                                "propertyName" => "custom_updated_time",
                                "value"        => "2024-01-28",
                                "operator"     => "GT"
                            ]
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

            dd($response->json());

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
            Log::info('Update Line Item Id :- '.$lineItemId);
            Http::withHeaders([
                "Authorization" => "Bearer ".env('HUBSPOT_KEY'),
                "content-type" => "application/json"
            ])->patch("https://api.hubapi.com/crm/v3/objects/line_items/$lineItemId", $payload);
        }catch (Exception $e){
            Log::info('updateLineItem Exception :- ', [$lineItemId, $e]);
        }
    }

    private function optOutAcv($lineItem)
    {
        $optOutAcv = floatval($lineItem["ramp_acv_exchange_rate"])*(100-floatval($lineItem["opt_out_window"]));
        return [
            "opt_out_acv" => $optOutAcv
        ];
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

        return [
            "acv_combined" => $acv_combined,
        ];
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

        return [
            "weighted_value" => $weightValue
        ];
    }

    private function acvInCompanyCurrency($lineItem)
    {
        $avc = $lineItem["hs_acv"];
        if ($lineItem["line_item_currency"] == "USD") {
            $acvInCompanyCurrency = floatval($avc) / 1.08082273;
        }else{
            $acvInCompanyCurrency = floatval($avc) / 0.87045409;
        }

        return [
            "price_in_company_currency" => $acvInCompanyCurrency,
        ];
    }

    private function formulaUpdateTime()
    {
        return [
          "formula_update_time" => now(),
        ];
    }
}
