<?php
declare(strict_types=1);

namespace App\Service\Bind;


use App\Model\Commodity;
use App\Util\Http;
use App\Util\Ini;
use App\Util\Str;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Kernel\Annotation\Inject;
use Kernel\Container\Di;
use Kernel\Exception\JSONException;
use Kernel\Util\Decimal;

class Shared implements \App\Service\Shared
{

    #[Inject]
    private Client $http;

    /**
     * @param string $url
     * @param string $appId
     * @param string $appKey
     * @param array $data
     * @return array
     * @throws JSONException
     */
    public function mcyRequest(string $url, string $appId, string $appKey, array $data = []): array
    {
        try {
            $response = Http::make()->post($url, [
                "headers" => [
                    "Api-Id" => $appId,
                    "Api-Signature" => Str::generateSignature($data, $appKey)
                ],
                "form_params" => $data,
                "timeout" => 30
            ]);

            $contents = json_decode($response->getBody()->getContents() ?: "", true) ?: [];

            if (!isset($contents['code'])) {
                throw new JSONException("连接失败#1");
            }

            if ($contents['code'] != 200) {
                throw new JSONException(strip_tags($contents['msg']) ?? "连接失败#2");
            }

            return $contents['data'] ?? [];
        } catch (\Throwable $e) {
            throw new JSONException("连接失败#0");
        }
    }


    /**
     * @param string $url
     * @param string $appId
     * @param string $appKey
     * @param array $data
     * @return array
     * @throws GuzzleException
     * @throws JSONException
     */
    private function post(string $url, string $appId, string $appKey, array $data = []): array
    {
        $data = array_merge($data, ["app_id" => $appId, "app_key" => $appKey]);
        $data['sign'] = Str::generateSignature($data, $appKey);
        try {
            $response = Http::make()->post($url, [
                'form_params' => $data,
                'timeout' => 30
            ]);
        } catch (\Exception $e) {
            throw new JSONException("连接失败");
        }
        $contents = $response->getBody()->getContents();

        $result = json_decode($contents, true);
        if ($result['code'] != 200) {
            throw new JSONException(strip_tags((string)$result['msg']) ?: "连接失败");
        }
        return (array)$result['data'];
    }

    /**
     * @param string $domain
     * @return string
     */
    private function webshareDomain(string $domain): string
    {
        $domain = trim($domain, "/");
        if (!str_starts_with($domain, "http://") && !str_starts_with($domain, "https://")) {
            $domain = "https://" . $domain;
        }
        return $domain;
    }

    /**
     * @param string $domain
     * @param string $appKey
     * @param string $path
     * @param string $method
     * @param array $query
     * @param array $json
     * @return array
     * @throws JSONException
     */
    private function webshareRequest(string $domain, string $appKey, string $path, string $method = "GET", array $query = [], array $json = []): array
    {
        $url = $this->webshareDomain($domain) . $path;
        $options = [
            "headers" => [
                "Authorization" => "Token " . $appKey,
                "Accept" => "application/json"
            ],
            "timeout" => 30
        ];

        if (!empty($query)) {
            $options["query"] = $query;
        }

        if ($method !== "GET") {
            $options["headers"]["Content-Type"] = "application/json";
            $options["json"] = $json;
        }

        try {
            $response = Http::make()->request($method, $url, $options);
            $contents = json_decode($response->getBody()->getContents() ?: "", true);
            if (!is_array($contents)) {
                throw new JSONException("Webshare响应异常");
            }
            return $contents;
        } catch (\Throwable $e) {
            $message = "Webshare请求失败";
            if (method_exists($e, "getResponse") && $e->getResponse()) {
                $body = (string)$e->getResponse()->getBody();
                $decoded = json_decode($body, true);
                if (is_array($decoded)) {
                    if (isset($decoded["detail"]) && is_string($decoded["detail"])) {
                        $message = $decoded["detail"];
                    } elseif (isset($decoded["non_field_errors"][0]["message"])) {
                        $message = (string)$decoded["non_field_errors"][0]["message"];
                    } else {
                        foreach ($decoded as $errors) {
                            if (is_array($errors) && isset($errors[0]["message"])) {
                                $message = (string)$errors[0]["message"];
                                break;
                            }
                        }
                    }
                }
            }
            throw new JSONException($message);
        }
    }

