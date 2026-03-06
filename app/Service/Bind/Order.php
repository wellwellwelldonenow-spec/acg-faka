<?php
declare(strict_types=1);

namespace App\Service\Bind;


use App\Consts\Hook;
use App\Entity\PayEntity;
use App\Model\Bill;
use App\Model\Business;
use App\Model\BusinessLevel;
use App\Model\Card;
use App\Model\Commodity;
use App\Model\CommodityGroup;
use App\Model\Config;
use App\Model\Coupon;
use App\Model\OrderOption;
use App\Model\Pay;
use App\Model\User;
use App\Model\UserCommodity;
use App\Model\UserGroup;
use App\Service\Email;
use App\Service\Shared;
use App\Service\Webshare;
use App\Util\Client;
use App\Util\Date;
use App\Util\Ini;
use App\Util\PayConfig;
use App\Util\Str;
use Illuminate\Database\Capsule\Manager as DB;
use Kernel\Annotation\Inject;
use Kernel\Container\Di;
use Kernel\Exception\JSONException;
use Kernel\Exception\RuntimeException;
use Kernel\Util\Arr;
use Kernel\Util\Context;
use Kernel\Util\Decimal;

class Order implements \App\Service\Order
{
    #[Inject]
    private Shared $shared;

    #[Inject]
    private Email $email;

    #[Inject]
    private Webshare $webshare;


    /**
     * @param int $owner
     * @param int $num
     * @param Commodity $commodity
     * @param UserGroup|null $group
     * @param string|null $race
     * @param bool $disableSubstation
     * @return float
     * @throws JSONException
     */
    public function calcAmount(int $owner, int $num, Commodity $commodity, ?UserGroup $group, ?string $race = null, bool $disableSubstation = false): float
    {
        $premium = 0;

        //检测分站价格
        $bus = Business::get(Client::getDomain());
        if ($bus && !$disableSubstation) {
            if ($userCommodity = UserCommodity::getCustom($bus->user_id, $commodity->id)) {
                $premium = (float)$userCommodity->premium;
            }
        }

        //解析配置文件
        $this->parseConfig($commodity, $group);
        $price = $owner == 0 ? $commodity->price : $commodity->user_price;

        //禁用任何折扣,直接计算
        if ($commodity->level_disable == 1) {
            return (int)(string)(($num * ($price + $premium)) * 100) / 100;
        }

        $userDefinedConfig = Commodity::parseGroupConfig((string)$commodity->level_price, $group);


        if ($userDefinedConfig && $userDefinedConfig['amount'] > 0) {
            if (!$commodity->race) {
                //如果自定义价格成功，那么将覆盖其他价格
                $price = $userDefinedConfig['amount'];
            }
        } elseif ($group) {
            //如果没有对应的会员等级解析，那么就直接采用系统折扣
            $price = $price - ($price * $group->discount);
        }

        //判定是race还是普通订单
        if (is_array($commodity->race)) {
            if (array_key_exists((string)$race, (array)$commodity->category_wholesale)) {
                //判定当前race是否可以折扣
                $list = $commodity->category_wholesale[$race];
                krsort($list);
                foreach ($list as $k => $v) {
                    if ($num >= $k) {
                        $price = $v;
                        break;
                    }
                }
            }
        } else {
            //普通订单，直接走批发
            $list = (array)$commodity->wholesale;
            krsort($list);
            foreach ($list as $k => $v) {
                if ($num >= $k) {
                    $price = $v;
                    break;
                }
            }
        }

        $price += $premium; //分站加价
        return (int)(string)(($num * $price) * 100) / 100;
    }


