<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BscUsdtController extends Controller
{
    /**
     * 自动检查所有待支付订单的状态
     * 此API可以由前端定期调用，无需系统定时任务
     */
    public function autoCheckPayments(Request $request)
    {
        // 检查是否有权限调用此API
        if (!$request->user() || !$request->user()->is_admin) {
            // 如果不是管理员，检查是否有有效的API密钥
            $apiKey = $request->header('X-API-KEY');
            $validApiKey = config('app.bsc_usdt_api_key', '');
            
            if (empty($apiKey) || $apiKey !== $validApiKey) {
                return $this->fail([403, '无权访问此API']);
            }
        }
        
        // 获取BSC-USDT支付配置
        $payment = Payment::where('payment', 'BscUsdtPay')->where('enable', 1)->first();
        if (!$payment) {
            return $this->fail([400, 'BSC-USDT支付方式未启用']);
        }
        
        $config = json_decode($payment->config, true);
        if (!isset($config['bscscan_api_key'])) {
            return $this->fail([400, 'BSC-USDT支付配置不完整']);
        }
        
        // 检查是否启用自动检查
        if (isset($config['auto_check_enabled']) && $config['auto_check_enabled'] != '1') {
            return $this->fail([400, 'BSC-USDT自动检查未启用']);
        }
        
        // 获取检查间隔（分钟）
        $checkInterval = isset($config['check_interval']) ? intval($config['check_interval']) : 5;
        if ($checkInterval < 1) $checkInterval = 1;
        
        // 检查上次运行时间
        $lastRunTime = Cache::get('bsc_usdt_last_check_time', 0);
        $currentTime = time();
        if ($currentTime - $lastRunTime < $checkInterval * 60) {
            return $this->success([
                'status' => 'skipped',
                'message' => '未到检查时间，跳过本次检查',
                'next_check' => $lastRunTime + ($checkInterval * 60)
            ]);
        }
        
        // 更新上次运行时间
        Cache::put('bsc_usdt_last_check_time', $currentTime, now()->addDay());
        
        // 获取所有待处理的BSC-USDT支付
        $pendingPayments = $this->getPendingPayments();
        $processedCount = 0;
        $successCount = 0;
        $results = [];
        
        foreach ($pendingPayments as $payment) {
            // 检查支付是否已过期
            if (time() > $payment['expires_at']) {
                $results[] = [
                    'trade_no' => $payment['trade_no'],
                    'status' => 'expired',
                    'message' => '支付已过期'
                ];
                continue;
            }
            
            // 使用BSCScan API查询该地址的USDT转入交易
            $matchingTransaction = $this->checkBscUsdtTransactions(
                $payment['wallet_address'], 
                $payment['usdt_amount'], 
                $payment['created_at'],
                $config['bscscan_api_key']
            );
            
            if ($matchingTransaction) {
                // 更新订单状态
                $order = Order::where('trade_no', $payment['trade_no'])->first();
                if ($order) {
                    $orderService = new OrderService($order);
                    if ($orderService->paid($matchingTransaction['hash'])) {
                        // 更新支付状态缓存
                        $payment['status'] = 'paid';
                        $payment['tx_hash'] = $matchingTransaction['hash'];
                        $payment['paid_at'] = time();
                        Cache::put('bsc_usdt_payment_' . $payment['trade_no'], $payment, now()->addDays(7));
                        
                        $results[] = [
                            'trade_no' => $payment['trade_no'],
                            'status' => 'paid',
                            'message' => '支付成功',
                            'tx_hash' => $matchingTransaction['hash']
                        ];
                        
                        $successCount++;
                    } else {
                        $results[] = [
                            'trade_no' => $payment['trade_no'],
                            'status' => 'error',
                            'message' => '订单状态更新失败'
                        ];
                    }
                } else {
                    $results[] = [
                        'trade_no' => $payment['trade_no'],
                        'status' => 'error',
                        'message' => '订单不存在'
                    ];
                }
            } else {
                $results[] = [
                    'trade_no' => $payment['trade_no'],
                    'status' => 'pending',
                    'message' => '等待支付'
                ];
            }
            
            $processedCount++;
        }
        
        return $this->success([
            'processed_count' => $processedCount,
            'success_count' => $successCount,
            'check_time' => $currentTime,
            'next_check' => $currentTime + ($checkInterval * 60),
            'results' => $results
        ]);
    }
    
    /**
     * 获取所有待处理的BSC-USDT支付
     */
    private function getPendingPayments()
    {
        $pendingPayments = [];
        
        // 获取所有待支付订单
        $pendingOrders = Order::where('status', Order::STATUS_PENDING)
            ->where('created_at', '>=', now()->subDay())
            ->get();
        
        foreach ($pendingOrders as $order) {
            // 检查是否是BSC-USDT支付
            $paymentInfo = Cache::get('bsc_usdt_payment_' . $order->trade_no);
            if ($paymentInfo && $paymentInfo['status'] === 'pending') {
                $pendingPayments[] = $paymentInfo;
            }
        }
        
        return $pendingPayments;
    }
    
    /**
     * 使用BSCScan API检查USDT转入交易
     */
    private function checkBscUsdtTransactions($address, $amount, $fromTimestamp, $apiKey)
    {
        // USDT合约地址 (BSC网络上的USDT)
        $tokenAddress = '0x55d398326f99059fF775485246999027B3197955';
        
        try {
            // 使用BSCScan API获取该地址的USDT代币转账记录
            $url = "https://api.bscscan.com/api?module=account&action=tokentx&contractaddress={$tokenAddress}&address={$address}&startblock=0&endblock=999999999&sort=desc&apikey={$apiKey}";
            
            $response = Http::get($url);
            if ($response->successful()) {
                $data = $response->json();
                
                if (isset($data['status']) && $data['status'] === '1' && isset($data['result']) && is_array($data['result'])) {
                    // 遍历所有交易
                    foreach ($data['result'] as $tx) {
                        // 只检查转入交易（to地址是我们的地址）
                        if (strtolower($tx['to']) === strtolower($address)) {
                            // 检查交易时间是否在订单创建之后
                            $txTimestamp = intval($tx['timeStamp']);
                            if ($txTimestamp >= $fromTimestamp) {
                                // 计算交易金额（USDT有18位小数）
                                $txAmount = floatval($tx['value']) / pow(10, 18);
                                
                                // 允许小额误差（0.01 USDT）
                                if (abs($txAmount - $amount) <= 0.01) {
                                    // 找到匹配的交易
                                    return [
                                        'hash' => $tx['hash'],
                                        'amount' => $txAmount,
                                        'timestamp' => $txTimestamp
                                    ];
                                }
                            }
                        }
                    }
                }
            } else {
                Log::channel('daily')->error("BSCScan API请求失败: " . $response->body());
            }
        } catch (\Exception $e) {
            Log::channel('daily')->error("BSCScan API异常: " . $e->getMessage());
        }
        
        return null;
    }

    /**
     * 查询支付状态
     */
    public function checkStatus(Request $request)
    {
        $request->validate([
            'trade_no' => 'required|string',
        ]);
        
        $tradeNo = $request->input('trade_no');
        $order = Order::where('trade_no', $tradeNo)
            ->where('user_id', $request->user()->id)
            ->first();
            
        if (!$order) {
            return $this->fail([400, '订单不存在']);
        }
        
        // 如果订单已支付，直接返回成功
        if ($order->status != Order::STATUS_PENDING) {
            return $this->success([
                'status' => 'paid',
                'message' => '订单已支付'
            ]);
        }
        
        // 获取支付信息
        $paymentInfo = Cache::get('bsc_usdt_payment_' . $tradeNo);
        if (!$paymentInfo) {
            return $this->fail([400, '支付信息不存在']);
        }
        
        // 检查是否已过期
        if (time() > $paymentInfo['expires_at']) {
            return $this->success([
                'status' => 'expired',
                'message' => '支付已过期',
                'payment_info' => $paymentInfo
            ]);
        }
        
        // 查询外部 API 获取支付状态
        $payment = Payment::find($order->payment_id);
        if (!$payment) {
            return $this->fail([400, '支付方式不存在']);
        }
        
        $paymentService = new \App\Payments\BscUsdtPay(json_decode($payment->config, true));
        $result = $paymentService->queryPaymentStatus($tradeNo);
        
        // 如果外部 API 返回已支付，更新订单状态
        if (isset($result['status']) && $result['status'] === 'paid' && isset($result['tx_hash'])) {
            $orderService = new OrderService($order);
            if ($orderService->paid($result['tx_hash'])) {
                return $this->success([
                    'status' => 'paid',
                    'message' => '支付成功',
                    'payment_info' => $paymentInfo
                ]);
            }
        }
        
        // 返回当前状态
        return $this->success([
            'status' => 'pending',
            'message' => '等待支付',
            'payment_info' => $paymentInfo
        ]);
    }
    
    /**
     * 外部回调接口
     */
    public function callback(Request $request, $paymentId)
    {
        Log::channel('daily')->info('BSC-USDT Callback', $request->all());
        
        // 获取支付方式
        $payment = Payment::where('uuid', $paymentId)->first();
        if (!$payment) {
            return response()->json(['code' => 400, 'message' => 'Payment not found'], 400);
        }
        
        // 处理回调
        try {
            $paymentService = new \App\Payments\BscUsdtPay(json_decode($payment->config, true));
            $result = $paymentService->notify($request->all());
            
            if (!$result) {
                return response()->json(['code' => 400, 'message' => 'Notification processing failed'], 400);
            }
            
            // 更新订单状态
            $order = Order::where('trade_no', $result['trade_no'])->first();
            if (!$order) {
                return response()->json(['code' => 400, 'message' => 'Order not found'], 400);
            }
            
            $orderService = new OrderService($order);
            if (!$orderService->paid($result['callback_no'])) {
                return response()->json(['code' => 400, 'message' => 'Order update failed'], 400);
            }
            
            return response()->json(['code' => 0, 'message' => 'success']);
        } catch (\Exception $e) {
            Log::channel('daily')->error('BSC-USDT Callback Error: ' . $e->getMessage());
            return response()->json(['code' => 500, 'message' => $e->getMessage()], 500);
        }
    }
    
    /**
     * 获取用户 BSC-USDT 钱包地址
     */
    public function getWalletAddress(Request $request)
    {
        $userId = $request->user()->id;
        $cacheKey = 'bsc_usdt_address_' . $userId;
        $walletAddress = Cache::get($cacheKey);
        
        if ($walletAddress) {
            return $this->success(['address' => $walletAddress]);
        }
        
        // 如果没有缓存的地址，尝试生成新地址
        // 查找使用 BSC-USDT 的支付方式
        $payment = Payment::where('payment', 'BscUsdtPay')->where('enable', 1)->first();
        if (!$payment) {
            return $this->fail([400, 'BSC-USDT 支付方式未启用']);
        }
        
        try {
            $config = json_decode($payment->config, true);
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $config['api_key']
            ])->post($config['api_url'], [
                'user_id' => $userId,
                'email' => $request->user()->email,
                'callback_url' => $config['callback_url']
            ]);
            
            if ($response->successful()) {
                $data = $response->json();
                if (isset($data['address'])) {
                    $walletAddress = $data['address'];
                    // 缓存地址，长期有效
                    Cache::put($cacheKey, $walletAddress, now()->addYear());
                    return $this->success(['address' => $walletAddress]);
                } else {
                    return $this->fail([400, '生成钱包地址失败']);
                }
            } else {
                return $this->fail([400, '生成钱包地址失败: ' . $response->body()]);
            }
        } catch (\Exception $e) {
            Log::channel('daily')->error('Generate BSC-USDT Address Error: ' . $e->getMessage());
            return $this->fail([400, '生成钱包地址失败: ' . $e->getMessage()]);
        }
    }
}