    /**
     * @param array $customize
     * @return array
     */
    private function webshareCountries(array $customize): array
    {
        $available = (array)($customize["available_countries"] ?? []);
        $countries = [];

        foreach ($available as $key => $value) {
            if (is_string($key) && $key !== "") {
                $countries[strtoupper(trim($key))] = strtoupper(trim($key));
                continue;
            }

            if (is_string($value) && $value !== "") {
                $countries[strtoupper(trim($value))] = strtoupper(trim($value));
                continue;
            }

            if (is_array($value)) {
                $code = strtoupper(trim((string)($value["code"] ?? $value["country"] ?? "")));
                if ($code !== "") {
                    $countries[$code] = $code;
                }
            }
        }

        if (empty($countries)) {
            $countries["US"] = "US";
        }

        return $countries;
    }

    /**
     * @param array $countries
     * @param string|null $preferred
     * @return string
     */
    private function webshareSelectCountry(array $countries, ?string $preferred = null): string
    {
        $preferred = strtoupper(trim((string)$preferred));
        if ($preferred !== "" && isset($countries[$preferred])) {
            return $preferred;
        }

        foreach (["CN", "US", "HK", "JP", "SG"] as $hot) {
            if (isset($countries[$hot])) {
                return $hot;
            }
        }

        return (string)(array_key_first($countries) ?: "US");
    }

    /**
     * @param int $min
     * @param int $max
     * @return array
     */
    private function webshareTierQuantities(int $min, int $max): array
    {
        $min = max(1, $min);
        $max = max($min, $max);
        $tiers = [$min];
        foreach ([2, 5, 10, 20] as $tier) {
            if ($tier >= $min && $tier <= $max) {
                $tiers[] = $tier;
            }
        }
        $tiers = array_values(array_unique(array_filter($tiers, static function ($n) use ($min, $max) {
            return $n >= $min && $n <= $max;
        })));
        sort($tiers, SORT_NUMERIC);
        return $tiers;
    }

    /**
     * @param float $total
     * @param int $num
     * @return float
     */
    private function webshareUnitPrice(float $total, int $num): float
    {
        $num = max(1, $num);
        return (float)number_format($total / $num, 2, '.', '');
    }

    /**
     * @param string $proxyType
     * @param string $proxySubtype
     * @param array $customize
     * @param int $num
     * @param string|null $preferredCountry
     * @return array
     */
    private function webshareDefaultQuery(string $proxyType, string $proxySubtype, array $customize, int $num = 1, ?string $preferredCountry = null): array
    {
        $countries = $this->webshareCountries($customize);
        $country = $this->webshareSelectCountry($countries, $preferredCountry);

        $proxyCountMin = max(1, (int)($customize["proxy_count_min"] ?? 1));
        $proxyCountMax = max($proxyCountMin, (int)($customize["proxy_count_max"] ?? $proxyCountMin));
        $planNum = max(1, $num);
        $proxyCount = $proxyCountMin * $planNum;
        if ($proxyCount > $proxyCountMax) {
            $proxyCount = $proxyCountMax;
        }

        $bandwidthMin = max(1, (int)($customize["bandwidth_limit_min"] ?? 1));
        $subusersMin = max(1, (int)($customize["subusers_min"] ?? 1));
        $term = "monthly";
        if (!empty($customize["terms"]) && is_array($customize["terms"])) {
            $term = (string)($customize["terms"][0]["term"] ?? $term);
        }

        $query = [
            "proxy_type" => $proxyType,
            "proxy_subtype" => $proxySubtype,
            "proxy_countries" => [$country => $proxyCount],
            "bandwidth_limit" => $bandwidthMin,
            "on_demand_refreshes_total" => 0,
            "automatic_refresh_frequency" => 0,
            "proxy_replacements_total" => 0,
            "subusers_total" => $subusersMin,
            "term" => $term,
            "with_tax" => false
        ];

        foreach ((array)($customize["available_features"] ?? []) as $feature) {
            $name = (string)($feature["feature"] ?? "");
            if ($name !== "") {
                $query[$name] = false;
            }
        }

        return $query;
    }

