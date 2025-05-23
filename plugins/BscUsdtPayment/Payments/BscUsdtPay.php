<?php

namespace Plugin\Bscusdtpayment\Payments;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class BscUsdtPay
{
    protected $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'api_url' => [
                'label' => '地址生成 API',
                'type' => 'input',
                'description' => '外部地址生成服务的 API 地址'
            ],
            'api_key' => [
                'label' => 'API Key',
                'type' => 'input',
                'description' => '外部服务的 API Key'
            ],
            'bscscan_api_key' => [
                'label' => 'BSCScan API Key',
                'type' => 'input',
                'description' => '用于查询 BSC 链上交易的 BSCScan API Key，可从 https://bscscan.com/myapikey 获取'
            ],
            'callback_url' => [
                'label' => '回调地址',
                'type' => 'input',
                'description' => '外部服务回调地址，用于通知支付状态'
            ],
            'payment_timeout' => [
                'label' => '支付超时（分钟）',
                'type' => 'input',
                'description' => '订单支付超时时间',
                'placeholder' => '30'
            ],
            'check_interval' => [
                'label' => '支付检查间隔（分钟）',
                'type' => 'input',
                'description' => '系统自动检查支付状态的时间间隔',
                'placeholder' => '5'
            ],
            'auto_check_enabled' => [
                'label' => '启用自动检查',
                'type' => 'select',
                'description' => '是否启用自动检查支付状态',
                'select_options' => [
                    ['value' => '1', 'label' => '启用'],
                    ['value' => '0', 'label' => '禁用']
                ]
            ]
        ];
    }

    public function pay($order)
    {
        // 获取用户 ID
        $userId = $order['user_id'];
        $tradeNo = $order['trade_no'];
        
        // 计算 USDT 金额（直接使用 U 作为计价单位）
        $usdtAmount = $order['total_amount'] / 100;
        
        // 检查是否已经有缓存的地址
        $cacheKey = 'bsc_usdt_address_' . $userId;
        $walletAddress = Cache::get($cacheKey);
        
        if (!$walletAddress) {
            // 调用外部 API 获取钱包地址
            try {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $this->config['api_key']
                ])->post($this->config['api_url'], [
                    'user_id' => $userId,
                    'email' => $this->getUserEmail($userId),  // 如果需要发送用户邮箱
                    'callback_url' => $this->config['callback_url']
                ]);
                
                if ($response->successful()) {
                    $data = $response->json();
                    if (isset($data['address'])) {
                        $walletAddress = $data['address'];
                        // 缓存地址，长期有效
                        Cache::put($cacheKey, $walletAddress, now()->addYear());
                    } else {
                        Log::channel('daily')->error('BSC-USDT API Error: Invalid response format', $data);
                        abort(400, '生成支付地址失败，请稍后再试');
                    }
                } else {
                    Log::channel('daily')->error('BSC-USDT API Error: ' . $response->body());
                    abort(400, '生成支付地址失败，请稍后再试');
                }
            } catch (\Exception $e) {
                Log::channel('daily')->error('BSC-USDT API Exception: ' . $e->getMessage());
                abort(400, '生成支付地址失败，请稍后再试');
            }
        }
        
        // 创建支付信息
        $paymentInfo = [
            'trade_no' => $tradeNo,
            'user_id' => $userId,
            'wallet_address' => $walletAddress,
            'usdt_amount' => $usdtAmount,
            'network' => 'bsc',
            'created_at' => time(),
            'expires_at' => time() + ($this->config['payment_timeout'] ?? 30) * 60, // 默认 30 分钟超时
            'status' => 'pending'
        ];
        
        // 缓存支付信息
        Cache::put('bsc_usdt_payment_' . $tradeNo, $paymentInfo, now()->addHours(24));
        
        // 记录日志
        Log::channel('daily')->info('BSC-USDT Payment Created', $paymentInfo);
        
        // 生成 BSC 网络 USDT 付款的二维码内容
        // 使用 BEP20 格式
        $qrContent = "ethereum:{$walletAddress}@56?value={$usdtAmount}&tokenAddress=0x55d398326f99059fF775485246999027B3197955";
        
        return [
            'type' => 0, // 0:qrcode 1:url
            'data' => $qrContent,
            'amount' => $usdtAmount,
            'address' => $walletAddress,
            'network' => 'BSC (BEP20)',
            'order_id' => $tradeNo
        ];
    }

    /**
     * 处理回调通知
     */
    public function notify($params)
    {
        Log::channel('daily')->info('BSC-USDT Payment Notification', $params);
        
        // 验证必要参数
        if (!isset($params['user_id']) || !isset($params['tx_hash']) || !isset($params['amount'])) {
            Log::channel('daily')->error('BSC-USDT Notification Error: Missing parameters');
            return false;
        }
        
        // 查找匹配的订单
        $userId = $params['user_id'];
        $amount = floatval($params['amount']);
        $txHash = $params['tx_hash'];
        
        // 查找该用户的未支付订单
        $matchedOrder = $this->findMatchingOrder($userId, $amount);
        
        if (!$matchedOrder) {
            Log::channel('daily')->error("BSC-USDT Notification Error: No matching order found for user {$userId} with amount {$amount}");
            return false;
        }
        
        // 记录支付成功信息
        Log::channel('daily')->info("BSC-USDT Payment Success: Order {$matchedOrder} paid successfully with tx {$txHash}");
        
        return [
            'trade_no' => $matchedOrder,
            'callback_no' => $txHash,
            'custom_result' => '{"returnCode":"SUCCESS","returnMessage":null}'
        ];
    }
    
    /**
     * 查询支付状态
     * 这个方法可以由平台调用外部 API 查询支付状态
     */
    public function queryPaymentStatus($tradeNo)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->config['api_key']
            ])->get($this->config['api_url'] . '/status', [
                'trade_no' => $tradeNo
            ]);
            
            if ($response->successful()) {
                return $response->json();
            } else {
                Log::channel('daily')->error('BSC-USDT Status API Error: ' . $response->body());
                return ['status' => 'error', 'message' => '查询支付状态失败'];
            }
        } catch (\Exception $e) {
            Log::channel('daily')->error('BSC-USDT Status API Exception: ' . $e->getMessage());
            return ['status' => 'error', 'message' => '查询支付状态失败'];
        }
    }
    
    /**
     * 获取用户邮箱
     */
    private function getUserEmail($userId)
    {
        // 从数据库获取用户邮箱
        $user = \App\Models\User::find($userId);
        return $user ? $user->email : '';
    }
    
    /**
     * 查找匹配的订单
     * 根据用户 ID 和金额查找匹配的未支付订单
     */
    private function findMatchingOrder($userId, $amount)
    {
        // 实际应用中，这里应该从数据库中查询未支付的订单
        // 这里使用缓存模拟
        $pendingPayments = $this->getPendingPayments();
        
        foreach ($pendingPayments as $tradeNo => $payment) {
            if ($payment['user_id'] == $userId && abs($payment['usdt_amount'] - $amount) < 0.01) {
                // 更新支付状态
                $payment['status'] = 'paid';
                Cache::put('bsc_usdt_payment_' . $tradeNo, $payment, now()->addHours(24));
                return $tradeNo;
            }
        }
        
        return null;
    }

    /**
     * 获取所有待处理的支付
     */
    public function getPendingPayments()
    {
        $pendingPayments = [];
        $keys = Cache::get('bsc_usdt_pending_payments', []);
        
        foreach ($keys as $key) {
            $payment = Cache::get($key);
            if ($payment && $payment['status'] == 'pending' && $payment['expires_at'] > time()) {
                $tradeNo = $payment['trade_no'];
                $pendingPayments[$tradeNo] = $payment;
            }
        }
        
        return $pendingPayments;
    }
}
