<?php
declare(strict_types=1);

namespace App\Service\Bind;

use App\Model\Commodity;
use App\Model\Order;
use App\Util\FileCache;
use App\Util\Http;
use App\Util\Ini;
use Kernel\Exception\JSONException;

class Webshare implements \App\Service\Webshare
{
    private const DEFAULT_BASE_URL = 'https://proxy.webshare.io/api/v2';
    private const CACHE_NAMESPACE = 'webshare_pricing';
    private const ASSETS_CACHE_NAMESPACE = 'webshare_assets';

    private const COUNTRY_LABELS = [
        'US' => '美国', 'GB' => '英国', 'HK' => '香港', 'JP' => '日本', 'DE' => '德国', 'SG' => '新加坡', 'CA' => '加拿大',
        'FR' => '法国', 'AU' => '澳大利亚', 'IT' => '意大利', 'NL' => '荷兰', 'ES' => '西班牙', 'KR' => '韩国', 'TW' => '台湾',
        'MY' => '马来西亚', 'TH' => '泰国', 'VN' => '越南', 'ID' => '印度尼西亚', 'PH' => '菲律宾', 'IN' => '印度',
        'AE' => '阿联酋', 'SA' => '沙特', 'TR' => '土耳其', 'BR' => '巴西', 'MX' => '墨西哥', 'AR' => '阿根廷',
        'CL' => '智利', 'PE' => '秘鲁', 'CO' => '哥伦比亚', 'PL' => '波兰', 'SE' => '瑞典', 'NO' => '挪威',
        'DK' => '丹麦', 'FI' => '芬兰', 'CH' => '瑞士', 'BE' => '比利时', 'AT' => '奥地利', 'IE' => '爱尔兰',
        'PT' => '葡萄牙', 'CZ' => '捷克', 'RO' => '罗马尼亚', 'UA' => '乌克兰', 'RU' => '俄罗斯', 'ZA' => '南非',
        'EG' => '埃及', 'NG' => '尼日利亚', 'PK' => '巴基斯坦', 'BD' => '孟加拉', 'KZ' => '哈萨克斯坦'
    ];

    public function isSupportedCommodity(Commodity $commodity): bool
    {
        if (!$commodity->config) {
            return false;
        }

        try {
            $config = Ini::toArray((string)$commodity->config);
        } catch (\Throwable) {
            return false;
        }

        if (($config['webshare']['provider'] ?? '') !== 'webshare') {
            return false;
        }

        $action = $config['webshare']['action'] ?? '';
        return in_array($action, ['subscription_purchase', 'subscription_create'], true);
    }

    public function deliver(Order $order): string
    {
        $commodity = $order->commodity;
        if (!$commodity) {
            throw new JSONException('商品不存在');
        }

        [$settings, $payload] = $this->buildPayloadFromWidget($commodity, (string)$order->widget, (int)$order->card_num);
        $response = $this->request('POST', '/subscription/checkout/purchase/', [
            'json' => $payload
        ]);

        return $this->formatSecret($payload, $response);
    }

