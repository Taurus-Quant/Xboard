<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use App\Models\Order;
use App\Models\Payment;
use App\Services\OrderService;

class CheckBscUsdtPayment extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:bsc-usdt-payment';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '检查 BSC-USDT 支付状态并更新订单';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('开始检查 BSC-USDT 支付状态...');
        
        // 获取 BSC-USDT 支付方式配置
        $payment = Payment::where('payment', 'BscUsdtPay')->where('enable', 1)->first();
        if (!$payment) {
            $this->error('BSC-USDT 支付方式未启用');
            return 1;
        }
        
        $config = json_decode($payment->config, true);
        if (!isset($config['api_key'])) {
            $this->error('BSC-USDT 支付方式配置不完整');
            return 1;
        }
        
        // \u83b7\u53d6\u6240\u6709\u5f85\u5904\u7406\u7684 BSC-USDT \u652f\u4ed8
        $pendingPayments = $this->getPendingPayments();
        $count = count($pendingPayments);
        
        $this->info("\u627e\u5230 {$count} \u4e2a\u5f85\u5904\u7406\u7684\u652f\u4ed8");
        
        if ($count === 0) {
            return 0;
        }
        
        $processed = 0;
        $bscscanApiKey = $config['bscscan_api_key'] ?? '';
        
        foreach ($pendingPayments as $payment) {
            $this->info("\u5904\u7406\u8ba2\u5355: {$payment['trade_no']}");
            
            // \u68c0\u67e5\u652f\u4ed8\u662f\u5426\u5df2\u8fc7\u671f
            if (time() > $payment['expires_at']) {
                $this->warn("\u8ba2\u5355 {$payment['trade_no']} \u5df2\u8fc7\u671f");
                continue;
            }
            
            // \u4f7f\u7528 BSCScan API \u67e5\u8be2\u8be5\u5730\u5740\u7684 USDT \u8f6c\u5165\u4ea4\u6613
            $matchingTransaction = $this->checkBscUsdtTransactions(
                $payment['wallet_address'], 
                $payment['usdt_amount'], 
                $payment['created_at'],
                $bscscanApiKey
            );
            
            if ($matchingTransaction) {
                $this->info("\u53d1\u73b0\u5339\u914d\u7684\u4ea4\u6613: {$matchingTransaction['hash']}");
                
                // \u66f4\u65b0\u8ba2\u5355\u72b6\u6001
                $order = Order::where('trade_no', $payment['trade_no'])->first();
                if ($order) {
                    $orderService = new OrderService($order);
                    if ($orderService->paid($matchingTransaction['hash'])) {
                        $this->info("\u8ba2\u5355 {$payment['trade_no']} \u652f\u4ed8\u6210\u529f!");
                        
                        // \u66f4\u65b0\u652f\u4ed8\u72b6\u6001\u7f13\u5b58
                        $payment['status'] = 'paid';
                        $payment['tx_hash'] = $matchingTransaction['hash'];
                        $payment['paid_at'] = time();
                        Cache::put('bsc_usdt_payment_' . $payment['trade_no'], $payment, now()->addDays(7));
                    } else {
                        $this->error("\u8ba2\u5355 {$payment['trade_no']} \u72b6\u6001\u66f4\u65b0\u5931\u8d25");
                    }
                } else {
                    $this->error("\u8ba2\u5355 {$payment['trade_no']} \u4e0d\u5b58\u5728");
                }
            }
            
            $processed++;
        }
        
        $this->info("\u5904\u7406\u5b8c\u6210\uff0c\u5171\u5904\u7406 {$processed} \u4e2a\u652f\u4ed8");
        return 0;
    }
    
    /**
     * \u83b7\u53d6\u6240\u6709\u5f85\u5904\u7406\u7684 BSC-USDT \u652f\u4ed8
     */
    private function getPendingPayments()
    {
        $pendingPayments = [];
        
        // \u83b7\u53d6\u6240\u6709\u5f85\u652f\u4ed8\u8ba2\u5355
        $pendingOrders = Order::where('status', Order::STATUS_PENDING)
            ->where('created_at', '>=', now()->subDay())
            ->get();
        
        foreach ($pendingOrders as $order) {
            // \u68c0\u67e5\u662f\u5426\u662f BSC-USDT \u652f\u4ed8
            $paymentInfo = Cache::get('bsc_usdt_payment_' . $order->trade_no);
            if ($paymentInfo && $paymentInfo['status'] === 'pending') {
                $pendingPayments[] = $paymentInfo;
            }
        }
        
        return $pendingPayments;
    }
    
    /**
     * \u4f7f\u7528 BSCScan API \u68c0\u67e5 USDT \u8f6c\u5165\u4ea4\u6613
     * 
     * @param string $address \u94b1\u5305\u5730\u5740
     * @param float $amount \u9700\u8981\u68c0\u67e5\u7684\u91d1\u989d
     * @param int $fromTimestamp \u4ece\u4ec0\u4e48\u65f6\u95f4\u5f00\u59cb\u68c0\u67e5
     * @param string $apiKey BSCScan API Key
     * @return array|null \u5339\u914d\u7684\u4ea4\u6613\u6216 null
     */
    private function checkBscUsdtTransactions($address, $amount, $fromTimestamp, $apiKey)
    {
        // USDT \u5408\u7ea6\u5730\u5740 (BSC \u7f51\u7edc\u4e0a\u7684 USDT)
        $tokenAddress = '0x55d398326f99059fF775485246999027B3197955';
        
        try {
            // \u4f7f\u7528 BSCScan API \u83b7\u53d6\u8be5\u5730\u5740\u7684 USDT \u4ee3\u5e01\u8f6c\u8d26\u8bb0\u5f55
            $url = "https://api.bscscan.com/api?module=account&action=tokentx&contractaddress={$tokenAddress}&address={$address}&startblock=0&endblock=999999999&sort=desc&apikey={$apiKey}";
            
            $response = Http::get($url);
            if ($response->successful()) {
                $data = $response->json();
                
                if (isset($data['status']) && $data['status'] === '1' && isset($data['result']) && is_array($data['result'])) {
                    // \u904d\u5386\u6240\u6709\u4ea4\u6613
                    foreach ($data['result'] as $tx) {
                        // \u53ea\u68c0\u67e5\u8f6c\u5165\u4ea4\u6613\uff08to \u5730\u5740\u662f\u6211\u4eec\u7684\u5730\u5740\uff09
                        if (strtolower($tx['to']) === strtolower($address)) {
                            // \u68c0\u67e5\u4ea4\u6613\u65f6\u95f4\u662f\u5426\u5728\u8ba2\u5355\u521b\u5efa\u4e4b\u540e
                            $txTimestamp = intval($tx['timeStamp']);
                            if ($txTimestamp >= $fromTimestamp) {
                                // \u8ba1\u7b97\u4ea4\u6613\u91d1\u989d\uff08USDT \u6709 18 \u4f4d\u5c0f\u6570\uff09
                                $txAmount = floatval($tx['value']) / pow(10, 18);
                                
                                // \u5141\u8bb8\u5c0f\u989d\u8bef\u5dee\uff080.01 USDT\uff09
                                if (abs($txAmount - $amount) <= 0.01) {
                                    // \u627e\u5230\u5339\u914d\u7684\u4ea4\u6613
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
                $this->error("BSCScan API \u8bf7\u6c42\u5931\u8d25: " . $response->body());
            }
        } catch (\Exception $e) {
            $this->error("BSCScan API \u5f02\u5e38: " . $e->getMessage());
            Log::channel('daily')->error('BSCScan API Exception: ' . $e->getMessage());
        }
        
        return null;
    }
}
