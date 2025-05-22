<?php

namespace App\Payments;

use Illuminate\Support\Facades\Log;

class BinancePay
{
    protected $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'api_key' => [
                'label' => 'API Key',
                'type' => 'input',
                'description' => '请输入您的 Binance API Key'
            ],
            'secret_key' => [
                'label' => 'Secret Key',
                'type' => 'input',
                'description' => '请输入您的 Binance Secret Key'
            ]
        ];
    }

    public function pay($order)
    {
        $timestamp = intval(microtime(true) * 1000);  // Timestamp in milliseconds
        $nonceStr = bin2hex(random_bytes(16));  // Generating a nonce
        $request = [
            "env" => [
                "terminalType" => "APP"
            ],
            'merchantTradeNo' => strval($order['trade_no']),
            'fiatCurrency' => 'CNY',
            'fiatAmount' => ($order["total_amount"] / 100),
            // 指定只支持 BSC-USDT
            'supportPayCurrency' => "USDT",
            // 指定 BSC 网络
            'network' => "BSC",
            'description' => strval($order['trade_no']),
            'webhookUrl' => $order['notify_url'],
            'returnUrl' => $order['return_url'],
            "goodsDetails" => [
                [
                    "goodsType" => "01",
                    "goodsCategory" => "D000",
                    "referenceGoodsId" => "7876763A3B",
                    "goodsName" => "订阅服务",
                    "goodsDetail" => "订阅服务支付"
                ]
            ]
        ];
        $body = json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        
        // 记录请求信息到日志
        \Log::channel('daily')->info('Binance Pay Request: ' . $body);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://bpay.binanceapi.com/binancepay/openapi/v3/order');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json; charset=utf-8',
            'BinancePay-Timestamp: ' . $timestamp,
            'BinancePay-Nonce: ' . $nonceStr,
            'BinancePay-Certificate-SN: ' . $this->config['api_key'],
            'BinancePay-Signature: ' . $this->generateSignature($body, $this->config['secret_key'], $timestamp, $nonceStr),
        ]);
        
        // 不使用代理
        $response = curl_exec($ch);
        curl_close($ch);
        if (!$response) {
            abort(400, '支付失败，请稍后再试');
        }
        $res = json_decode($response, true);
        \Log::channel('daily')->info($res);
        if (!is_array($res)) {
            abort(400, '支付失败，请稍后再试');
        }
        if (isset($res['code']) && $res['code'] == '400201') {
            $res['data'] = \Cache::get('CheckoutInfo_' . strval($order['trade_no']));
        }
        if (!isset($res['data'])) {
            abort(400, '支付失败，请稍后再试');
        }
        
        // 缓存支付信息
        \Cache::put('CheckoutInfo_' . strval($order['trade_no']), $res['data']);
        
        // Check if qrCodeUrl exists, otherwise fall back to checkoutUrl
        if (is_array($res['data']) && isset($res['data']['qrCodeUrl'])) {
            return [
                'type' => 0, // 0:qrcode 1:url
                'data' => $res['data']['qrCodeUrl']
            ];
        } elseif (is_array($res['data']) && isset($res['data']['qrContent'])) {
            // If qrContent exists (contains the payment address/info), use it for QR code
            return [
                'type' => 0, // 0:qrcode 1:url
                'data' => $res['data']['qrContent']
            ];
        } elseif (is_array($res['data']) && isset($res['data']['checkoutUrl'])) {
            // If neither qrCodeUrl nor qrContent exists, use checkoutUrl but set type to 0 for QR code
            return [
                'type' => 0, // 0:qrcode 1:url
                'data' => $res['data']['checkoutUrl']
            ];
        } else {
            abort(400, '支付失败，无法获取支付二维码');
        }
    }

    public function notify($params)
    {
        // 记录回调参数到日志
        \Log::channel('daily')->info('Binance Pay Notify: ' . json_encode($params));
        
        // 验证回调状态
        if (!isset($params['bizStatus'])) {
            \Log::channel('daily')->error('Binance Pay Notify Error: bizStatus not found');
            return false;
        }
        
        $bizStatus = $params['bizStatus'];
        if ($bizStatus !== 'PAY_SUCCESS'){
            \Log::channel('daily')->error('Binance Pay Notify Error: bizStatus is not PAY_SUCCESS: ' . $bizStatus);
            return false;
        }
        
        // 解析回调数据
        if (!isset($params['data'])) {
            \Log::channel('daily')->error('Binance Pay Notify Error: data not found');
            return false;
        }
        
        $data = json_decode($params['data'], true);
        if (!$data || !isset($data['merchantTradeNo'])) {
            \Log::channel('daily')->error('Binance Pay Notify Error: invalid data or merchantTradeNo not found');
            return false;
        }
        
        // 记录支付成功信息
        \Log::channel('daily')->info('Binance Pay Success: Order ' . $data['merchantTradeNo'] . ' paid successfully');
        
        return [
            'trade_no' => $data['merchantTradeNo'],
            'callback_no' => $params['bizIdStr'] ?? $data['merchantTradeNo'],
            'custom_result' => '{"returnCode":"SUCCESS","returnMessage":null}'
        ];
    }
    private function generateSignature($body, $secret, $timestamp, $nonceStr)
    {
        $payload = $timestamp . chr(0x0A) . $nonceStr . chr(0x0A) . $body . chr(0x0A);
        return strtoupper(hash_hmac('sha512', $payload, $secret));
    }
}