    /**
     * @param Commodity|int $commodity
     * @param int $num
     * @param string|null $race
     * @param array|null $sku
     * @param int|null $cardId
     * @param string|null $coupon
     * @param UserGroup|null $group
     * @return string
     * @throws JSONException
     * @throws \ReflectionException
     */
    public function valuation(Commodity|int $commodity, int $num = 1, ?string $race = null, ?array $sku = [], ?int $cardId = null, ?string $coupon = null, ?UserGroup $group = null): string
    {
        if (is_int($commodity)) {
            $commodity = Commodity::query()->find($commodity);
        }

        if (!$commodity) {
            throw new JSONException("商品不存在");
        }

        $commodity = clone $commodity;

        //解析配置文件
        $this->parseConfig($commodity, $group);
        $price = (new Decimal($group ? $commodity->user_price : $commodity->price, 2));

        //算出race价格
        if (!empty($race) && !empty($commodity->config['category'])) {
            $_race = $commodity->config['category'];

            if (!isset($_race[$race])) {
                throw new JSONException("此商品类型不存在[{$race}]");
            }

            $price = (new Decimal($_race[$race], 2));
            if (is_array($commodity->config['category_wholesale'])) {
                if (array_key_exists($race, $commodity->config['category_wholesale'])) {
                    $list = $commodity->config['category_wholesale'][$race];
                    krsort($list);
                    foreach ($list as $k => $v) {
                        if ($num >= $k) {
                            $price = (new Decimal($v, 2));
                            break;
                        }
                    }
                }

            }

        } else {
            if (is_array($commodity->config['wholesale'])) {
                $list = $commodity->config['wholesale'];
                krsort($list);
                foreach ($list as $k => $v) {
                    if ($num >= $k) {
                        $price = (new Decimal($v, 2));
                        break;
                    }
                }
            }
        }

        //算出sku价格
        if (!empty($sku) && !empty($commodity->config['sku'])) {
            $_sku = $commodity->config['sku'];

            foreach ($sku as $k => $v) {
                if (!isset($_sku[$k])) {
                    throw new JSONException("此SKU不存在[{$k}]");
                }

                if (!isset($_sku[$k][$v])) {
                    throw new JSONException("此SKU不存在[{$v}]");
                }

                $_sku_price = $_sku[$k][$v] ?: 0;

                if (is_numeric($_sku_price) && $_sku_price > 0) {
                    $price = $price->add($_sku_price); //sku加价
                }
            }
        }


        //card自选加价
        if (!empty($cardId) && $commodity->draft_status == 1 && $num == 1) {

            /**
             * @var \App\Service\Shop $shop
             */
            $shop = Di::inst()->make(\App\Service\Shop::class);

            if ($commodity->shared) {
                $draft = $this->shared->getDraft($commodity->shared, $commodity->shared_code, $cardId);
                $draftPremium = $draft['draft_premium'] > 0 ? $this->shared->AdjustmentAmount($commodity->shared_premium_type, $commodity->shared_premium, $draft['draft_premium']) : 0;
            } else {
                $draft = $shop->getDraft($commodity, $cardId);
                $draftPremium = $draft['draft_premium'];
            }

            if ($draftPremium > 0) {
                $price = $price->add($draftPremium); //卡密独立加价
            } else {
                $price = $price->add($commodity->draft_premium);
            }
        }


        //禁用任何折扣,直接计算
        if ($commodity->level_disable == 1) {
            return $price->mul($num)->getAmount();
        }


        //商品组优惠
        if ($group && is_array($group->discount_config)) {
            $discountConfig = $group->discount_config;
            asort($discountConfig);
            $commodityGroups = CommodityGroup::query()->whereIn("id", array_keys($discountConfig))->get();

            foreach ($commodityGroups as $commodityGroup) {
                if (is_array($commodityGroup->commodity_list) && in_array($commodity->id, $commodityGroup->commodity_list)) {
                    $price = $price->mul((new Decimal($discountConfig[$commodityGroup->id], 3))->div(100)->getAmount());
                    break;
                }
            }
        }

        //优惠券折扣计算
        if (!empty($coupon) && $num == 1) {
            $voucher = Coupon::query()->where("code", $coupon)->first();

            if (!$voucher) {
                throw new JSONException("该优惠券不存在");
            }

            if ($voucher->owner != $commodity->owner) {
                throw new JSONException("该优惠券不存在");
            }

            if ($voucher->commodity_id != 0 && $voucher->commodity_id != $commodity->id) {
                throw new JSONException("该优惠券不属于该商品");
            }

            //race
            if ($voucher->race && $voucher->commodity_id != 0 && $race != $voucher->race) {
                throw new JSONException("该优惠券不能抵扣当前商品");
            }

            //sku
            if ($voucher->sku && is_array($voucher->sku) && $voucher->commodity_id != 0) {
                if (!is_array($sku)) {
                    throw new JSONException("此优惠券不适用当前商品");
                }

                foreach ($voucher->sku as $key => $sk) {
                    if (!isset($sku[$key])) {
                        throw new JSONException("此优惠券不适用此SKU");
                    }

                    if ($sk != $sku[$key]) {
                        throw new JSONException("此优惠券不适用此SKU{$sku[$key]}");
                    }
                }
            }

            //判断该优惠券是否有分类设定
            if ($voucher->commodity_id == 0 && $voucher->category_id != 0 && $voucher->category_id != $commodity->category_id) {
                throw new JSONException("该优惠券不能抵扣当前商品");
            }

            if ($voucher->status != 0) {
                throw new JSONException("该优惠券已失效");
            }

            //检测过期时间
            if ($voucher->expire_time != null && strtotime($voucher->expire_time) < time()) {
                throw new JSONException("该优惠券已过期");
            }

            //检测面额
            if ($voucher->money >= $price->getAmount()) {
                return "0";
            }

            $deduction = $voucher->mode == 0 ? $voucher->money : $price->mul($voucher->money)->getAmount();
            $price = $price->sub($deduction);
        }

        //返回单价
        return $price->mul($num)->getAmount();
    }


    /**
     * @param int $commodityId
     * @param string|float|int $price
     * @param UserGroup|null $group
     * @return string
     */
    public function getValuationPrice(int $commodityId, string|float|int $price, ?UserGroup $group = null): string
    {
        $price = new Decimal($price);

        //商品组优惠
        if ($group && is_array($group->discount_config)) {
            $discountConfig = $group->discount_config;
            asort($discountConfig);
            $commodityGroups = CommodityGroup::query()->whereIn("id", array_keys($discountConfig))->get();

            foreach ($commodityGroups as $commodityGroup) {
                if (is_array($commodityGroup->commodity_list) && in_array($commodityId, $commodityGroup->commodity_list)) {
                    $price = $price->mul((new Decimal($discountConfig[$commodityGroup->id], 3))->div(100)->getAmount());
                    break;
                }
            }
        }

        return $price->getAmount();
    }

