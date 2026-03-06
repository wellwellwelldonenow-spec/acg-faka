<?php
declare(strict_types=1);

namespace App\Pay\Epay\Impl;

use App\Entity\PayEntity;
use App\Pay\Base;
use App\Util\Http;
use Kernel\Exception\JSONException;

/**
 * Class Pay
 * @package App\Pay\Kvmpay\Impl
 */
class Pay extends Base implements \App\Pay\Pay
{

    /**
     * @return PayEntity
     * @throws JSONException
     */
    public function trade(): PayEntity
    {

        if (!$this->config['url']) {
            throw new JSONException("请配置易支付请求地址");
        }

        if (!$this->config['pid']) {
            throw new JSONException("请配置易支付商户ID");
        }

        if (!$this->config['key']) {
            throw new JSONException("请配置易支付商户密钥");
        }

        $param = [
            'pid' => $this->config['pid'],
            'name' => $this->tradeNo, //订单名称
            'type' => $this->code,
            'money' => $this->amount,
            'out_trade_no' => $this->tradeNo,
            'notify_url' => $this->callbackUrl,
            'return_url' => $this->returnUrl,
            'sitename' => $this->tradeNo
        ];


        $url = trim($this->config['url'], "/");

        $payEntity = new PayEntity();

        if ($this->config['mapi'] == 1) {
            try {
                $param['clientip'] = $this->clientIp;
                $param['sign'] = Signature::generateSignature($param, $this->config['key']);
                $param['sign_type'] = "MD5";

                $response = Http::make()->post($url . "/mapi.php", [
                    "form_params" => $param
                ]);
                $json = json_decode($response->getBody()->getContents(), true);
                if ($json['code'] != 1) {
                    throw new JSONException((string)$json['msg']);
                }
                if (isset($json['qrcode'])) {
                    $payEntity->setType(self::TYPE_LOCAL_RENDER);
                    $payEntity->setUrl($json['qrcode']);
                    $payEntity->setOption(['returnUrl' => $this->returnUrl]);
                    return $payEntity;
                } elseif (isset($json['payurl'])) {
                    $payEntity->setType(self::TYPE_REDIRECT);
                    $payEntity->setUrl($json['payurl']);
                    return $payEntity;
                }
            } catch (\Throwable $e) {
                $this->log($e->getMessage());
                throw new JSONException("MAPI请求出错");
            }
        } else {
            $param['sign'] = Signature::generateSignature($param, $this->config['key']);
            $param['sign_type'] = "MD5";
        }

        $payEntity->setType(self::TYPE_SUBMIT);
        $payEntity->setOption($param);
        $payEntity->setUrl($url . "/submit.php");
        return $payEntity;
    }
}