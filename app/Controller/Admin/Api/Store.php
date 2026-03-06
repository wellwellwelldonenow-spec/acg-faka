<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;

use App\Controller\Base\API\Manage;
use App\Entity\Query\Delete;
use App\Entity\Query\Get;
use App\Entity\Query\Save;
use App\Interceptor\ManageSession;
use App\Model\ManageLog;
use App\Model\Shared;
use App\Service\Image;
use App\Service\Query;
use App\Util\Date;
use App\Util\FileCache;
use App\Util\Ini;
use App\Util\Str;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Context\Interface\Request;
use Kernel\Exception\JSONException;
use Kernel\Waf\Filter;

#[Interceptor(ManageSession::class, Interceptor::TYPE_API)]
class Store extends Manage
{
    private const ITEMS_CACHE_TTL = 86400;
    private const ITEMS_DEFAULT_LIMIT = 20;
    private const ITEMS_MAX_LIMIT = 100;

    #[Inject]
    private Query $query;

    #[Inject]
    private \App\Service\Shared $shared;

    #[Inject]
    private Image $image;

    public function data(): array
    {
        $map = $_POST;
        $get = new Get(Shared::class);
        $get->setPaginate((int)$this->request->post("page"), (int)$this->request->post("limit"));
        $get->setWhere($map);
        $data = $this->query->get($get);
        return $this->json(data: $data);
    }

    public function save(): array
    {
        $map = $_POST;

        if (!$map['domain']) {
            throw new JSONException("店铺地址不能为空");
        }

        if (!$map['app_id']) {
            throw new JSONException("商户ID不能为空");
        }

        if (!$map['app_key']) {
            throw new JSONException("商户密钥不能为空");
        }

        $map['domain'] = trim($map['domain'], "/");

        $connect = $this->shared->connect($map['domain'], $map['app_id'], $map['app_key'], (int)$map['type']);

        $map['name'] = strip_tags((string)$connect['shopName']);
        $map['balance'] = (float)$connect['balance'];

        $save = new Save(Shared::class);
        $save->setMap($map);
        $save->enableCreateTime();
        $save = $this->query->save($save);
        if (!$save) {
            throw new JSONException("保存失败，请检查信息填写是否完整");
        }

        if (isset($save['id'])) {
            $savedStore = Shared::query()->find((int)$save['id']);
            if ($savedStore) {
                $this->clearItemsCache($savedStore);
                $this->dispatchItemsCacheWarmup($savedStore, true);
            }
        }

        ManageLog::log($this->getManage(), "[修改/新增]共享店铺");
        return $this->json(200, '（＾∀＾）保存成功');
    }

    public function connect(): array
    {
        $id = (int)$_POST['id'];
        $shared = Shared::query()->find($id);

        if (!$shared) {
            throw new JSONException("未找到该店铺");
        }
        $connect = $this->shared->connect($shared->domain, $shared->app_id, $shared->app_key, $shared->type);
        $shared->name = strip_tags((string)$connect['shopName']);
        $shared->balance = (float)$connect['balance'];
        $shared->save();
        $this->dispatchItemsCacheWarmup($shared, false);
        return $this->json(200, 'success');
    }

