<?php
declare(strict_types=1);

namespace App\Util;

use Kernel\Exception\JSONException;

class AliyunNumberAuth
{
    private const ENDPOINT = "https://dypnsapi.aliyuncs.com/";

    /**
     * Use Aliyun Dypnsapi GetMobile to fetch mobile number from access token.
     * @param array $config
     * @param string $accessToken
     * @return string
     * @throws JSONException
     */
    public static function getMobile(array $config, string $accessToken): string
    {
        $accessKeyId = trim((string)($config['numberAuthAccessKeyId'] ?? ''));
        $accessKeySecret = trim((string)($config['numberAuthAccessKeySecret'] ?? ''));

        if ($accessKeyId === '' || $accessKeySecret === '') {
            throw new JSONException("号码认证配置不完整");
        }

        if ($accessToken === '') {
            throw new JSONException("号码认证令牌为空");
        }

        $params = [
            "AccessKeyId" => $accessKeyId,
            "Action" => "GetMobile",
            "Format" => "JSON",
            "RegionId" => "cn-hangzhou",
            "SignatureMethod" => "HMAC-SHA1",
            "SignatureNonce" => Str::generateRandStr(32),
            "SignatureVersion" => "1.0",
            "Timestamp" => gmdate("Y-m-d\\TH:i:s\\Z"),
            "Version" => "2017-05-25",
            "AccessToken" => $accessToken
        ];

        ksort($params);
        $canonicalized = self::buildCanonicalizedQuery($params);
        $stringToSign = "GET&%2F&" . self::percentEncode($canonicalized);
        $signature = base64_encode(hash_hmac("sha1", $stringToSign, $accessKeySecret . "&", true));
        $params["Signature"] = $signature;

        $url = self::ENDPOINT . "?" . self::buildCanonicalizedQuery($params);

        try {
            $response = Http::make([
                "timeout" => 15
            ])->get($url);
        } catch (\Throwable $e) {
            throw new JSONException("号码认证请求失败");
        }

        $contents = (string)$response->getBody()->getContents();
        $json = json_decode($contents, true);
        if (!is_array($json)) {
            throw new JSONException("号码认证响应异常");
        }

        if (($json["Code"] ?? "") !== "OK") {
            throw new JSONException((string)($json["Message"] ?? "号码认证失败"));
        }

        $mobile = trim((string)($json["GetMobileResultDTO"]["Mobile"] ?? ""));
        if ($mobile === "" || !Validation::phone($mobile)) {
            throw new JSONException("号码认证返回手机号异常");
        }

        return $mobile;
    }

    private static function buildCanonicalizedQuery(array $params): string
    {
        $parts = [];
        foreach ($params as $key => $value) {
            $parts[] = self::percentEncode((string)$key) . "=" . self::percentEncode((string)$value);
        }
        return implode("&", $parts);
    }

    private static function percentEncode(string $value): string
    {
        return str_replace(["+", "*", "%7E"], ["%20", "%2A", "~"], rawurlencode($value));
    }
}
