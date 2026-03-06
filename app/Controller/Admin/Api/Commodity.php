<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;


use App\Controller\Base\API\Manage;
use App\Entity\Query\Delete;
use App\Entity\Query\Get;
use App\Entity\Query\Save;
use App\Interceptor\ManageSession;
use App\Model\ManageLog;
use App\Service\Query;
use App\Service\Webshare;
use App\Util\Client;
use App\Util\Date;
use App\Util\Ini;
use App\Util\Str;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Container\Di;
use Kernel\Context\Interface\Request;
use Kernel\Exception\JSONException;
use Kernel\Exception\NotFoundException;
use Kernel\Exception\RuntimeException;
use Kernel\Waf\Filter;

#[Interceptor(ManageSession::class, Interceptor::TYPE_API)]
class Commodity extends Manage
{
    #[Inject]
    private Query $query;

    #[Inject]
    private Webshare $webshare;

    /**
     * @return array
     */
    public function data(): array
    {
        $map = $_POST;
        $get = new Get(\App\Model\Commodity::class);
        $get->setPaginate((int)$this->request->post("page"), (int)$this->request->post("limit"));
        $get->setWhere($map);
        $get->setOrderBy(...$this->query->getOrderBy($map, "sort", "asc"));

        $data = $this->query->get($get, function (Builder $builder) use ($map) {
            if (isset($map['display_scope'])) {
                if ($map['display_scope'] == 1) {
                    $builder = $builder->where("owner", 0);
                } elseif ($map['display_scope'] == 2) {
                    if (isset($map['user_id']) && $map['user_id'] > 0) {
                        $builder = $builder->where("owner", $map['user_id']);
                    } else {
                        $builder = $builder->where("owner", "!=", 0);
                    }
                }
            }

            return $builder->with(['shared', 'category', 'owner' => function (Relation $relation) {
                $relation->with(['business' => function (Relation $relation) {
                    $relation->select(['id', 'user_id', 'subdomain', 'topdomain']);
                }])->select(["id", "username", "avatar"]);
            }])->withCount([
                'card as card_count' => function (Builder $builder) {
                    $builder->where("status", 0);
                },
                'card as card_success_count' => function (Builder $builder) {
                    $builder->where("status", 1);
                },
                //商品总盈利
                'order as order_all_amount' => function (Builder $relation) {
                    $relation->where("status", 1)->select(\App\Model\Order::query()->raw("COALESCE(sum(amount),0) as order_all_amount"));
                },
                //过去7天内盈利
                'order as order_week_amount' => function (Builder $relation) {
                    $relation->whereBetween('create_time', [Date::weekDay(1, Date::TYPE_START), Date::weekDay(7, Date::TYPE_END)])->where("status", 1)->select(\App\Model\Order::query()->raw("COALESCE(sum(amount),0) as order_week_amount"));
                },
                //昨日盈利
                'order as order_yesterday_amount' => function (Builder $relation) {
                    $relation->whereBetween('create_time', [Date::calcDay(-1), Date::calcDay()])->where("status", 1)->select(\App\Model\Order::query()->raw("COALESCE(sum(amount),0) as order_yesterday_amount"));
                },
                //今日盈利
                'order as order_today_amount' => function (Builder $relation) {
                    $relation->whereBetween('create_time', [Date::calcDay(), Date::calcDay(1)])->where("status", 1)->select(\App\Model\Order::query()->raw("COALESCE(sum(amount),0) as order_today_amount"));
                }
            ]);
        });

        $clientUrl = Client::getUrl();
        foreach ($data['list'] as &$val) {
            $url = $clientUrl;
            if ($val['owner'] && $val['owner']['business']) {
                if ($val['owner']['business']['subdomain']) {
                    $url = "https://" . $val['owner']['business']['subdomain'];
                }
                if ($val['owner']['business']['topdomain']) {
                    $url = "https://" . $val['owner']['business']['topdomain'];
                }
            }
            $val['share_url'] = $url . "/item/{$val['id']}";
            $val['webshare_supported'] = false;

            try {
                $commodity = new \App\Model\Commodity();
                $commodity->forceFill($val);
                $val['webshare_supported'] = $this->webshare->isSupportedCommodity($commodity);
            } catch (\Throwable) {
            }

            $val['upstream_cost'] = (float)($val['factory_price'] ?? 0);
            $val['upstream_price'] = (float)($val['factory_price'] ?? 0);
            $val['upstream_currency'] = 'CNY';

            $price = (float)($val['price'] ?? 0);
            $userPrice = (float)($val['user_price'] ?? 0);
            $upstreamCost = (float)($val['upstream_cost'] ?? 0);
            $val['margin_amount'] = round($price - $upstreamCost, 2);
            $val['margin_rate'] = $price > 0 ? round(($val['margin_amount'] / $price) * 100, 2) : null;
            $val['user_margin_amount'] = round($userPrice - $upstreamCost, 2);
            $val['user_margin_rate'] = $userPrice > 0 ? round(($val['user_margin_amount'] / $userPrice) * 100, 2) : null;
        }


        return $this->json(data: $data);
    }