    public function items(): array
    {
        $id = (int)$_POST['id'];
        $shared = Shared::query()->find($id);

        if (!$shared) {
            throw new JSONException("未找到该店铺");
        }

        $refresh = (int)($_POST['refresh'] ?? 0) === 1;
        $keyword = trim((string)($_POST['keyword'] ?? ''));
        $category = trim((string)($_POST['category'] ?? ''));
        $page = max(1, (int)($_POST['page'] ?? 1));
        $limit = (int)($_POST['limit'] ?? self::ITEMS_DEFAULT_LIMIT);
        $limit = $limit <= 0 ? self::ITEMS_DEFAULT_LIMIT : min($limit, self::ITEMS_MAX_LIMIT);

        $cache = FileCache::getJsonFile('shared_items', $this->getItemsCacheKey($shared));

        if ($this->isItemsDatasetReady($cache)) {
            $payload = $this->buildItemsPayload($cache, $keyword, $category, $page, $limit);
            if ($refresh) {
                $this->dispatchItemsCacheWarmup($shared, true);
                $payload['refreshing'] = 1;
                return $this->json(200, '已提交后台刷新，当前先展示缓存货源', $payload);
            }
            return $this->json(200, 'success', $payload);
        }

        $this->dispatchItemsCacheWarmup($shared, true);
        return $this->json(200, '正在同步远端商品，请稍后重试', [
            'building' => 1,
            'summary' => [
                'item_total' => 0,
                'category_total' => 0,
                'generated_at' => null,
            ],
            'categories' => [],
            'list' => [],
            'items' => [],
            'page' => $page,
            'limit' => $limit,
            'total' => 0,
            'keyword' => $keyword,
            'category' => $category,
        ]);
    }

    public function addItem(Request $request): array
    {
        $map = $request->post(flags: Filter::NORMAL);

        $categoryId = (int)$map['category_id'];
        $storeId = (int)($map['store_id'] ?? ($_GET['storeId'] ?? 0));
        $items = (array)($map['items'] ?? []);
        $codes = array_values(array_filter((array)($map['codes'] ?? []), static fn($code) => (string)$code !== '' && (string)$code !== '0'));
        if (empty($codes) && !empty($map['codes_json'])) {
            $codes = array_values(array_filter((array)json_decode((string)$map['codes_json'], true), static fn($code) => (string)$code !== '' && (string)$code !== '0'));
        }
        $premium = (float)$map['premium'];
        $premiumType = (int)$map['premium_type'];
        $imageDownload = (bool)$map['image_download'];
        $shelves = (int)$map['shelves'] == 0 ? 0 : 1;

        $shared = Shared::query()->find($storeId);

        if (!$shared) {
            throw new JSONException("未找到该店铺");
        }

        if (empty($items) && empty($codes)) {
            throw new JSONException("至少选择一个远端店铺的商品");
        }

        $date = Date::current();
        $count = empty($items) ? count($codes) : count($items);
        $success = 0;
        $error = 0;

        if (!empty($items)) {
            foreach ($items as $item) {
                try {
                    $this->cloneSharedItem($shared, $item, $categoryId, $premium, $premiumType, $imageDownload, $shelves, $date);
                    $success++;
                } catch (\Exception $e) {
                    $error++;
                }
            }
        } else {
            foreach ($codes as $code) {
                try {
                    $item = $this->shared->item($shared, (string)$code);
                    $this->cloneSharedItem($shared, $item, $categoryId, $premium, $premiumType, $imageDownload, $shelves, $date);
                    $success++;
                } catch (\Exception $e) {
                    $error++;
                }
            }
        }

        ManageLog::log($this->getManage(), "[店铺共享]进行了克隆商品({$shared->name})，总数量：{$count}，成功：{$success}，失败：{$error}");
        return $this->json(200, "拉取结束，总数量：{$count}，成功：{$success}，失败：{$error}");
    }

    public function del(): array
    {
        $ids = array_map('intval', (array)$_POST['list']);
        $deleteBatchEntity = new Delete(Shared::class, $ids);
        $count = $this->query->delete($deleteBatchEntity);
        if ($count == 0) {
            throw new JSONException("没有移除任何数据");
        }

        foreach ($ids as $id) {
            $store = new Shared();
            $store->id = $id;
            $this->clearItemsCache($store);
        }
        FileCache::clearCache('shared_items');

        ManageLog::log($this->getManage(), "[店铺共享]删除操作，共计：" . count($ids));
        return $this->json(200, '（＾∀＾）移除成功');
    }

