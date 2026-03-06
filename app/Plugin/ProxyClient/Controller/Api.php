<?php
declare (strict_types=1);

namespace App\Plugin\ProxyClient\Controller;

use App\Controller\Base\API\UserPlugin;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use App\Model\Business;
use App\Util\Client;
use App\Util\Http;
use App\Util\Plugin;
use GuzzleHttp\Exception\GuzzleException;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

#[Interceptor([Waf::class, UserSession::class])]
class Api extends UserPlugin
{
    #[Inject]
    private \App\Service\Order $order;


    /**
     * @return array
     * @throws GuzzleException
     */
    public function data(): array
    {
        $config = Plugin::getConfig("ProxyClient");
        $response = Http::make()->post(trim($config['url'], "/") . "/api/get", [
            "form_params" => [
                "user" => $_GET['user'],
                "type_id" => (int)$_POST['type_id']
            ]
        ]);
        $contents = json_decode((string)$response->getBody()->getContents(), true);
        return ["code" => 200, "count" => count($contents['data']), "data" => $contents['data']];
    }

    /**
     * @return array
     * @throws GuzzleException
     */
    public function type(): array
    {
        $config = Plugin::getConfig("ProxyClient");
        $response = Http::make()->post(trim($config['url'], "/") . "/api/type");
        $contents = json_decode((string)$response->getBody()->getContents(), true);
        return ["code" => 200, "msg" => "success", "data" => $contents['data']];
    }


    /**
     * @return array
     */
    public function buy(): array
    {
        $map = [
            "pay_id" => 1,
            "num" => (int)$_POST['num'],
            "commodity_id" => (int)$_POST['commodity_id'],
            "device" => 0,
            "race" => (string)$_POST['race'],
            "proxy_id" => (int)$_POST['proxy_id']
        ];

        $site = Business::get(Client::getDomain());

        if ($site) {
            $map['from'] = $site->user_id;
        }

        hook(\App\Consts\Hook::USER_API_ORDER_TRADE_BEGIN, $map);
        $trade = $this->order->trade($this->getUser(), $this->getUserGroup(), $map);
        return $this->json(200, '续费成功', $trade);
    }


    /**
     * @throws GuzzleException
     * @throws JSONException
     */
    public function edit(): array
    {
        $config = Plugin::getConfig("ProxyClient");

        $username = (string)$_POST['username'];
        $password = (string)$_POST['password'];

        if (mb_strlen($username) < 6 || mb_strlen($password) < 6) {
            throw new JSONException("账号和密码都不能小于6位");
        }

        $response = Http::make()->post(trim($config['url'], "/") . "/api/edit", [
            "form_params" => [
                "user" => $this->getUser()->username . ":" . $this->getUser()->id,
                "username" => $username,
                "password" => $password,
                "id" => (int)$_POST['id'],
            ]
        ]);
        $contents = json_decode((string)$response->getBody()->getContents(), true);

        if ($contents['code'] != 200) {
            throw new JSONException($contents['msg']);
        }

        return $this->json(200, "IP信息修改成功", [
            "address" => $contents['data']['address']]);
    }
}