    /**
     * @param Request $request
     * @return array
     * @throws JSONException
     * @throws NotFoundException
     * @throws RuntimeException
     * @throws \ReflectionException
     */
    public function save(Request $request): array
    {
        $map = $request->post(flags: Filter::NORMAL);

        //create new
        if ((int)$map['id'] == 0) {

            if (!$map['name']) {
                throw new JSONException("商品名称不能为空哦(｡￫‿￩｡)");
            }

            if ((float)$map['price'] < 0 || (float)$map['user_price'] < 0) {
                throw new JSONException("商品单价不能低于0元哦(｡￫‿￩｡)");
            }

            //--init
            $map['owner'] = 0;
            $map['code'] = strtoupper(Str::generateRandStr(16));
        }

        //如果选择了别人平台
        if ((int)$map['shared_id'] != 0) {
            $map['delivery_way'] = 0;
            if (!$map['shared_code']) {
                throw new JSONException("您选择了对接别人店铺，所以要填写商品对接代码哦(｡￫‿￩｡)");
            }
        }

        if ($map['seckill_status'] == 1) {
            if (!$map['seckill_start_time'] || !$map['seckill_end_time']) {
                throw new JSONException("您开启了秒杀功能，所以请指定秒杀的开始时间和结束时间哦(｡￫‿￩｡)");
            }
            if (strtotime($map['seckill_end_time']) < strtotime($map['seckill_start_time'])) {
                throw new JSONException("秒杀结束时间不能低于秒杀开始时间哦，请认真指定秒杀结束时间(｡￫‿￩｡)");
            }
        }

        if ($map['draft_status'] == 1) {
            if ($map['draft_premium'] === "") {
                throw new JSONException("您开启了预选卡密功能，请填写预选时的溢价(｡￫‿￩｡)");
            }
        }

        //解析配置文件
        if ($map['config']) {
            Ini::toArray($map['config']);
        }

        $save = new Save(\App\Model\Commodity::class);
        $save->setMap($map);
        $save->enableCreateTime();
        $save = $this->query->save($save);
        if (!$save) {
            throw new JSONException("保存失败，请检查信息填写是否完整");
        }

        ManageLog::log($this->getManage(), "[修改/新增]商品");
        return $this->json(200, '（＾∀＾）保存成功');
    }


    /**
     * @return array
     * @throws JSONException
     * @throws NotFoundException
     * @throws \ReflectionException
     */
    public function del(): array
    {
        $deleteBatchEntity = new Delete(\App\Model\Commodity::class, $_POST['list']);
        $count = $this->query->delete($deleteBatchEntity);
        if ($count == 0) {
            throw new JSONException("没有移除任何数据");
        }

        ManageLog::log($this->getManage(), "[删除]商品");
        return $this->json(200, '（＾∀＾）移除成功');
    }

