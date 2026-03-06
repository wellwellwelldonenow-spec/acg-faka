<?php
declare(strict_types=1);

namespace App\Pay\Epusdt\Impl;

/**
 * Class Signature
 * @package App\Pay\Kvmpay\Impl
 */
class Signature implements \App\Pay\Signature
{

    /**
     * 生成签名
     * @param array $data
     * @param string $key
     * @return string
     */
    public static function generateSignature(array $data, string $key): string
    {
        if (isset($data['signature'])) unset($data['signature']);
        ksort($data);
        $sign = '';
        foreach ($data as $k => $v) {
            if ($v == '') continue;
            $sign .= $k . '=' . $v . '&';
        }
        $sign = trim($sign, '&');
        return md5($sign . $key);
    }

    /**
     * @inheritDoc
     */
    public function verification(array $data, array $config): bool
    {
        $sign = $data['signature'];
        unset($data['signature']);
        $generateSignature = self::generateSignature($data, $config['key']);
        file_put_contents(BASE_PATH . "/ep1.text", $generateSignature);

        if ($sign != $generateSignature) {
            return false;
        }
        return true;
    }
}