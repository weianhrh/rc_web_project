<?php

class CurlHelper
{
    private static $profiles = [];

    public static function registerProfile($key, array $config)
    {
        self::$profiles[$key] = $config;
    }

    public static function useProfile($key)
    {
        return self::$profiles[$key] ?? [];
    }

    public static function initHandle($ch, $url, $method, $data, $config)
    {
        $method = strtoupper($method);
        $defaults = [
            'headers'        => [],
            'cookies'        => '',
            'user_agent'     => 'CurlHelper/2.0',
            'timeout'        => 15,
            'connect_timeout'=> 5,
            'proxy'          => null,
            'referer'        => null,
            'gzip'           => true,
            'ssl_verify'     => false,
            'is_json'        => false,
            'is_multipart'   => false,
            'return_header'  => false,
        ];
        $cfg = array_merge($defaults, $config);

        // 拼接 GET 参数
        if ($method === 'GET' && !empty($data)) {
            $query = http_build_query($data);
            $url .= (strpos($url, '?') === false ? '?' : '&') . $query;
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $cfg['timeout']);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $cfg['connect_timeout']);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        if (!empty($cfg['headers'])) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $cfg['headers']);
        }
        if (!empty($cfg['cookies'])) {
            curl_setopt($ch, CURLOPT_COOKIE, $cfg['cookies']);
        }
        if (!empty($cfg['user_agent'])) {
            curl_setopt($ch, CURLOPT_USERAGENT, $cfg['user_agent']);
        }
        if (!empty($cfg['referer'])) {
            curl_setopt($ch, CURLOPT_REFERER, $cfg['referer']);
        }
        if (!empty($cfg['proxy'])) {
            curl_setopt($ch, CURLOPT_PROXY, $cfg['proxy']);
        }
        if ($cfg['gzip']) {
            curl_setopt($ch, CURLOPT_ENCODING, 'gzip');
        }
        if (!$cfg['ssl_verify']) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        }
        if ($cfg['return_header']) {
            curl_setopt($ch, CURLOPT_HEADER, true);
        }

        // 处理 POST 等请求
        switch ($method) {
            case 'POST':
            case 'PUT':
            case 'PATCH':
            case 'DELETE':
            case 'OPTIONS':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                if (!empty($data)) {
                    if ($cfg['is_json']) {
                        $cfg['headers'][] = 'Content-Type: application/json';
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                    } elseif ($cfg['is_multipart']) {
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                    } else {
                        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
                    }
                }
                break;
        }
    }

    public static function parseResponse($response)
    {
        $json = json_decode($response, true);
        return (json_last_error() === JSON_ERROR_NONE) ? $json : $response;
    }

    private static function logRequest($url, $method, $data, $config, $httpCode, $error, $response)
    {
        $log = [
            'time' => date('Y-m-d H:i:s'),
            'url' => $url,
            'method' => $method,
            'data' => $data,
            'http_code' => $httpCode,
            'error' => $error,
            'response' => mb_substr($response, 0, 200),
        ];
        file_put_contents(__DIR__ . '/curl_requests.log', json_encode($log, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);
    }

    public static function request($url, $method = 'GET', $data = [], $config = [])
    {
        $method = strtoupper($method);
        $ch = curl_init();

        // 生成句柄配置
        self::initHandle($ch, $url, $method, $data, $config);

        $retry = $config['retry'] ?? 0;
        $retryDelay = $config['retry_delay'] ?? 1;
        $response = $error = '';
        $http_code = 0;
        $attempt = 0;

        while ($attempt <= $retry) {
            $response = curl_exec($ch);
            $error = curl_error($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if (curl_errno($ch) === 0) break;

            $attempt++;
            if ($attempt <= $retry) sleep($retryDelay);
        }

        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $full_response = $response;

        self::logRequest($url, $method, $data, $config, $http_code, $error, $response);

        curl_close($ch);

        return [
            'http_code' => $http_code,
            'error'     => $error,
            'response'  => ($config['return_header'] ?? false) ? substr($response, $header_size) : $response,
            'parsed'    => self::parseResponse(($config['return_header'] ?? false) ? substr($response, $header_size) : $response),
            'headers'   => ($config['return_header'] ?? false) ? substr($full_response, 0, $header_size) : '',
            'raw'       => $full_response,
            'attempts'  => $attempt,
        ];
    }
}
