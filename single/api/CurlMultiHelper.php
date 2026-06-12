<?php

class CurlMultiHelper
{
    public static function multiRequest(array $requests)
    {
        $multiHandle = curl_multi_init();
        $curlHandles = [];
        $responses = [];

        foreach ($requests as $id => $req) {
            $ch = curl_init();
            $url = $req['url'];
            $method = strtoupper($req['method'] ?? 'GET');
            $data = $req['data'] ?? [];
            $config = $req['config'] ?? [];

            // 支持配置中使用 profile
            if (!empty($req['profile'])) {
                $profile = CurlHelper::useProfile($req['profile']);
                $config = array_merge($profile, $config);
            }

            CurlHelper::initHandle($ch, $url, $method, $data, $config);

            curl_multi_add_handle($multiHandle, $ch);
            $curlHandles[$id] = $ch;
        }

        $running = null;
        do {
            curl_multi_exec($multiHandle, $running);
            curl_multi_select($multiHandle, 1);
        } while ($running > 0);

        foreach ($curlHandles as $id => $ch) {
            $raw = curl_multi_getcontent($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);

            $responses[$id] = [
                'http_code' => $httpCode,
                'error'     => $error,
                'response'  => $raw,
                'parsed'    => CurlHelper::parseResponse($raw),
            ];

            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);
        }

        curl_multi_close($multiHandle);
        return $responses;
    }
}