    private function cloneSharedItem(Shared $shared, array $item, int $categoryId, float $premium, int $premiumType, bool $imageDownload, int $shelves, string $date): void
    {
        $commodity = new \App\Model\Commodity();
        $commodity->category_id = $categoryId;
        $commodity->name = $item['name'];
        $commodity->description = $item['description'];

        preg_match_all('#<img.*?src="(/.*?)"#', $commodity->description, $matchs);
        $list = (array)$matchs[1];

        if (count($list) > 0) {
            foreach ($list as $entry) {
                if ($imageDownload) {
                    $download = $this->image->downloadRemoteImage($shared->domain . $entry);
                    $commodity->description = str_replace($entry, $download[0], $commodity->description);
                } else {
                    $commodity->description = str_replace($entry, $shared->domain . $entry, $commodity->description);
                }
            }
        }

        if ($imageDownload) {
            $download = $this->image->downloadRemoteImage($shared->domain . $item['cover']);
            $commodity->cover = $download[0];
        } else {
            $commodity->cover = $shared->domain . $item['cover'];
        }

        $commodity->status = $shelves;
        $commodity->owner = 0;
        $commodity->create_time = $date;
        $commodity->api_status = 0;
        $commodity->code = strtoupper(Str::generateRandStr(16));
        $commodity->delivery_way = 1;
        $commodity->contact_type = $item['contact_type'];
        $commodity->password_status = $item['password_status'];
        $commodity->sort = 0;
        $commodity->coupon = 0;
        $commodity->shared_id = (int)$shared->id;
        $commodity->shared_code = $item['code'];
        $commodity->shared_premium = $premium;
        $commodity->shared_premium_type = $premiumType;
        $commodity->seckill_status = $item['seckill_status'];

        if ($commodity->seckill_status == 1) {
            $commodity->seckill_start_time = $item['seckill_start_time'];
            $commodity->seckill_end_time = $item['seckill_end_time'];
        }

        $commodity->draft_status = $item['draft_status'];

        if ($commodity->draft_status) {
            $commodity->draft_premium = $this->shared->AdjustmentAmount($premiumType, $premium, $item['draft_premium']);
        }

        $commodity->inventory_hidden = $item['inventory_hidden'];
        $commodity->only_user = $item['only_user'];
        $commodity->purchase_count = $item['purchase_count'];
        $commodity->widget = $item['widget'];
        $commodity->minimum = $item['minimum'];
        if (!empty($item['stock'])) {
            $commodity->stock = $item['stock'];
        }

        $sourceConfig = is_array($item['config']) ? Ini::toConfig($item['config']) : (string)$item['config'];

        $config = $this->shared->AdjustmentPrice($sourceConfig, (string)$item['price'], (string)$item['user_price'], $premiumType, $premium);

        $commodity->config = Ini::toConfig($config['config']);
        $commodity->price = $config['price'];
        $commodity->user_price = $config['user_price'];

        $commodity->save();
    }

    private function isItemsDatasetReady(array $cache): bool
    {
        return isset($cache['meta'], $cache['categories'], $cache['items']) && is_array($cache['categories']) && is_array($cache['items']);
    }

    private function buildItemsPayload(array $cache, string $keyword, string $category, int $page, int $limit): array
    {
        $items = $cache['items'];
        $filtered = array_values(array_filter($items, function (array $item) use ($keyword, $category) {
            if ($category !== '' && (string)($item['category_id'] ?? '') !== $category) {
                return false;
            }

            if ($keyword === '') {
                return true;
            }

            $haystack = (string)($item['keywords'] ?? '');
            return stripos($haystack, $keyword) !== false;
        }));

        $total = count($filtered);
        $offset = ($page - 1) * $limit;
        $list = array_slice($filtered, $offset, $limit);

        return [
            'building' => 0,
            'summary' => $cache['meta'],
            'categories' => $cache['categories'],
            'list' => $list,
            'items' => $list,
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'keyword' => $keyword,
            'category' => $category,
        ];
    }

