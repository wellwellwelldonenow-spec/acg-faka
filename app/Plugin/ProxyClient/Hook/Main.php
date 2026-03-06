<?php
declare (strict_types=1);

namespace App\Plugin\ProxyClient\Hook;

use App\Controller\Base\View\UserPlugin;
use App\Model\Commodity;
use App\Model\Order;
use App\Model\Pay;
use App\Model\User;
use App\Util\Http;
use App\Util\Ini;
use App\Util\Plugin;
use GuzzleHttp\Exception\GuzzleException;
use Kernel\Annotation\Hook;
use Kernel\Exception\JSONException;
use Kernel\Exception\ViewException as ViewExceptionAlias;

class Main extends UserPlugin
{


    /**
     * @throws \ReflectionException
     * @throws ViewExceptionAlias
     */
    #[Hook(point: \App\Consts\Hook::USER_VIEW_MENU)]
    public function nav(): void
    {
        echo $this->render("", "Nav.html");
    }


    /**
     * @throws GuzzleException
     * @throws JSONException
     */
    #[Hook(point: \App\Consts\Hook::USER_API_ORDER_TRADE_PAY_BEGIN)]
    public function trade(Commodity $commodity, Order $order, Pay $pay): void
    {
        $user = User::query()->find($order->owner);

        if (!$user) {
            return;
        }

        $config = Plugin::getConfig("ProxyClient");

        $proxyUser = $_POST['proxy_user'];
        $proxyExt = $_POST['proxy_ext'];
        $proxyId = (int)$_POST['proxy_id'];

        $json = json_decode((string)$order->widget, true);

        if ($proxyId > 0) {
            $json['proxy_id'] = ["value" => $proxyId, "cn" => "rid"];
        }

        if ($proxyUser && $proxyExt) {
            $json["proxy_user"] = ["value" => $proxyUser, "cn" => "userinfo"];
            $json['proxy_ext'] = ["value" => $proxyExt, "cn" => "ext"];
        } else {
            $json["proxy_user"] = ["value" => $user->username . ":" . $user->id, "cn" => "userinfo"];
            $json['proxy_ext'] = ["value" => json_encode([
                "commodity" => $commodity->id,
                "race" => $order->race,
                "trade_no" => $order->trade_no
            ]), "cn" => "ext"];
        }

        /*        if ($proxyId == 0) {   --暂时废弃
                    $cfg = Ini::toArray((string)$commodity->config);
                    $response = Http::make()->post(trim($config['url'], "/") . "/api/inventory", [
                        "form_params" => [
                            "user" => $json['proxy_user']['value'],
                            "type_id" => $cfg['proxy_config']['type_id'],
                            "num" => (int)$_POST['num']
                        ]
                    ]);
                    $contents = json_decode((string)$response->getBody()->getContents(), true);
                    if ($contents['code'] != 200) {
                        throw new JSONException("库存不足");
                    }
                }*/

        $order->widget = json_encode($json);
    }
}