    /**
     * @param string $domain
     * @param string $appKey
     * @param array $query
     * @return float
     * @throws JSONException
     */
    private function webshareFactoryPrice(string $domain, string $appKey, array $query): float
    {
        $pricing = $this->webshareRequest($domain, $appKey, "/api/v2/subscription/pricing/", "GET", [
            "query" => json_encode($query, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ]);

        $price = (float)($pricing["paid_today"] ?? $pricing["price"] ?? 0);
        if ($price <= 0) {
            $price = (float)($pricing["price"] ?? 0);
        }
        return $price;
    }

    /**
     * @param string $code
     * @return array
     * @throws JSONException
     */
    private function webshareDecodeCode(string $code): array
    {
        $parts = explode("|", $code, 2);
        if (count($parts) !== 2 || $parts[0] === "" || $parts[1] === "") {
            throw new JSONException("Webshare商品编码格式错误");
        }
        return [$parts[0], $parts[1]];
    }

    /**
     * @param \App\Model\Shared $shared
     * @param string $proxyType
     * @param string $proxySubtype
     * @param bool $buildMatrix
     * @return array
     * @throws JSONException
     */
    private function webshareItem(\App\Model\Shared $shared, string $proxyType, string $proxySubtype, bool $buildMatrix = true): array
    {
        $customize = $this->webshareRequest($shared->domain, $shared->app_key, "/api/v2/subscription/customize/", "GET", [
            "query" => json_encode([
                "proxy_type" => $proxyType,
                "proxy_subtype" => $proxySubtype
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ]);

        $query = $this->webshareDefaultQuery($proxyType, $proxySubtype, $customize);
        $factoryTotal = $this->webshareFactoryPrice($shared->domain, $shared->app_key, $query);

        $country = (string)array_key_first((array)$query["proxy_countries"]);
        $proxyCount = (int)($query["proxy_countries"][$country] ?? 1);
        $factoryPrice = $this->webshareUnitPrice($factoryTotal, 1);
        $proxyCountMin = max(1, (int)($customize["proxy_count_min"] ?? 1));
        $proxyCountMax = max($proxyCountMin, (int)($customize["proxy_count_max"] ?? $proxyCountMin));
        $maxPlans = max(1, (int)floor($proxyCountMax / max(1, $proxyCountMin)));

        $category = [];
        $categoryWholesale = [];

        if ($buildMatrix) {
            $countries = $this->webshareCountries($customize);
            $pickCountries = [];

            $defaultCountry = $this->webshareSelectCountry($countries, $country);
            $pickCountries[$defaultCountry] = $defaultCountry;
            foreach (["CN", "US"] as $pick) {
                if (isset($countries[$pick])) {
                    $pickCountries[$pick] = $pick;
                }
            }

            $tiers = $this->webshareTierQuantities(1, $maxPlans);

            foreach ($pickCountries as $countryCode) {
                try {
                    $baseQuery = $this->webshareDefaultQuery($proxyType, $proxySubtype, $customize, 1, $countryCode);
                    $baseTotal = $this->webshareFactoryPrice($shared->domain, $shared->app_key, $baseQuery);
                    $baseUnitPrice = $this->webshareUnitPrice($baseTotal, 1);
                    $category[$countryCode] = $baseUnitPrice;

                    foreach ($tiers as $tierNum) {
                        $tierQuery = $this->webshareDefaultQuery($proxyType, $proxySubtype, $customize, $tierNum, $countryCode);
                        $tierTotal = $this->webshareFactoryPrice($shared->domain, $shared->app_key, $tierQuery);
                        $tierUnitPrice = $this->webshareUnitPrice($tierTotal, max(1, $tierNum));
                        if ($tierNum === 1) {
                            $categoryWholesale[$countryCode][$tierNum] = $baseUnitPrice;
                            continue;
                        }

                        // 仅在上游总价随数量增长时记录阶梯价，避免出现“总价不变导致单价异常降低”。
                        if ($tierUnitPrice > 0 && $tierTotal > $baseTotal) {
                            $categoryWholesale[$countryCode][$tierNum] = $tierUnitPrice;
                        }
                    }
                } catch (\Throwable $e) {
                }
            }
        }

        if (empty($category)) {
            $category[$country] = $factoryPrice;
        }

        $stock = (int)($customize["proxy_count_max"] ?? 0);
        if ($stock <= 0) {
            $stock = 10000000;
        }

        $name = strtoupper($proxyType) . " / " . strtoupper($proxySubtype);
        $description = "Webshare代理资源，类型：{$proxyType}/{$proxySubtype}。支持国家选择与阶梯数量，默认国家：{$country}。";

        return [
            "id" => "{$proxyType}|{$proxySubtype}",
            "name" => $name,
            "description" => $description,
            "price" => $factoryPrice,
            "user_price" => $factoryPrice,
            "cover" => "https://www.webshare.io/favicon.ico",
            "factory_price" => $factoryPrice,
            "delivery_way" => 1,
            "contact_type" => 0,
            "password_status" => 0,
            "sort" => 0,
            "code" => "{$proxyType}|{$proxySubtype}",
            "seckill_status" => 0,
            "seckill_start_time" => "",
            "seckill_end_time" => "",
            "draft_status" => 0,
            "draft_premium" => "0.00",
            "inventory_hidden" => 0,
            "only_user" => 0,
            "purchase_count" => 0,
            "minimum" => 1,
            "maximum" => $maxPlans,
            "widget" => "[]",
            "stock" => (string)$stock,
            "config" => Ini::toConfig([
                "webshare_query" => $query,
                "webshare_customize" => [
                    "proxy_count_min" => (string)$proxyCountMin,
                    "proxy_count_max" => (string)$proxyCountMax,
                    "available_countries" => array_fill_keys(array_keys($category), "1")
                ],
                "category" => $category,
                "category_wholesale" => $categoryWholesale
            ])
        ];
    }

    /**
     * @param string $domain
     * @param string $appKey
     * @return int
     * @throws JSONException
     */
    private function websharePaymentMethod(string $domain, string $appKey): int
    {
        $methods = $this->webshareRequest($domain, $appKey, "/api/v2/payment/method/", "GET", [
            "page_size" => 1
        ]);

        $id = (int)($methods["results"][0]["id"] ?? 0);
        if ($id <= 0) {
            throw new JSONException("Webshare账号未找到可用支付方式，请先在Webshare后台绑定支付方式");
        }
        return $id;
    }

    /**
     * @param string $domain
     * @param string $appId
     * @param string $appKey
     * @param int $type
     * @return array|null
     * @throws GuzzleException
     * @throws JSONException
     */
    public function connect(string $domain, string $appId, string $appKey, int $type = 0): ?array
    {
        if ($type == 1) {
            $data = $this->mcyRequest($domain . "/plugin/open-api/connect", $appId, $appKey);
            return ["shopName" => $data['username'], "balance" => $data['balance']];
        } elseif ($type == 3) {
            $profile = $this->webshareRequest($domain, $appKey, "/api/v2/profile/");
            $shopName = (string)($profile["email"] ?? $profile["id"] ?? "Webshare");
            return ["shopName" => $shopName, "balance" => 0];
        }
        return $this->post($domain . "/shared/authentication/connect", $appId, $appKey);
    }

    /**
     * @param array $item
     * @return array
     */
    private function createV4Item(array $item): array
    {
        $arr = [
            'id' => $item['id'],
            'name' => $item['name'],
            'description' => $item['introduce'],
            'price' => $item['sku'][0]['stock_price'],
            'user_price' => $item['sku'][0]['stock_price'],
            'cover' => $item['picture_url'],
            'factory_price' => $item['sku'][0]['stock_price'],
            'delivery_way' => 0,
            'contact_type' => 0,
            'password_status' => 0,
            'sort' => 0,
            'code' => $item['id'],
            'seckill_status' => 0,
            'draft_status' => 0,
            'inventory_hidden' => 0,
            'only_user' => 0,
            'purchase_count' => 0,
            'minimum' => 0, //最低购买，
            'maximum' => 0
        ];

        $widget = json_decode($item['widget'] ?: "", true) ?: [];

        $wid = [];

        if (!empty($widget)) {
            foreach ($widget as $w) {
                $wid[] = [
                    'cn' => $w['title'],
                    'name' => $w['name'],
                    'placeholder' => $w['placeholder'],
                    'type' => $w['type'],
                    'regex' => $w['regex'],
                    'error' => $w['error'],
                    'dict' => str_replace(PHP_EOL, ',', $w['data'] ?? "")
                ];
            }
        }

        $arr['widget'] = json_encode($wid);

        $config = [];
        $arr['stock'] = 0;

        foreach ($item['sku'] as $sku) {
            $config['category'][$sku['name']] = $sku['stock_price'];
            $config['shared_mapping'][$sku['name']] = $sku['id'];
            if (is_numeric($sku['stock'])) {
                $arr['stock'] += $sku['stock'];
            }
        }
        $arr['stock'] == 0 && $arr['stock'] = 10000000;
        $arr['config'] = Ini::toConfig($config);

        return $arr;
    }

    /**
     * @param \App\Model\Shared $shared
     * @return array|null
     * @throws GuzzleException
     * @throws JSONException
     */
    public function items(\App\Model\Shared $shared): ?array
    {
        if ($shared->type == 1) {
            $data = $this->mcyRequest($shared->domain . "/plugin/open-api/items", $shared->app_id, $shared->app_key);

            $category = [];

            foreach ($data as $item) {
                $cateName = $item['category']['name'];
                if (!isset($category[$cateName])) {
                    $category[$cateName] = [
                        "name" => $cateName,
                        "id" => 0
                    ];
                }
                $category[$cateName]['children'][] = $this->createV4Item($item);
            }

            return array_values($category);
        } elseif ($shared->type == 2) {
            $a = $this->post($shared->domain . "/shared/commodity/items", $shared->app_id, $shared->app_key);

            foreach ($a as &$a1) {
                if (is_array($a1['children'])) {
                    foreach ($a1['children'] as &$a2) {
                        $a2['stock'] = '10000000';
                    }
                }
            }

            return $a;
        } elseif ($shared->type == 3) {
            $assets = $this->webshareRequest($shared->domain, $shared->app_key, "/api/v2/subscription/available_assets/");
            $category = [];

            foreach ($assets as $proxyType => $subtypes) {
                if (!is_array($subtypes)) {
                    continue;
                }

                $cateName = "Webshare/" . strtoupper((string)$proxyType);
                if (!isset($category[$cateName])) {
                    $category[$cateName] = [
                        "name" => $cateName,
                        "id" => 0,
                        "children" => []
                    ];
                }

                foreach ($subtypes as $proxySubtype => $value) {
                    try {
                        $item = $this->webshareItem($shared, (string)$proxyType, (string)$proxySubtype);
                        $category[$cateName]["children"][] = $item;
                    } catch (\Throwable $e) {
                    }
                }
            }

            return array_values(array_filter($category, static function ($item) {
                return !empty($item["children"]);
            }));
        }


        return $this->post($shared->domain . "/shared/commodity/items", $shared->app_id, $shared->app_key);
    }

    /**
     * @param \App\Model\Shared $shared
     * @param string $code
     * @return array
     * @throws GuzzleException
     * @throws JSONException
     */
    public function item(\App\Model\Shared $shared, string $code): array
    {
        if ($shared->type == 1) {
            $data = $this->mcyRequest($shared->domain . "/plugin/open-api/item", $shared->app_id, $shared->app_key, [
                "id" => $code
            ]);
            $a = $this->createV4Item($data);

            if (!is_array($a['config'])) {
                $a['config'] = Ini::toArray((string)$a['config']);
            }

            return $a;
        } elseif ($shared->type == 2) {
            $a = $this->post($shared->domain . "/shared/commodity/item", $shared->app_id, $shared->app_key, [
                "sharedCode" => $code
            ]);
            if (!isset($a[0]['children'][0])) {
                throw new JSONException("商品不存在#{$code}");
            }

            $b = $a[0]['children'][0];

            if (!is_array($b['config'])) {
                $b['config'] = Ini::toArray((string)$b['config']);
            }

            $b['stock'] = '10000000';

            return $b;
        } elseif ($shared->type == 3) {
            [$proxyType, $proxySubtype] = $this->webshareDecodeCode($code);
            $item = $this->webshareItem($shared, $proxyType, $proxySubtype, false);
            $item["config"] = Ini::toArray((string)$item["config"]);
            return $item;
        }
        return $this->post($shared->domain . "/shared/commodity/item", $shared->app_id, $shared->app_key, [
            "code" => $code
        ]);
    }


    /**
     * @param \App\Model\Shared $shared
     * @param Commodity $commodity
     * @param int $cardId
     * @param int $num
     * @param string $race
     * @return bool
     * @throws GuzzleException
     * @throws JSONException
     */
    public function inventoryState(\App\Model\Shared $shared, Commodity $commodity, int $cardId, int $num, string $race): bool
    {

        if ($shared->type == 1) {
            $config = Ini::toArray($commodity->config);
            $data = $this->mcyRequest($shared->domain . "/plugin/open-api/sku/state", $shared->app_id, $shared->app_key, [
                'sku_id' => (int)$config['shared_mapping'][$race],
                'quantity' => $num
            ]);
            return (bool)$data['state'];
        } elseif ($shared->type == 3) {
            return true;
        }

        $this->post($shared->domain . "/shared/commodity/inventoryState", $shared->app_id, $shared->app_key, [
            "shared_code" => $commodity->shared_code,
            "card_id" => $cardId,
            "num" => $num,
            "race" => $race
        ]);

        return true;
    }

    /**
     * @param \App\Model\Shared $shared
     * @param Commodity $commodity
     * @param string $contact
     * @param int $num
     * @param int $cardId
     * @param int $device
     * @param string $password
     * @param string $race
     * @param array|null $sku
     * @param string|null $widget
     * @param string $requestNo
     * @return string
     * @throws GuzzleException
     * @throws JSONException
     * @throws \ReflectionException
     */
    public function trade(\App\Model\Shared $shared, Commodity $commodity, string $contact, int $num, int $cardId, int $device, string $password, string $race, ?array $sku, ?string $widget, string $requestNo): string
    {
        $wg = (array)json_decode((string)$widget, true);


        if ($shared->type == 1) {
            $config = Ini::toArray($commodity->config);

            $post = [
                'sku_id' => (int)$config['shared_mapping'][$race],
                'quantity' => $num,
                'trade_no' => substr(md5($requestNo), 0, 24)
            ];

            foreach ($wg as $key => $item) {
                $post[$key] = $item['value'];
            }

            $data = $this->mcyRequest($shared->domain . "/plugin/open-api/trade", $shared->app_id, $shared->app_key, $post);
            return $data['contents'] ?? "此商品没有发货信息或正在发货中";
        } elseif ($shared->type == 3) {
            $config = Ini::toArray((string)$commodity->config);
            [$proxyType, $proxySubtype] = $this->webshareDecodeCode($commodity->shared_code);
            $customize = (array)($config["webshare_customize"] ?? []);
            $query = (array)($config["webshare_query"] ?? []);

            if (empty($query) || empty($customize)) {
                $item = $this->webshareItem($shared, $proxyType, $proxySubtype, false);
                $itemConfig = Ini::toArray((string)$item["config"]);
                $query = (array)($itemConfig["webshare_query"] ?? []);
                $customize = (array)($itemConfig["webshare_customize"] ?? []);
            }
            if (empty($query)) {
                throw new JSONException("Webshare商品配置缺失，请重新拉取商品");
            }
            $fallbackCountry = (string)array_key_first((array)($query["proxy_countries"] ?? []));

            if (empty($customize)) {
                $proxyCount = max(1, (int)($query["proxy_countries"][array_key_first((array)$query["proxy_countries"])] ?? 1));
                $availableCountries = [];
                foreach ((array)($config["category"] ?? []) as $countryCode => $ignore) {
                    if (is_string($countryCode) && $countryCode !== "") {
                        $availableCountries[strtoupper($countryCode)] = strtoupper($countryCode);
                    }
                }
                if ($fallbackCountry !== "") {
                    $availableCountries[strtoupper($fallbackCountry)] = strtoupper($fallbackCountry);
                }

                $customize = [
                    "proxy_count_min" => $proxyCount,
                    "proxy_count_max" => max($proxyCount, 100000),
                    "available_countries" => $availableCountries
                ];
            }

            $query = $this->webshareDefaultQuery(
                $proxyType,
                $proxySubtype,
                $customize,
                $num,
                $race ?: $fallbackCountry
            );

            $paymentMethod = $this->websharePaymentMethod($shared->domain, $shared->app_key);
            $factoryTotal = $this->webshareFactoryPrice($shared->domain, $shared->app_key, $query);
            $factoryPrice = $this->webshareUnitPrice($factoryTotal, max(1, $num));

            $purchase = $this->webshareRequest($shared->domain, $shared->app_key, "/api/v2/subscription/checkout/purchase/", "POST", [], array_merge($query, [
                "payment_method" => $paymentMethod,
                "recaptcha" => ""
            ]));

            $paymentRequired = (bool)($purchase["payment_required"] ?? false);
            if ($paymentRequired) {
                return "上游已创建待支付订单，PendingPayment#" . (int)($purchase["pending_payment"] ?? 0) . "，请联系管理员处理。";
            }

            // 仅同步成本价，不覆盖前台售卖价
            $commodity->factory_price = $factoryPrice;
            $commodity->save();

            return "Webshare采购成功，计划ID：" . (int)($purchase["plan"] ?? 0) . "。本次总成本：$" . number_format($factoryTotal, 2) . "，单个成本：$" . number_format($factoryPrice, 2) . "。代理明细请后台在Webshare代理池分发。";
        }

        $post = [
            "shared_code" => $commodity->shared_code,
            "contact" => $contact,
            "num" => $num,
            "card_id" => $cardId,
            "device" => $device,
            "password" => $password,
            "race" => $race,
            "request_no" => $requestNo,
            "sku" => $sku ?: []
        ];

        foreach ($wg as $key => $item) {
            $post[$key] = $item['value'];
        }

        $trade = $this->post($shared->domain . "/shared/commodity/trade", $shared->app_id, $shared->app_key, $post);

        /**
         * 更新缓存库存
         * @var \App\Service\Shop $shop
         */
        $shop = Di::inst()->make(\App\Service\Shop::class);
        $shop->updateSharedStock($commodity->id, $race, $sku);

        return (string)$trade['secret'];
    }

    /**
     * @param \App\Model\Shared $shared
     * @param string $code
     * @param array $map
     * @return array
     * @throws GuzzleException
     * @throws JSONException
     */
    public function draftCard(\App\Model\Shared $shared, string $code, array $map = []): array
    {
        $card = $this->post($shared->domain . "/shared/commodity/draftCard", $shared->app_id, $shared->app_key, array_merge([
            "code" => $code
        ], $map));
        return (array)$card;
    }


    /**
     * @param \App\Model\Shared $shared
     * @param string $code
     * @param int $cardId
     * @return array
     * @throws GuzzleException
     * @throws JSONException
     */
    public function getDraft(\App\Model\Shared $shared, string $code, int $cardId): array
    {
        return $this->post($shared->domain . "/shared/commodity/draft", $shared->app_id, $shared->app_key, [
            "code" => $code,
            "card_id" => $cardId
        ]);
    }

    /**
     * @param \App\Model\Shared $shared
     * @param Commodity $commodity
     * @param string $race
     * @return array
     * @throws GuzzleException
     * @throws JSONException
     */
    public function inventory(\App\Model\Shared $shared, Commodity $commodity, string $race = ""): array
    {
        if ($shared->type == 1) {
            $config = Ini::toArray($commodity->config);

            $item = $this->mcyRequest($shared->domain . "/plugin/open-api/item", $shared->app_id, $shared->app_key, [
                'id' => (int)$commodity->shared_code
            ]);

            $v4Item = $this->createV4Item($item);

            $result = [
                'delivery_way' => 0,
                'draft_status' => 0,
                'price' => $v4Item['price'],
                'user_price' => $v4Item['user_price'],
                'config' => $v4Item['config'],
                'factory_price' => $v4Item['factory_price'],
                'is_category' => true,
                'count' => 0
            ];

            if (empty($race)) {
                foreach ($config['shared_mapping'] as $skuId) {
                    $data = $this->mcyRequest($shared->domain . "/plugin/open-api/sku/stock", $shared->app_id, $shared->app_key, [
                        'sku_id' => (int)$skuId,
                    ]);
                    $result['count'] += (int)$data['stock'];
                }
            } else {
                $data = $this->mcyRequest($shared->domain . "/plugin/open-api/sku/stock", $shared->app_id, $shared->app_key, [
                    'sku_id' => (int)$config['shared_mapping'][$race],
                ]);
                $result['count'] = (int)$data['stock'];
            }

            return $result;
        } elseif ($shared->type == 3) {
            $item = $this->item($shared, $commodity->shared_code);
            $isCategory = is_array($item["config"]["category"] ?? null) && !empty($item["config"]["category"]);
            return [
                "delivery_way" => 1,
                "draft_status" => 0,
                "price" => $item["price"],
                "user_price" => $item["user_price"],
                "config" => $item["config"],
                "factory_price" => $item["factory_price"],
                "is_category" => $isCategory,
                "count" => (int)$item["stock"]
            ];
        }

        $inventory = $this->post($shared->domain . "/shared/commodity/inventory", $shared->app_id, $shared->app_key, [
            "sharedCode" => $commodity->shared_code,
            "race" => $race
        ]);

        return (array)$inventory;
    }

    /**
     * @param \App\Model\Shared $shared
     * @param string $code
     * @param string|null $race
     * @param array|null $sku
     * @return string
     * @throws GuzzleException
     * @throws JSONException
     */
    public function getItemStock(\App\Model\Shared $shared, string $code, ?string $race = null, ?array $sku = []): string
    {
        if ($shared->type == 1) {
            return "10000000";
        } elseif ($shared->type == 2) {
            return "10000000";
        } elseif ($shared->type == 3) {
            return "10000000";
        }
        
        $stock = $this->post($shared->domain . "/shared/commodity/stock", $shared->app_id, $shared->app_key, [
            "code" => $code,
            "race" => $race,
            "sku" => $sku
        ]);
        return $stock['stock'] ?? "0";
    }


    /**
     * @param string $config
     * @param string $price
     * @param string $userPrice
     * @param int $type
     * @param float $premium
     * @return array
     * @throws JSONException
     */
    public function AdjustmentPrice(string $config, string $price, string $userPrice, int $type, float $premium): array
    {
        $_config = Ini::toArray($config);
        //race
        if (array_key_exists("category", $_config) && is_array($_config['category'])) {
            foreach ($_config['category'] as &$_price) {
                $_tmp = new Decimal($_price, 2);
                $_price = $type == 0 ? $_tmp->add($premium)->getAmount() : $_tmp->add((new Decimal($premium, 3))->mul($_price)->getAmount())->getAmount();
            }
        }
        //sku
        if (array_key_exists("sku", $_config) && is_array($_config['sku'])) {
            foreach ($_config['sku'] as &$sku) {
                foreach ($sku as &$_price) {
                    if ($_price > 0) {
                        $_tmp = new Decimal($_price, 2);
                        $_price = $type == 0 ? $_tmp->add($premium)->getAmount() : $_tmp->add((new Decimal($premium, 3))->mul($_price)->getAmount())->getAmount();
                    }
                }
            }
        }

        //wholesale
        if (array_key_exists("wholesale", $_config) && is_array($_config['wholesale'])) {
            foreach ($_config['wholesale'] as &$_price) {
                $_tmp = new Decimal($_price, 2);
                $_price = $type == 0 ? $_tmp->add($premium)->getAmount() : $_tmp->add((new Decimal($premium, 3))->mul($_price)->getAmount())->getAmount();
            }
        }

        //category_wholesale
        if (array_key_exists("category_wholesale", $_config) && is_array($_config['category_wholesale'])) {
            foreach ($_config['category_wholesale'] as &$categoryWholesale) {
                foreach ($categoryWholesale as &$_price) {
                    $_tmp = new Decimal($_price, 2);
                    $_price = $type == 0 ? $_tmp->add($premium)->getAmount() : $_tmp->add((new Decimal($premium, 3))->mul($_price)->getAmount())->getAmount();
                }
            }
        }

        $_tmp = new Decimal($price, 2);
        $price = $type == 0 ? $_tmp->add($premium)->getAmount() : $_tmp->add((new Decimal($premium, 3))->mul($price)->getAmount())->getAmount();


        $_tmp = new Decimal($userPrice, 2);
        $userPrice = $type == 0 ? $_tmp->add($premium)->getAmount() : $_tmp->add((new Decimal($premium, 3))->mul($userPrice)->getAmount())->getAmount();


        return ["config" => $_config, "price" => $price, "user_price" => $userPrice];
    }


    /**
     * @param int $type
     * @param float $premium
     * @param float|int|string $amount
     * @return string
     */
    public function AdjustmentAmount(int $type, float $premium, float|int|string $amount): string
    {
        $_tmp = new Decimal($amount, 2);
        return $type == 0 ? $_tmp->add($premium)->getAmount() : $_tmp->add((new Decimal($premium, 3))->mul($amount)->getAmount())->getAmount();
    }
}
