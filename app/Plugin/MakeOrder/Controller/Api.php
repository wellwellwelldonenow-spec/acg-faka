<?php
declare(strict_types=1);

namespace App\Plugin\MakeOrder\Controller;


use App\Controller\Base\API\ManagePlugin;
use App\Interceptor\ManageSession;
use App\Interceptor\Waf;
use App\Model\Order;
use Illuminate\Database\Capsule\Manager as DB;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

#[Interceptor([Waf::class, ManageSession::class], Interceptor::TYPE_API)]
class Api extends ManagePlugin
{

    #[Inject]
    private \App\Service\Order $order;

    /**
     * @return array
     * @throws JSONException
     */
    public function make(): array
    {
        $orderId = (int)$_POST['order_id'];

        if ($orderId == 0) {
            throw new JSONException("订单ID为空");
        }

        DB::transaction(function () use ($orderId) {
            $order = Order::query()->find($orderId);
            if (!$order) {
                throw new JSONException("订单不存在");
            }
            if ($order->status != 0) {
                throw new JSONException("该订单已支付过了");
            }
            $this->order->orderSuccess($order);
        });

        return $this->json(200, "补单成功");
    }
}