    /**
     * 解析配置
     * @param Commodity $commodity
     * @param UserGroup|null $group
     * @return void
     * @throws JSONException
     */
    public function parseConfig(Commodity &$commodity, ?UserGroup $group): void
    {
        $parseConfig = Ini::toArray((string)$commodity->config);

        //用户组解析
        $userDefinedConfig = Commodity::parseGroupConfig($commodity->level_price, $group);

        if ($userDefinedConfig) {
            if (key_exists("category", $userDefinedConfig['config'])) {
                //$parseConfig['category'] = array_merge($parseConfig['category'] ?? [], $userDefinedConfig['config']['category']);
                $parseConfig['category'] = Arr::override($userDefinedConfig['config']['category'] ?? null, $parseConfig['category'] ?? null);
            }

            if (key_exists("wholesale", $userDefinedConfig['config'])) {
                //$parseConfig['wholesale'] = array_merge($parseConfig['wholesale'] ?? [], $userDefinedConfig['config']['wholesale']);
                $parseConfig['wholesale'] = Arr::override($userDefinedConfig['config']['wholesale'] ?? null, $parseConfig['wholesale'] ?? null);
            }

            if (key_exists("category_wholesale", $userDefinedConfig['config'])) {
                //$parseConfig['category_wholesale'] = array_merge($parseConfig['category_wholesale'] ?? [], $userDefinedConfig['config']['category_wholesale']);
                $parseConfig['category_wholesale'] = Arr::override($userDefinedConfig['config']['category_wholesale'] ?? null, $parseConfig['category_wholesale'] ?? null);
            }

            if (key_exists("sku", $userDefinedConfig['config'])) {
                //$parseConfig['sku'] = array_merge($parseConfig['sku'] ?? [], $userDefinedConfig['config']['sku']);
                $parseConfig['sku'] = Arr::override($userDefinedConfig['config']['sku'] ?? null, $parseConfig['sku'] ?? null);
            }
        }

        $commodity->config = $parseConfig;
        $commodity->level_price = null;
    }

    /**
     * @param Commodity $commodity
     * @param UserGroup|null $group
     * @return array|null
     */
    public function userDefinedPrice(Commodity $commodity, ?UserGroup $group): ?array
    {
        if ($group) {
            $levelPrice = (array)json_decode((string)$commodity->level_price, true);
            return array_key_exists($group->id, $levelPrice) ? $levelPrice[$group->id] : null;
        }
        return null;
    }

