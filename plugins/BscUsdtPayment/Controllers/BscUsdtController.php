<?php

namespace Plugin\Bscusdtpayment\Controllers;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;
use App\Models\Payment;
use Illuminate\Support\Facades\Http;

class BscUsdtController extends Controller
{
    /**
     * 获取用户的 BSC-USDT 钱包地址
     */
    public function getWalletAddress(Request $request)
    {
        $userId = $request->user()->id;
        $cacheKey = 'bsc_usdt_address_' . $userId;
        $walletAddress = Cache::get($cacheKey);
        
        if (!$walletAddress) {
            // 获取BSC-USDT支付配置
            $payment = Payment::where('payment', 'BscUsdtPay')->where('enable', 1)->first();
            if (!$payment) {
                return $this->fail([400, 'BSC-USDT支付方式未启用']);
            }
            
            $config = json_decode($payment->config, true);
            
            // 调用外部 API 获取钱包地址
            try {
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
                    } else {
                        return $this->fail([400, '生成钱包地址失败，请稍后再试']);
                    }
                } else {
                    return $this->fail([400, '生成钱包地址失败，请稍后再试']);
                }
            } catch (\Exception $e) {
                return $this->fail([400, '生成钱包地址失败，请稍后再试']);
            }
        }
        
        return $this->success(['address' => $walletAddress]);
    }
    
    /**
     * 检查支付状态
     */
    public function checkPaymentStatus(Request $request, $trade_no)
    {
        $payment = Cache::get('bsc_usdt_payment_' . $trade_no);
        
        if (!$payment) {
            return $this->fail([404, '支付订单不存在']);
        }
        
        return $this->success([
            'status' => $payment['status'],
            'expires_at' => $payment['expires_at'],
            'amount' => $payment['usdt_amount'],
            'address' => $payment['wallet_address']
        ]);
    }
    
    /**
     * 自动检查待支付订单
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
        
        // u68c0u67e5u662fu5426u542fu7528u81eau52a8u68c0u67e5
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
        $bscUsdtPay = new \Plugin\BscUsdtPayment\Payments\BscUsdtPay($config);
        $pendingPayments = $bscUsdtPay->getPendingPayments();
        $processedCount = 0;
        
        foreach ($pendingPayments as $tradeNo => $payment) {
            // 使用BSCScan API检查是否有匹配的交易
            $walletAddress = $payment['wallet_address'];
            $usdtAmount = $payment['usdt_amount'];
            
            try {
                // BSCScan API 查询 USDT 转账交易
                // USDT 合约地址: 0x55d398326f99059fF775485246999027B3197955 (BSC)
                $response = Http::get('https://api.bscscan.com/api', [
                    'module' => 'account',
                    'action' => 'tokentx',
                    'contractaddress' => '0x55d398326f99059fF775485246999027B3197955',
                    'address' => $walletAddress,
                    'startblock' => 0,
                    'endblock' => 999999999,
                    'sort' => 'desc',
                    'apikey' => $config['bscscan_api_key']
                ]);
                
                if ($response->successful()) {
                    $data = $response->json();
                    if ($data['status'] == '1' && isset($data['result']) && is_array($data['result'])) {
                        foreach ($data['result'] as $tx) {
                            // 检查交易是否在支付创建时间之后
                            if ($tx['timeStamp'] >= $payment['created_at']) {
                                // 计算 USDT 金额 (18u4f4du5c0fu6570)
                                $txAmount = hexdec($tx['value']) / pow(10, 18);
                                
                                // 检查金额是否匹配 (u51410.01u7684u8befu5dee)
                                if (abs($txAmount - $usdtAmount) <= 0.01) {
                                    // 更新支付状态
                                    $payment['status'] = 'paid';
                                    $payment['tx_hash'] = $tx['hash'];
                                    Cache::put('bsc_usdt_payment_' . $tradeNo, $payment, now()->addHours(24));
                                    
                                    // 调用支付成功回调
                                    $this->processPaymentSuccess($tradeNo, $tx['hash']);
                                    
                                    $processedCount++;
                                    break;
                                }
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                // 记录错误但继续处理其他支付
                \Illuminate\Support\Facades\Log::error('BSC-USDT Check Error: ' . $e->getMessage());
            }
        }
        
        return $this->success([
            'status' => 'completed',
            'pending_count' => count($pendingPayments),
            'processed_count' => $processedCount,
            'next_check' => $currentTime + ($checkInterval * 60)
        ]);
    }
    
    /**
     * 处理支付成功
     */
    private function processPaymentSuccess($tradeNo, $txHash)
    {
        // 调用订单系统的支付回调
        try {
            $callbackUrl = route('payment.callback', ['method' => 'bsc_usdt', 'trade_no' => $tradeNo]);
            Http::post($callbackUrl, [
                'trade_no' => $tradeNo,
                'callback_no' => $txHash,
                'status' => 'SUCCESS'
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('BSC-USDT Callback Error: ' . $e->getMessage());
        }
    }
}