    public function getPreset(string $preset): array
    {
        return match ($preset) {
            'subscription_residential' => [
                'title' => 'Webshare 动态住宅代理',
                'tips' => '已写入 Webshare 控件与配置；前台估价与下单使用本地成本，后台可手动同步一次上游成本，请先在 `config/webshare.php` 中填入 Token。',
                'config' => Ini::toConfig([
                    'webshare' => [
                        'provider' => 'webshare',
                        'action' => 'subscription_purchase',
                        'proxy_type' => 'shared',
                        'proxy_subtype' => 'residential',
                        'default_proxy_count' => '1',
                        'default_bandwidth_limit' => '30',
                        'default_automatic_refresh_frequency' => '0',
                        'default_term' => 'monthly',
                        'default_proxy_replacements_total' => '0',
                        'default_subusers_total' => '3',
                        'default_on_demand_refreshes_total' => '0',
                        'default_is_unlimited_ip_authorizations' => '0',
                        'default_is_high_concurrency' => '0',
                        'default_is_high_priority_network' => '0'
                    ]
                ]),
                'widget' => [
                    [
                        'cn' => '国家地区',
                        'name' => 'country_code',
                        'placeholder' => '请选择国家地区',
                        'type' => 'select',
                        'regex' => '',
                        'error' => '',
                        'dict' => '随机=,美国=US,英国=GB,香港=HK,日本=JP,德国=DE,新加坡=SG,加拿大=CA,法国=FR,澳大利亚=AU'
                    ],
                    [
                        'cn' => '流量上限(GB)',
                        'name' => 'bandwidth_limit',
                        'placeholder' => '请选择流量上限',
                        'type' => 'select',
                        'regex' => '',
                        'error' => '',
                        'dict' => '1GB=1,5GB=5,10GB=10,30GB=30,100GB=100,300GB=300'
                    ],
                    [
                        'cn' => '定期更换时间',
                        'name' => 'automatic_refresh_frequency',
                        'placeholder' => '请选择更换周期',
                        'type' => 'select',
                        'regex' => '',
                        'error' => '',
                        'dict' => '不更换=0,每1分钟=60,每5分钟=300,每10分钟=600,每30分钟=1800,每1小时=3600,每天=86400'
                    ],
                    [
                        'cn' => '订阅周期',
                        'name' => 'term',
                        'placeholder' => '请选择订阅周期',
                        'type' => 'radio',
                        'regex' => '',
                        'error' => '',
                        'dict' => '月付=monthly,年付=yearly'
                    ],
                    [
                        'cn' => '代理数量',
                        'name' => 'proxy_count',
                        'placeholder' => '留空则跟随购买数量',
                        'type' => 'number',
                        'regex' => '^\\d*$',
                        'error' => '代理数量必须是整数'
                    ],
                    [
                        'cn' => '代理类型',
                        'name' => 'proxy_subtype',
                        'placeholder' => '请选择代理类型',
                        'type' => 'radio',
                        'regex' => '',
                        'error' => '',
                        'dict' => '住宅=residential,ISP=isp,混合机房/ISP=datacenter_and_isp,premium=premium,default=default'
                    ]
                ]
            ],
            default => throw new JSONException('未找到该 Webshare 预设')
        };
    }

    public function getRealtimeCost(Commodity $commodity, array $input = [], int $quantity = 1): array
    {
        [, $payload] = $this->buildPayloadFromInput($commodity, $input, $quantity);
        return $this->fetchPricing($commodity, $payload);
    }

    public function getRealtimeCostByWidget(Commodity $commodity, ?string $widgetJson, int $quantity = 1): array
    {
        [, $payload] = $this->buildPayloadFromWidget($commodity, $widgetJson, $quantity);
        return $this->fetchPricing($commodity, $payload);
    }

    public function syncCommodityCost(Commodity $commodity): array
    {
        [, $payload] = $this->buildPayloadFromInput($commodity, [], 1);
        return $this->fetchPricing($commodity, $payload, true);
    }