    private function buildItemsDataset(array $items): array
    {
        $flatItems = [];
        $categories = [];

        $walk = function (array $nodes, string $categoryName = '未分类', string $categoryId = '') use (&$walk, &$flatItems, &$categories) {
            foreach ($nodes as $node) {
                $children = isset($node['children']) && is_array($node['children']) ? $node['children'] : [];
                $name = strip_tags((string)($node['name'] ?? '未命名商品'));
                $code = trim((string)($node['code'] ?? $node['id'] ?? ''));

                if (!empty($children) && ($code === '' || $code === '0')) {
                    $nextCategoryName = $name !== '' ? $name : $categoryName;
                    $nextCategoryId = 'category_' . substr(md5($nextCategoryName), 0, 16);
                    $walk($children, $nextCategoryName, $nextCategoryId);
                    continue;
                }

                if ($code === '' || $code === '0') {
                    continue;
                }

                $currentCategoryName = $categoryName !== '' ? $categoryName : '未分类';
                $currentCategoryId = $categoryId !== '' ? $categoryId : 'category_' . substr(md5($currentCategoryName), 0, 16);

                if (!isset($categories[$currentCategoryId])) {
                    $categories[$currentCategoryId] = [
                        'id' => $currentCategoryId,
                        'name' => $currentCategoryName,
                        'count' => 0,
                    ];
                }
                $categories[$currentCategoryId]['count']++;

                $flatItems[] = [
                    'code' => $code,
                    'name' => $name,
                    'category' => $currentCategoryName,
                    'category_id' => $currentCategoryId,
                    'price' => (string)($node['price'] ?? '0'),
                    'user_price' => (string)($node['user_price'] ?? ($node['price'] ?? '0')),
                    'stock' => (string)($node['stock'] ?? ''),
                    'keywords' => strtolower($name . ' ' . $currentCategoryName . ' ' . $code),
                ];
            }
        };

        $walk($items);

        usort($flatItems, static function (array $left, array $right) {
            return strcmp((string)$left['name'], (string)$right['name']);
        });

        $categoryList = array_values($categories);
        usort($categoryList, static function (array $left, array $right) {
            return strcmp((string)$left['name'], (string)$right['name']);
        });

        return [
            'meta' => [
                'item_total' => count($flatItems),
                'category_total' => count($categoryList),
                'generated_at' => Date::current(),
            ],
            'categories' => $categoryList,
            'items' => $flatItems,
        ];
    }

    private function getItemsCacheKey(Shared $shared): string
    {
        return 'store_' . $shared->id;
    }

    private function getItemsWarmKey(Shared $shared): string
    {
        return $this->getItemsCacheKey($shared) . '_warming';
    }

    private function clearItemsCache(Shared $shared): void
    {
        @unlink(BASE_PATH . '/runtime/shared_items/' . $this->getItemsCacheKey($shared) . '.json');
        @unlink(BASE_PATH . '/runtime/shared_items/' . $this->getItemsWarmKey($shared) . '.json');
    }

    private function dispatchItemsCacheWarmup(Shared $shared, bool $force = false): void
    {
        $cacheKey = $this->getItemsCacheKey($shared);
        if (!$force && $this->isItemsDatasetReady(FileCache::getJsonFile('shared_items', $cacheKey))) {
            return;
        }

        $warmKey = $this->getItemsWarmKey($shared);
        $warming = FileCache::getJsonFile('shared_items', $warmKey);
        if (!$force && !empty($warming['warming'])) {
            return;
        }

        FileCache::setJsonFile('shared_items', $warmKey, ['warming' => 1], 120);
        $sharedId = (int)$shared->id;

        register_shutdown_function(function () use ($sharedId, $warmKey, $cacheKey) {
            ignore_user_abort(true);
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            @set_time_limit(0);

            try {
                $store = Shared::query()->find($sharedId);
                if ($store) {
                    $dataset = $this->buildItemsDataset((array)$this->shared->items($store));
                    FileCache::setJsonFile('shared_items', $cacheKey, $dataset, self::ITEMS_CACHE_TTL);
                }
            } catch (\Throwable) {
            }

            FileCache::setJsonFile('shared_items', $warmKey, ['warming' => 0], 1);
        });
    }
}