    /**
     * @param User|null $user
     * @param UserGroup|null $userGroup
     * @param array $map
     * @return array
     * @throws JSONException
     * @throws RuntimeException
     * @throws \ReflectionException
     */
    public function trade(?User $user, ?UserGroup $userGroup, array $map): array
    {
        #CFG begin
        $commodityId = (int)$map['item_id'];//商品ID
        $contact = (string)$map['contact'];//联系方式
        $num = (int)$map['num']; //购买数量
        $cardId = (int)$map['card_id'];//预选的卡号ID
        $payId = (int)$map['pay_id'];//支付方式id
        $device = (int)$map['device'];//设备
        $password = (string)$map['password'];//查单密码
        $coupon = (string)$map['coupon'];//优惠券
        $from = $_COOKIE['promotion_from'] ?? 0;//推广人ID
        $owner = $user == null ? 0 : $user->id;
        $race = (string)$map['race']; //2022/01/09 新增，商品种类功能
        $requestNo = (string)$map['request_no'];
        $sku = $map['sku'] ?: null;
        #CFG end

        if ($user && $user->pid > 0) {
            $from = $user->pid;
        }

        if ($commodityId == 0) {
            throw new JSONException("请选择商品");
        }

        if ($num <= 0) {
            throw new JSONException("至少购买1个");
        }

        /**
         * @var Commodity $commodity
         */
        $commodity = Commodity::with(['shared'])->find($commodityId);


        if (!$commodity) {
            throw new JSONException("商品不存在");
        }

        if ($commodity->status != 1) {
            throw new JSONException("当前商品已停售");
        }

        if ($commodity->only_user == 1 || $commodity->purchase_count > 0) {
            if ($owner == 0) {
                throw new JSONException("请先登录后再购买哦");
            }
        }


        if ($commodity->minimum > 0 && $num < $commodity->minimum) {
            throw new JSONException("本商品最少购买{$commodity->minimum}个");
        }

        if ($commodity->maximum > 0 && $num > $commodity->maximum) {
            throw new JSONException("本商品单次最多购买{$commodity->maximum}个");
        }


        $widget = [];

        //widget
        if ($commodity->widget) {
            $widgetList = (array)json_decode((string)$commodity->widget, true);
            foreach ($widgetList as $item) {
                if ($item['regex'] != "") {
                    if (!preg_match("/{$item['regex']}/", (string)$map[$item['name']])) {
                        throw new JSONException($item['error']);
                    }
                }
                $widget[$item['name']] = [
                    "value" => $map[$item['name']],
                    "cn" => $item['cn']
                ];
            }
        }

        $widget = json_encode($widget, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        //预选卡密
        ($commodity->draft_status == 1 && $cardId != 0) && $num = 1;


        $regx = ['/^1[3456789]\d{9}$/', '/.*(.{2}@.*)$/i', '/[1-9]{1}[0-9]{4,11}/'];
        $msg = ['手机', '邮箱', 'QQ号'];
        //未登录才检测，登录后无需检测

        /**
         * @var \App\Service\Shop $shopService
         */
        $shopService = Di::inst()->make(\App\Service\Shop::class);

        if (!$user) {
            if (mb_strlen($contact) < 3) {
                throw new JSONException("联系方式不能低于3个字符");
            }
            //联系方式正则判断
            if ($commodity->contact_type != 0) {
                if (!preg_match($regx[$commodity->contact_type - 1], $contact)) {
                    throw new JSONException("您输入的{$msg[$commodity->contact_type - 1]}格式不正确！");
                }
            }
            if ($commodity->password_status == 1 && mb_strlen($password) < 6) {
                throw new JSONException("您的设置的密码过于简单，不能低于6位哦");
            }
        }

        if ($commodity->seckill_status == 1) {
            if (time() < strtotime($commodity->seckill_start_time)) {
                throw new JSONException("抢购还未开始");
            }
            if (time() > strtotime($commodity->seckill_end_time)) {
                throw new JSONException("抢购已结束");
            }
        }

        if ($commodity->shared) {
            $stock = $this->shared->getItemStock($commodity->shared, $commodity->shared_code, $race ?: null, $sku ?: []);
        } else {
            $stock = $shopService->getItemStock($commodity, $race, $sku);
        }

        if (($stock == 0 || $num > $stock)) {
            throw new JSONException("库存不足");
        }

        if ($commodity->purchase_count > 0 && $owner > 0) {
            $orderCount = \App\Model\Order::query()->where("owner", $owner)->where("commodity_id", $commodity->id)->count();
            if ($orderCount >= $commodity->purchase_count) {
                throw new JSONException("该商品每人只能购买{$commodity->purchase_count}件");
            }
        }


        //计算订单价格
        $amount = $this->valuation($commodity, $num, $race, $sku, $cardId, $coupon, $userGroup);
        $rebate = 0;
        $divideAmount = 0;

        //分站相关
        $business = Business::get();
        if ($business) {
            $_user = User::query()->find($business->user_id);
            if ($commodity->owner === $business->user_id) {
                //自营商品
                $_level = BusinessLevel::query()->find($_user->business_level);
                $rebate = (new Decimal($amount))->sub((new Decimal($amount))->mul($_level->cost)->getAmount())->getAmount();
            } else {
                //分站提高价格
                $amount = $shopService->getSubstationPrice($commodity, $amount);
                $_userGroup = UserGroup::get($_user->recharge);
                //分站拿到的具体金额
                $rebate = (new Decimal($amount))->sub($this->valuation($commodity, $num, $race, $sku, $cardId, $coupon, $_userGroup))->getAmount();
            }
        } else {
            //主站卖分站的东西
            if ($commodity->owner > 0) {
                $_user = User::query()->find($commodity->owner);
                $_level = BusinessLevel::query()->find($_user->business_level);
                $rebate = (new Decimal($amount))->sub((new Decimal($amount))->mul($_level->cost)->getAmount())->getAmount();
            }
        }

        //推广者
        if ($from > 0 && $commodity->owner != $from && $owner != $from && (!$business || $business->user_id != $from)) {
            //佣金计算
            $x_user = User::query()->find($from);
            $x_userGroup = UserGroup::get($x_user->recharge);
            //推广者具体拿到的金额，计算方法：订单总金额 - 拿货价 = 具体金额
            $x_amount = $this->valuation($commodity, $num, $race, $sku, $cardId, $coupon, $x_userGroup);
            //先判定该订单是否分站或主站
            if ($rebate > 0) {
                $x_amount = $shopService->getSubstationPrice($commodity, $x_amount);
                //分站
                $x_divideAmount = (new Decimal($amount))->sub($x_amount)->getAmount();
                if ($rebate > $x_divideAmount) {
                    //当分站利益大过推广者的时候，才会给推广者进行分成
                    $rebate = (new Decimal($rebate))->sub($x_divideAmount)->getAmount();
                    $divideAmount = $x_divideAmount;
                }
            } else {
                $divideAmount = (new Decimal($amount))->sub($x_amount)->getAmount();
            }
        } else {
            $from = 0;
        }

        $pay = Pay::query()->find($payId);

        if (!$pay) {
            throw new JSONException("该支付方式不存在");
        }

        if ($pay->commodity != 1) {
            throw new JSONException("当前支付方式已停用，请换个支付方式再进行支付");
        }

        //回调地址
        $callbackDomain = trim(Config::get("callback_domain"), "/");
        $clientDomain = Client::getUrl();

        if (!$callbackDomain) {
            $callbackDomain = $clientDomain;
        }

        DB::connection()->getPdo()->exec("set session transaction isolation level serializable");
        $result = Db::transaction(function () use ($commodity, $rebate, $divideAmount, $business, $sku, $requestNo, $user, $userGroup, $num, $contact, $device, $amount, $owner, $pay, $cardId, $password, $coupon, $from, $widget, $race, $callbackDomain, $clientDomain) {
            //生成联系方式
            if ($user) {
                $contact = Str::generateRandStr(16);
            }

            if ($requestNo && \App\Model\Order::query()->where("request_no", $requestNo)->first()) {
                throw new JSONException("The request ID already exists");
            }


            $date = Date::current();
            $order = new  \App\Model\Order();
            $order->widget = $widget;
            $order->owner = $owner;
            $order->trade_no = Str::generateTradeNo();
            $order->amount = (new Decimal($amount, 2))->getAmount();
            $order->cost = (float)$commodity->factory_price;
            $order->commodity_id = $commodity->id;
            $order->pay_id = $pay->id;
            $order->create_time = $date;
            $order->create_ip = Client::getAddress();
            $order->create_device = $device;
            $order->status = 0;
            $order->contact = trim((string)$contact);
            $order->delivery_status = 0;
            $order->card_num = $num;
            $order->user_id = (int)$commodity->owner;

            if ($requestNo) $order->request_no = $requestNo;
            if (!empty($race)) $order->race = $race;
            if (!empty($sku)) $order->sku = $sku;
            if ($commodity->draft_status == 1 && $cardId != 0) $order->card_id = $cardId;
            if ($password != "") $order->password = $password;
            if ($business) $order->substation_user_id = $business->user_id;
            if ($rebate > 0) $order->rebate = $rebate;
            if ($from > 0) $order->from = $from;
            if ($divideAmount > 0) $order->divide_amount = $divideAmount;


            //优惠券
            if (!empty($coupon)) {
                $voucher = Coupon::query()->where("code", $coupon)->first();
                if ($voucher->status != 0) {
                    throw new JSONException("该优惠券已失效");
                }
                $voucher->service_time = $date;
                $voucher->use_life = $voucher->use_life + 1;
                $voucher->life = $voucher->life - 1;
                if ($voucher->life <= 0) {
                    $voucher->status = 1;
                }
                $voucher->trade_no = $order->trade_no;
                $voucher->save();
                $order->coupon_id = $voucher->id;
            }

            $secret = null;

            hook(Hook::USER_API_ORDER_TRADE_PAY_BEGIN, $commodity, $order, $pay);

            if ($order->amount == 0) {
                //免费赠送
                $order->save();//先将订单保存下来
                $secret = $this->orderSuccess($order); //提交订单并且获取到卡密信息
            } else {
                if ($pay->handle == "#system") {
                    //余额购买
                    if ($owner == 0) {
                        throw new JSONException("您未登录，请先登录后再使用余额支付");
                    }
                    $session = User::query()->find($owner);
                    if (!$session) {
                        throw new JSONException("用户不存在");
                    }

                    if ($session->status != 1) {
                        throw new JSONException("You have been banned");
                    }
                    $parent = $session->parent;
                    if ($parent && $order->user_id != $from) {
                        $order->from = $parent->id;
                    }
                    //扣钱
                    Bill::create($session, $order->amount, Bill::TYPE_SUB, "商品下单[{$order->trade_no}]");
                    //发卡
                    $order->save();//先将订单保存下来
                    $secret = $this->orderSuccess($order); //提交订单并且获取到卡密信息
                } else {
                    //开始进行远程下单
                    $class = "\\App\\Pay\\{$pay->handle}\\Impl\\Pay";
                    if (!class_exists($class)) {
                        throw new JSONException("该支付方式未实现接口，无法使用");
                    }
                    $autoload = BASE_PATH . '/app/Pay/' . $pay->handle . "/Vendor/autoload.php";
                    if (file_exists($autoload)) {
                        require($autoload);
                    }
                    //增加接口手续费：0.9.6-beta
                    $order->pay_cost = $pay->cost_type == 0 ? $pay->cost : (new Decimal($order->amount, 2))->mul($pay->cost)->getAmount();
                    $order->amount = (new Decimal($order->amount, 2))->add($order->pay_cost)->getAmount();

                    $payObject = new $class;
                    $payObject->amount = $order->amount;
                    $payObject->tradeNo = $order->trade_no;
                    $payObject->config = PayConfig::config($pay->handle);

                    $payObject->callbackUrl = $callbackDomain . '/user/api/order/callback.' . $pay->handle;

                    //判断如果登录
                    if ($owner == 0) {
                        $payObject->returnUrl = $clientDomain . '/user/index/query?tradeNo=' . $order->trade_no;
                    } else {
                        $payObject->returnUrl = $clientDomain . '/user/personal/purchaseRecord?tradeNo=' . $order->trade_no;
                    }

                    $payObject->clientIp = Client::getAddress();
                    $payObject->code = $pay->code;
                    $payObject->handle = $pay->handle;

                    $trade = $payObject->trade();
                    if ($trade instanceof PayEntity) {
                        $order->pay_url = $trade->getUrl();
                        switch ($trade->getType()) {
                            case \App\Pay\Pay::TYPE_REDIRECT:
                                $url = $order->pay_url;
                                break;
                            case \App\Pay\Pay::TYPE_LOCAL_RENDER:
                                $url = '/user/pay/order.' . $order->trade_no . ".1";
                                break;
                            case \App\Pay\Pay::TYPE_SUBMIT:
                                $url = '/user/pay/order.' . $order->trade_no . ".2";
                                break;
                        }
                        $order->save();
                        $option = $trade->getOption();
                        if (!empty($option)) {
                            OrderOption::create($order->id, $trade->getOption());
                        }
                    } else {
                        throw new JSONException("支付方式未部署成功");
                    }
                }
            }


            $order->save();

            hook(Hook::USER_API_ORDER_TRADE_AFTER, $commodity, $order, $pay);
            return ['url' => $url, 'amount' => $order->amount, 'tradeNo' => $order->trade_no, 'secret' => $secret];
        });
        $result["stock"] = $shopService->getItemStock($commodity, $race, $sku);
        return $result;
    }


    /**
     * 初始化回调
     * @throws JSONException
     */
    public function callbackInitialize(string $handle, array $map): array
    {
        $json = json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $payInfo = PayConfig::info($handle);
        $payConfig = PayConfig::config($handle);
        $callback = $payInfo['callback'];

        $autoload = BASE_PATH . '/app/Pay/' . $handle . "/Vendor/autoload.php";
        if (file_exists($autoload)) {
            require($autoload);
        }

        //检测签名验证是否开启
        if ($callback[\App\Consts\Pay::IS_SIGN]) {
            $class = "\\App\\Pay\\{$handle}\\Impl\\Signature";
            if (!class_exists($class)) {
                PayConfig::log($handle, "CALLBACK", "插件未实现接口");
                throw new JSONException("signature not implements interface");
            }
            $signature = new $class;
            Context::set(\App\Consts\Pay::DAFA, $map);
            if (!$signature->verification($map, $payConfig)) {
                PayConfig::log($handle, "CALLBACK", "签名验证失败，接受数据：" . $json);
                throw new JSONException("sign error");
            }
            $map = Context::get(\App\Consts\Pay::DAFA);
        }

        //验证状态
        if ($callback[\App\Consts\Pay::IS_STATUS]) {
            if ($map[$callback[\App\Consts\Pay::FIELD_STATUS_KEY]] != $callback[\App\Consts\Pay::FIELD_STATUS_VALUE]) {
                PayConfig::log($handle, "CALLBACK", "状态验证失败，接受数据：" . $json);
                throw new JSONException("status error");
            }
        }

        //拿到订单号和金额
        return ["trade_no" => $map[$callback[\App\Consts\Pay::FIELD_ORDER_KEY]], "amount" => $map[$callback[\App\Consts\Pay::FIELD_AMOUNT_KEY]], "success" => $callback[\App\Consts\Pay::FIELD_RESPONSE]];
    }


    /**
     * @param \App\Model\Order $order
     * @return string
     * @throws JSONException
     */
    public function orderSuccess(\App\Model\Order $order): string
    {
        /**
         * @var Commodity $commodity
         */
        $commodity = $order->commodity;
        $order->pay_time = Date::current();
        $order->status = 1;
        $shared = $commodity->shared; //获取商品的共享平台

        if ($shared) {
            //拉取远程平台的卡密发货
            $order->secret = $this->shared->trade($shared, $commodity, $order->contact, $order->card_num, (int)$order->card_id, $order->create_device, (string)$order->password, (string)$order->race, $order->sku ?: [], $order->widget, $order->trade_no);
            $order->delivery_status = 1;
        } else {
            if ($this->webshare->isSupportedCommodity($commodity)) {
                $order->secret = $this->webshare->deliver($order);
                $order->delivery_status = 1;
                if ($commodity->stock >= $order->card_num) {
                    Commodity::query()->where("id", $commodity->id)->decrement('stock', $order->card_num);
                } else {
                    Commodity::query()->where("id", $commodity->id)->update(['stock' => 0]);
                }
            } elseif ($commodity->delivery_way == 0) {
                //拉取本地的卡密发货
                $order->secret = $this->pullCardForLocal($order, $commodity);
                $order->delivery_status = 1;
            } else {
                //手动发货
                $order->secret = ($commodity->delivery_message != null && $commodity->delivery_message != "") ? $commodity->delivery_message : '正在发货中，请耐心等待，如有疑问，请联系客服。';
                //减少手动库存
                if ($commodity->stock >= $order->card_num) {
                    Commodity::query()->where("id", $commodity->id)->decrement('stock', $order->card_num);
                } else {
                    Commodity::query()->where("id", $commodity->id)->update(['stock' => 0]);
                }
            }
        }

        //推广者
        if ($order->from > 0 && $order->divide_amount > 0) {
            Bill::create($order->from, $order->divide_amount, Bill::TYPE_ADD, "推广分成[$order->trade_no]", 1);
        }

        if ($order->rebate > 0) {
            if ($order->user_id > 0) {
                Bill::create($order->user_id, $order->rebate, Bill::TYPE_ADD, "自营商品出售[$order->trade_no]", 1);
            } elseif ($order->substation_user_id > 0) {
                Bill::create($order->substation_user_id, $order->rebate, Bill::TYPE_ADD, "分站商品出售[$order->trade_no]", 1);
            }
        }


        $order->save();

        if ($commodity->contact_type == 2 && $commodity->send_email == 1 && $order->owner == 0) {
            try {
                $this->email->send($order->contact, "【发货提醒】您购买的卡密发货啦", "您购买的卡密如下：" . $order->secret);
            } catch (\Exception|\Error $e) {
            }
        }

        hook(Hook::USER_API_ORDER_PAY_AFTER, $commodity, $order, $order->pay);


        return (string)$order->secret;
    }

    /**
     * 拉取本地卡密，需要事务环境执行
     * @param \App\Model\Order $order
     * @param Commodity $commodity
     * @return string
     */
    private function pullCardForLocal(\App\Model\Order $order, Commodity $commodity): string
    {
        $secret = "很抱歉，有人在你付款之前抢走了商品，请联系客服。";

        /**
         * @var Card $draft
         */
        $draft = $order->card;

        //指定预选卡密
        if ($draft) {
            if ($draft->status == 0) {
                $secret = $draft->secret;
                $draft->purchase_time = $order->pay_time;
                $draft->order_id = $order->id;
                $draft->status = 1;
                $draft->save();
            }
            return $secret;
        }

        //取出和订单相同数量的卡密
        $direction = match ($commodity->delivery_auto_mode) {
            0 => "id asc",
            1 => "rand()",
            2 => "id desc"
        };
        $cards = Card::query()->where("commodity_id", $order->commodity_id)->orderByRaw($direction)->where("status", 0);
        //判断订单是否存在类别
        if ($order->race) {
            $cards = $cards->where("race", $order->race);
        }

        //判断sku存在
        if (!empty($order->sku)) {
            foreach ($order->sku as $k => $v) {
                $cards = $cards->where("sku->{$k}", $v);
            }
        }

        $cards = $cards->limit($order->card_num)->get();

        if (count($cards) == $order->card_num) {
            $ids = [];
            $cardc = '';
            foreach ($cards as $card) {
                $ids[] = $card->id;
                $cardc .= $card->secret . PHP_EOL;
            }
            try {
                //将全部卡密置已销售状态
                $rows = Card::query()->whereIn("id", $ids)->update(['purchase_time' => $order->pay_time, 'order_id' => $order->id, 'status' => 1]);
                if ($rows != 0) {
                    $secret = trim($cardc, PHP_EOL);
                }
            } catch (\Exception $e) {
            }
        }

        return $secret;
    }


    /**
     * @param string $handle
     * @param array $map
     * @return string
     * @throws JSONException
     * @throws RuntimeException
     * @throws \ReflectionException
     */
    public function callback(string $handle, array $map): string
    {
        $callback = $this->callbackInitialize($handle, $map);
        $json = json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        DB::connection()->getPdo()->exec("set session transaction isolation level serializable");
        DB::transaction(function () use ($handle, $map, $callback, $json) {
            //获取订单
            $order = \App\Model\Order::query()->where("trade_no", $callback['trade_no'])->first();
            if (!$order) {
                PayConfig::log($handle, "CALLBACK", "订单不存在，接受数据：" . $json);
                throw new JSONException("order not found");
            }
            if ($order->status != 0) {
                PayConfig::log($handle, "CALLBACK", "重复通知，当前订单已支付");
                throw new JSONException("order status error");
            }
            if ($order->amount != $callback['amount']) {
                PayConfig::log($handle, "CALLBACK", "订单金额不匹配，接受数据：" . $json);
                throw new JSONException("amount error");
            }
            //第三方支付订单成功，累计充值
            if ($order->owner != 0 && $owner = User::query()->find($order->owner)) {
                //累计充值
                $owner->recharge = $owner->recharge + $order->amount;
                $owner->save();
            }
            $this->orderSuccess($order);
        });
        return $callback['success'];
    }

    /**
     * @param User|null $user
     * @param UserGroup|null $userGroup
     * @param int $cardId
     * @param int $num
     * @param string $coupon
     * @param int|Commodity|null $commodityId
     * @param string|null $race
     * @param array|null $sku
     * @param bool $disableShared
     * @return array
     * @throws JSONException
     * @throws \ReflectionException
     */
    public function getTradeAmount(
        ?User              $user,
        ?UserGroup         $userGroup,
        int                $cardId,
        int                $num,
        string             $coupon,
        int|Commodity|null $commodityId,
        ?string            $race = null,
        ?array             $sku = [],
        bool               $disableShared = false
    ): array
    {
        if ($num <= 0) {
            throw new JSONException("购买数量不能低于1个");
        }

        if ($commodityId instanceof Commodity) {
            $commodity = $commodityId;
        } else {
            $commodity = Commodity::query()->find($commodityId);
        }

        if (!$commodity) {
            throw new JSONException("商品不存在");
        }
        if ($commodity->status != 1) {
            throw new JSONException("当前商品已停售");
        }

        $data = [];
        $config = Ini::toArray($commodity->config);

        if (is_array($config['category']) && !in_array($race, $config['category'])) {
            throw new JSONException("宝贝分类选择错误");
        }

        if (is_array($config['sku'])) {
            if (empty($sku) || !is_array($sku)) {
                throw new JSONException("请选择SKU");
            }

            foreach ($config['sku'] as $sk => $ks) {
                if (!in_array($sk, $sku)) {
                    throw new JSONException("请选择{$sk}");
                }

                if (!in_array($sku[$sk], $ks)) {
                    throw new JSONException("{$sk}中不存在{$sku[$sk]}，请选择正确的SKU");
                }
            }
        }

        /**
         * @var \App\Service\Shop $shopService
         */
        $shopService = Di::inst()->make(\App\Service\Shop::class);

        $data['card_count'] = $shopService->getItemStock($commodityId, $race, $sku);

//        if ($commodity->delivery_way == 0 && ($commodity->shared_id == null || $commodity->shared_id == 0)) {
//            if ($race) {
//                $data['card_count'] = Card::query()->where("commodity_id", $commodity->id)->where("status", 0)->where("race", $race)->count();
//            }
//        } elseif ($commodity->shared_id != 0) {
//            //查远程平台的库存
//            $shared = \App\Model\Shared::query()->find($commodity->shared_id);
//            if ($shared && !$disableShared) {
//                $inventory = $this->shared->inventory($shared, $commodity, (string)$race);
//                $data['card_count'] = $inventory['count'];
//            }
//        }

        //检测限购数量
        if ($commodity->minimum != 0 && $num < $commodity->minimum) {
            throw new JSONException("本商品单次最少购买{$commodity->minimum}个");
        }

        if ($commodity->maximum != 0 && $num > $commodity->maximum) {
            throw new JSONException("本商品单次最多购买{$commodity->maximum}个");
        }

        if ($cardId != 0 && $commodity->draft_status == 1) {
            $num = 1;
        }

        $ow = 0;
        if ($user) {
            $ow = $user->id;
        }
        $amount = $this->calcAmount($ow, $num, $commodity, $userGroup, $race);
        if ($cardId != 0 && $commodity->draft_status == 1) {
            $amount = $amount + $commodity->draft_premium;
        }

        $couponMoney = 0;
        //优惠券
        $price = $amount / $num;


        if ($coupon != "") {
            $voucher = Coupon::query()->where("code", $coupon)->first();

            if (!$voucher) {
                throw new JSONException("该优惠券不存在");
            }

            if ($voucher->owner != $commodity->owner) {
                throw new JSONException("该优惠券不存在");
            }


            if ($voucher->commodity_id != 0 && $voucher->commodity_id != $commodity->id) {
                throw new JSONException("该优惠券不属于该商品");
            }

            //race
            if ($voucher->race && $voucher->commodity_id != 0) {
                if ($race != $voucher->race) {
                    throw new JSONException("该优惠券不能抵扣当前商品");
                }
            }

            //sku
            if ($voucher->sku && is_array($voucher->sku) && $voucher->commodity_id != 0) {
                if (!is_array(empty($sku))) {
                    throw new JSONException("此优惠券不适用当前商品");
                }

                foreach ($voucher->sku as $key => $sk) {
                    if (isset($sku[$key])) {
                        throw new JSONException("此优惠券不适用此SKU");
                    }

                    if ($sk != $sku[$key]) {
                        throw new JSONException("此优惠券不适用此SKU{$sku[$key]}");
                    }
                }
            }


            //判断该优惠券是否有分类设定
            if ($voucher->commodity_id == 0 && $voucher->category_id != 0 && $voucher->category_id != $commodity->category_id) {
                throw new JSONException("该优惠券不能抵扣当前商品");
            }

            if ($voucher->status != 0) {
                throw new JSONException("该优惠券已失效");
            }

            //检测过期时间
            if ($voucher->expire_time != null && strtotime($voucher->expire_time) < time()) {
                throw new JSONException("该优惠券已过期");
            }

            //检测面额
            if ($voucher->money >= $amount) {
                throw new JSONException("该优惠券面额大于订单金额");
            }

            $deduction = $voucher->mode == 0 ? $voucher->money : (new Decimal($price, 2))->mul($voucher->money)->getAmount();

            $amount = (new Decimal($amount))->sub($deduction)->getAmount();
            $couponMoney = $deduction;
        }


        $data ['amount'] = $amount;
        $data ['price'] = (new Decimal($price))->getAmount();
        $data ['couponMoney'] = (new Decimal($couponMoney))->getAmount();

        return $data;
    }


    /**
     * @param Commodity $commodity
     * @param string $race
     * @param int $num
     * @param string $contact
     * @param string $password
     * @param int|null $cardId
     * @param int $userId
     * @param string $widget
     * @return array
     * @throws JSONException
     * @throws RuntimeException
     * @throws \ReflectionException
     */
    public function giftOrder(Commodity $commodity, string $race = "", int $num = 1, string $contact = "", string $password = "", ?int $cardId = null, int $userId = 0, string $widget = "[]"): array
    {
        return DB::transaction(function () use ($race, $widget, $contact, $password, $num, $cardId, $commodity, $userId) {
            //创建订单
            $date = Date::current();
            $order = new  \App\Model\Order();
            $order->owner = $userId;
            $order->trade_no = Str::generateTradeNo();
            $order->amount = 0;
            $order->commodity_id = $commodity->id;
            $order->card_id = $cardId;
            $order->card_num = $num;
            $order->pay_id = 1;
            $order->create_time = $date;
            $order->create_ip = Client::getAddress();
            $order->create_device = 0;
            $order->status = 0;
            $order->password = $password;
            $order->contact = trim($contact);
            $order->delivery_status = 0;
            $order->widget = $widget;
            $order->rent = 0;
            $order->race = $race;
            $order->user_id = $commodity->owner;
            $order->save();
            $secret = $this->orderSuccess($order);
            return [
                "secret" => $secret,
                "tradeNo" => $order->trade_no
            ];
        });
    }
}