    /**
     * @return array
     */
    public function status(): array
    {
        $list = (array)$_POST['list'];
        $status = (int)$_POST['status'];
        \App\Model\Commodity::query()->whereIn('id', $list)->update(['status' => $status]);
        ManageLog::log($this->getManage(), "[批量更新]商品启停状态");
        return $this->json(200, '商品状态已经更新');
    }


    /**
     * @return array
     */
    public function fastEnable(): array
    {
        $list = (array)explode(",", (string)$_POST['list']);
        unset($_POST['list']);
        foreach ($_POST as $key => $val) {
            if ($val == 0) {
                $_POST[$key] = 0;
            } else {
                $_POST[$key] = 1;
            }
        }
        \App\Model\Commodity::query()->whereIn('id', $list)->update($_POST);
        ManageLog::log($this->getManage(), "[批量更新]商品状态");
        return $this->json(200, '更新成功');
    }

    /**
     * @throws JSONException
     */
    public function websharePreset(): array
    {
        $preset = (string)($_GET['preset'] ?? 'subscription_residential');
        return $this->json(200, 'success', $this->webshare->getPreset($preset));
    }

    public function webshareSyncCost(): array
    {
        try {
            $id = (int)($_POST['id'] ?? 0);
            if ($id < 1) {
                throw new JSONException('商品ID不能为空');
            }

            $commodity = \App\Model\Commodity::query()->find($id);
            if (!$commodity) {
                throw new JSONException('商品不存在');
            }

            if (!$this->webshare->isSupportedCommodity($commodity)) {
                throw new JSONException('当前商品不是 Webshare 商品');
            }

            $result = $this->webshare->syncCommodityCost($commodity);
            return $this->json(200, '同步成功', [
                'id' => $commodity->id,
                'factory_price' => (float)($result['cost'] ?? $commodity->factory_price),
                'upstream_price' => (float)($result['price'] ?? $commodity->factory_price),
                'upstream_currency' => (string)($result['currency'] ?? 'USD'),
                'payload' => $result['payload'] ?? [],
            ]);
        } catch (\Throwable $e) {
            return $this->json(500, '同步失败：' . $e->getMessage());
        }
    }

    public function webshareConvert(): array
    {
        $list = $_POST['list'] ?? [];
        $id = (int)($_POST['id'] ?? 0);

        if ($id > 0) {
            $list = [$id];
        }

        if (!is_array($list)) {
            $list = [$list];
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $list))));
        if (empty($ids)) {
            return $this->json(500, '请选择要转换的商品');
        }

        $success = 0;
        $fail = [];

        $commodities = \App\Model\Commodity::query()->whereIn('id', $ids)->get();
        foreach ($commodities as $commodity) {
            try {
                if ($this->webshare->isSupportedCommodity($commodity)) {
                    $success++;
                    continue;
                }

                if (!$commodity->shared || ($commodity->shared->app_id ?? '') !== 'webshare') {
                    throw new JSONException('不是 Webshare 共享商品');
                }

                $payload = $this->webshare->convertSharedCommodity($commodity);
                \App\Model\Commodity::query()->where('id', $commodity->id)->update([
                    'shared_id' => 0,
                    'shared_code' => '',
                    'shared_premium' => 0,
                    'shared_premium_type' => 0,
                    'shared_sync' => 0,
                    'inventory_sync' => 0,
                    'delivery_way' => 1,
                    'widget' => $payload['widget'],
                    'config' => $payload['config'],
                    'stock' => $payload['stock'],
                ]);
                $success++;
            } catch (\Throwable $e) {
                $fail[] = [
                    'id' => $commodity->id,
                    'name' => $commodity->name,
                    'msg' => $e->getMessage(),
                ];
            }
        }

        ManageLog::log($this->getManage(), '[Webshare共享商品转直连]成功:' . $success . ' 失败:' . count($fail));
        return $this->json(200, '转换完成', [
            'success' => $success,
            'fail' => $fail,
        ]);
    }
}