    public function convertSharedCommodity(Commodity $commodity): array
    {
        $config = Ini::toArray((string)$commodity->config);
        $query = (array)($config['webshare_query'] ?? []);
        $customize = (array)($config['webshare_customize'] ?? []);

        $sharedCode = strtolower((string)$commodity->shared_code);
        [$sharedType, $sharedSubtype] = array_pad(explode('|', $sharedCode, 2), 2, '');

        $proxyType = (string)($query['proxy_type'] ?? $sharedType ?: 'shared');
        $proxySubtype = (string)($query['proxy_subtype'] ?? $sharedSubtype ?: 'default');
        $defaultCountry = $this->pickDefaultCountry($query, $customize);
        $countryCodes = $this->resolveCountryCodes($proxyType, $proxySubtype, $customize);
        $proxyCountMin = max(1, (int)($customize['proxy_count_min'] ?? 1));
        $proxyCountMax = max($proxyCountMin, (int)($customize['proxy_count_max'] ?? max((int)$commodity->stock, 1)));

        $widget = [
            [
                'cn' => '国家地区',
                'name' => 'country_code',
                'placeholder' => '请选择国家地区',
                'type' => 'select',
                'regex' => '',
                'error' => '',
                'dict' => $this->buildCountryDict($countryCodes, $defaultCountry),
            ],
            [
                'cn' => '流量上限(GB)',
                'name' => 'bandwidth_limit',
                'placeholder' => '请选择流量上限',
                'type' => 'select',
                'regex' => '',
                'error' => '',
                'dict' => '1GB=1,5GB=5,10GB=10,30GB=30,100GB=100,300GB=300',
            ],
            [
                'cn' => '定期更换时间',
                'name' => 'automatic_refresh_frequency',
                'placeholder' => '请选择更换周期',
                'type' => 'select',
                'regex' => '',
                'error' => '',
                'dict' => '不更换=0,每1分钟=60,每5分钟=300,每10分钟=600,每30分钟=1800,每1小时=3600,每天=86400',
            ],
            [
                'cn' => '订阅周期',
                'name' => 'term',
                'placeholder' => '请选择订阅周期',
                'type' => 'radio',
                'regex' => '',
                'error' => '',
                'dict' => '月付=monthly,年付=yearly',
            ],
            [
                'cn' => '代理数量',
                'name' => 'proxy_count',
                'placeholder' => '默认1，最大' . $proxyCountMax,
                'type' => 'number',
                'regex' => '^\\d+$',
                'error' => '代理数量必须是正整数',
            ]
        ];

        return [
            'widget' => json_encode($widget, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'config' => Ini::toConfig([
                'webshare' => [
                    'provider' => 'webshare',
                    'action' => 'subscription_purchase',
                    'proxy_type' => $proxyType,
                    'proxy_subtype' => $proxySubtype,
                    'default_proxy_count' => (string)$proxyCountMin,
                    'default_bandwidth_limit' => (string)($query['bandwidth_limit'] ?? '1'),
                    'default_automatic_refresh_frequency' => (string)($query['automatic_refresh_frequency'] ?? '0'),
                    'default_term' => (string)($query['term'] ?? 'monthly'),
                    'default_country_code' => $defaultCountry,
                    'default_proxy_replacements_total' => (string)($query['proxy_replacements_total'] ?? '0'),
                    'default_subusers_total' => (string)($query['subusers_total'] ?? '3'),
                    'default_on_demand_refreshes_total' => (string)($query['on_demand_refreshes_total'] ?? '0'),
                    'default_is_unlimited_ip_authorizations' => $this->normalizeBoolString($query['is_unlimited_ip_authorizations'] ?? '0'),
                    'default_is_high_concurrency' => $this->normalizeBoolString($query['is_high_concurrency'] ?? '0'),
                    'default_is_high_priority_network' => $this->normalizeBoolString($query['is_high_priority_network'] ?? '0'),
                ],
                'webshare_customize' => [
                    'proxy_count_min' => $proxyCountMin,
                    'proxy_count_max' => $proxyCountMax,
                    'available_countries' => array_fill_keys($countryCodes, 1),
                ],
            ]),
            'stock' => max(1, (int)$commodity->stock),
        ];
    }

    private function fetchPricing(Commodity $commodity, array $payload, bool $forceRefresh = false): array
    {
        $cfg = (array)config('webshare');
        $ttl = max(0, (int)($cfg['pricing_cache_ttl'] ?? 20));
        $cacheKey = md5(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');

        if (!$forceRefresh && $ttl > 0) {
            $cache = FileCache::getJsonFile(self::CACHE_NAMESPACE, $cacheKey);
            if (!empty($cache)) {
                return $cache;
            }
        }

        $response = $this->request('GET', '/subscription/pricing/', [
            'query' => [
                'query' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ]
        ]);

        $costField = (string)($cfg['cost_field'] ?? 'price');
        $canonicalCost = (float)($response[$costField] ?? $response['price'] ?? 0);
        $data = [
            'cost' => $canonicalCost,
            'price' => (float)($response['price'] ?? $canonicalCost),
            'paid_today' => (float)($response['paid_today'] ?? $canonicalCost),
            'currency' => (string)($response['currency'] ?? 'USD'),
            'payload' => $payload,
            'response' => $response,
        ];

        if ((bool)($cfg['sync_factory_price'] ?? true)) {
            $this->syncFactoryPrice($commodity, $data['cost']);
        }

        if ($ttl > 0) {
            FileCache::setJsonFile(self::CACHE_NAMESPACE, $cacheKey, $data, $ttl);
        }

        return $data;
    }

    private function buildPayloadFromInput(Commodity $commodity, array $input, int $quantity): array
    {
        $settings = $this->getSettings($commodity);
        $resolver = static function (string $key, string $default = '') use ($input) {
            $value = $input[$key] ?? $default;
            if (is_array($value)) {
                return implode(',', $value);
            }
            return trim((string)$value);
        };

        return [$settings, $this->buildSubscriptionPayload($settings, $resolver, $quantity)];
    }

    private function buildPayloadFromWidget(Commodity $commodity, ?string $widgetJson, int $quantity): array
    {
        $settings = $this->getSettings($commodity);
        $widget = (array)json_decode($widgetJson ?: '{}', true);
        $resolver = static function (string $key, string $default = '') use ($widget) {
            $value = $widget[$key]['value'] ?? $default;
            if (is_array($value)) {
                return implode(',', $value);
            }
            return trim((string)$value);
        };

        return [$settings, $this->buildSubscriptionPayload($settings, $resolver, $quantity)];
    }

    private function getSettings(Commodity $commodity): array
    {
        if (!$this->isSupportedCommodity($commodity)) {
            throw new JSONException('当前商品未启用 Webshare');
        }

        $config = Ini::toArray((string)$commodity->config);
        return $config['webshare'] ?? [];
    }

    private function buildSubscriptionPayload(array $settings, callable $resolver, int $quantity): array
    {
        $proxyCount = $this->toPositiveInt($resolver('proxy_count', (string)($settings['default_proxy_count'] ?? '1')), 1);
        $effectiveCount = max(1, $proxyCount) * max(1, $quantity);
        $countryCode = strtoupper(trim((string)$resolver('country_code', (string)($settings['default_country_code'] ?? ''))));

        $proxyType = $resolver('proxy_type', (string)($settings['proxy_type'] ?? 'shared'));
        $proxySubtype = $resolver('proxy_subtype', (string)($settings['proxy_subtype'] ?? 'residential'));
        if ($proxyType === 'residential') {
            $proxyType = 'shared';
            if ($proxySubtype === '' || $proxySubtype === 'residential') {
                $proxySubtype = 'residential';
            }
        }

        $automaticRefresh = $this->toPositiveInt($resolver('automatic_refresh_frequency', (string)($settings['default_automatic_refresh_frequency'] ?? '0')), 0);
        if ($automaticRefresh > 0 && $automaticRefresh <= 1440) {
            $automaticRefresh *= 60;
        }

        $term = strtolower($resolver('term', (string)($settings['default_term'] ?? 'monthly')));
        if (is_numeric($term)) {
            $term = ((int)$term >= 365) ? 'yearly' : 'monthly';
        }
        if (!in_array($term, ['monthly', 'yearly'], true)) {
            $term = 'monthly';
        }

        $payload = [
            'proxy_type' => $proxyType,
            'proxy_subtype' => $proxySubtype,
            'proxy_count' => $effectiveCount,
            'bandwidth_limit' => $this->toPositiveInt($resolver('bandwidth_limit', (string)($settings['default_bandwidth_limit'] ?? '30')), 30),
            'automatic_refresh_frequency' => $automaticRefresh,
            'term' => $term,
            'proxy_replacements_total' => $this->toPositiveInt($resolver('proxy_replacements_total', (string)($settings['default_proxy_replacements_total'] ?? '0')), 0),
            'subusers_total' => $this->toPositiveInt($resolver('subusers_total', (string)($settings['default_subusers_total'] ?? '3')), 3),
            'on_demand_refreshes_total' => $this->toPositiveInt($resolver('on_demand_refreshes_total', (string)($settings['default_on_demand_refreshes_total'] ?? '0')), 0),
            'is_unlimited_ip_authorizations' => $this->toBool($resolver('is_unlimited_ip_authorizations', (string)($settings['default_is_unlimited_ip_authorizations'] ?? '0'))),
            'is_high_concurrency' => $this->toBool($resolver('is_high_concurrency', (string)($settings['default_is_high_concurrency'] ?? '0'))),
            'is_high_priority_network' => $this->toBool($resolver('is_high_priority_network', (string)($settings['default_is_high_priority_network'] ?? '0'))),
        ];

        $autoRenewal = $resolver('auto_renewal_option', (string)($settings['auto_renewal_option'] ?? ''));
        if ($autoRenewal !== '') {
            $payload['auto_renewal_option'] = $autoRenewal;
        }

        if ($countryCode !== '') {
            $payload['proxy_countries'] = [
                $countryCode => $effectiveCount,
            ];
        }

        return $payload;
    }

    private function request(string $method, string $path, array $options = []): array
    {
        $config = (array)config('webshare');
        $token = trim((string)($config['token'] ?? ''));
        if ($token === '') {
            throw new JSONException('请先在 config/webshare.php 中填写 Token');
        }

        $baseUrl = rtrim((string)($config['base_url'] ?? self::DEFAULT_BASE_URL), '/');
        $timeout = (int)($config['timeout'] ?? 20);

        try {
            $response = Http::make()->request($method, $baseUrl . $path, array_replace_recursive([
                'headers' => [
                    'Authorization' => 'Token ' . $token,
                    'Accept' => 'application/json',
                ],
                'timeout' => $timeout,
                'http_errors' => false,
            ], $options));
        } catch (\Throwable $e) {
            throw new JSONException('Webshare 请求失败：' . $e->getMessage());
        }

        $statusCode = $response->getStatusCode();
        $contents = $response->getBody()->getContents();
        $result = json_decode($contents, true);

        if ($statusCode < 200 || $statusCode >= 300) {
            $message = is_array($result) ? ($result['detail'] ?? $result['message'] ?? $contents) : $contents;
            throw new JSONException('Webshare 返回异常：' . trim((string)$message));
        }

        if (!is_array($result)) {
            throw new JSONException('Webshare 返回内容不是有效 JSON');
        }

        return $result;
    }

    private function getAvailableAssets(): array
    {
        $cache = FileCache::getJsonFile(self::ASSETS_CACHE_NAMESPACE, 'subscription_assets');
        if (!empty($cache)) {
            return $cache;
        }

        $assets = $this->request('GET', '/subscription/available_assets/');
        FileCache::setJsonFile(self::ASSETS_CACHE_NAMESPACE, 'subscription_assets', $assets, 3600);
        return $assets;
    }

    private function resolveCountryCodes(string $proxyType, string $proxySubtype, array $customize): array
    {
        $preset = array_keys((array)($customize['available_countries'] ?? []));
        $assets = $this->getAvailableAssets();
        $available = array_keys((array)($assets[$proxyType][$proxySubtype]['available_countries'] ?? []));

        if (!empty($available)) {
            sort($available);
            return $available;
        }

        if (!empty($preset)) {
            sort($preset);
            return $preset;
        }

        return ['US'];
    }

    private function pickDefaultCountry(array $query, array $customize): string
    {
        $queryCountries = array_keys((array)($query['proxy_countries'] ?? []));
        if (!empty($queryCountries)) {
            return strtoupper((string)$queryCountries[0]);
        }

        $countries = array_keys((array)($customize['available_countries'] ?? []));
        if (!empty($countries)) {
            return strtoupper((string)$countries[0]);
        }

        return 'US';
    }

    private function buildCountryDict(array $codes, string $defaultCountry): string
    {
        $dict = ['随机='];
        if ($defaultCountry !== '' && !in_array($defaultCountry, $codes, true)) {
            array_unshift($codes, $defaultCountry);
        }

        foreach (array_values(array_unique($codes)) as $code) {
            $upperCode = strtoupper((string)$code);
            $label = self::COUNTRY_LABELS[$upperCode] ?? $upperCode;
            $dict[] = $label . '=' . $upperCode;
        }

        return implode(',', $dict);
    }

    private function syncFactoryPrice(Commodity $commodity, float $cost): void
    {
        $cost = (float)sprintf('%.4f', $cost);
        if ((float)$commodity->factory_price === $cost) {
            return;
        }

        Commodity::query()->where('id', $commodity->id)->update(['factory_price' => $cost]);
        $commodity->factory_price = $cost;
    }

    private function toPositiveInt(string $value, int $default): int
    {
        if ($value === '' || !is_numeric($value)) {
            return $default;
        }

        return max(0, (int)$value);
    }

    private function toBool(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function normalizeBoolString(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $string = strtolower(trim((string)$value));
        return in_array($string, ['1', 'true', 'yes', 'on'], true) ? '1' : '0';
    }

    private function formatSecret(array $payload, array $response): string
    {
        $lines = [
            'Webshare 开通成功',
            '代理类型：' . ($payload['proxy_type'] ?? '-'),
            '代理子类型：' . ($payload['proxy_subtype'] ?? '-'),
            '代理数量：' . ($payload['proxy_count'] ?? '-'),
            '流量上限(GB)：' . ($payload['bandwidth_limit'] ?? '-'),
            '定期更换(秒)：' . ($payload['automatic_refresh_frequency'] ?? '-'),
            '订阅周期：' . ($payload['term'] ?? '-'),
        ];

        if (!empty($payload['proxy_countries']) && is_array($payload['proxy_countries'])) {
            $countryCodes = array_keys($payload['proxy_countries']);
            if (!empty($countryCodes[0])) {
                $lines[] = '国家代码：' . $countryCodes[0];
            }
        }

        foreach (['id', 'username', 'password', 'proxy_list_download_api', 'proxy_list_download_url'] as $key) {
            if (isset($response[$key]) && $response[$key] !== '') {
                $lines[] = $key . '：' . (is_array($response[$key]) ? json_encode($response[$key], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $response[$key]);
            }
        }

        $lines[] = '原始响应：';
        $lines[] = json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

        return implode(PHP_EOL, $lines);
    }
}
