<?php
declare (strict_types=1);

namespace App\Plugin\ProxyClient\Controller;

use App\Controller\Base\View\UserPlugin;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use App\Util\Plugin;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\ViewException;

#[Interceptor([Waf::class, UserSession::class])]
class Order extends UserPlugin
{

    /**
     * @throws \ReflectionException
     * @throws ViewException
     */
    public function index(): string
    {
        $usr = $this->getUser()->username . ":" . $this->getUser()->id;
        $config = Plugin::getConfig("ProxyClient");
        return $this->render("IP代理管理", "Order.html", ["notice" => $config['notice'], "usr" => $usr], true);
    }
}