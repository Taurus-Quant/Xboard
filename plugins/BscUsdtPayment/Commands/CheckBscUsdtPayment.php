<?php

namespace Plugin\Bscusdtpayment\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use App\Models\Payment;

class CheckBscUsdtPayment extends Command
{
    /**
     * 命令名称
     *
     * @var string
     */
    protected $signature = 'check:bsc-usdt-payment';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '检查 BSC-USDT 支付状态';

    /**
     * 执行命令
     */
    public function handle()
    {
        $this->info('开始检查 BSC-USDT 支付状态...');
        
        // 获取BSC-USDT支付配置
        $payment = Payment::where('payment', 'BscUsdtPay')->where('enable', 1)->first();
        if (!$payment) {
            $this->error('BSC-USDT支付方式未启用');
            return 1;
        }
        
        $config = json_decode($payment->config, true);
        if (!isset($config['bscscan_api_key'])) {
            $this->error('BSC-USDT支付配置不完整');
            return 1;
        }
        
        // 获取所有待处理的BSC-USDT支付
        $bscUsdtPay = new \Plugin\BscUsdtPayment\Payments\BscUsdtPay($config);
        $pendingPayments = $bscUsdtPay->getPendingPayments();
        
        $this->info('找到 ' . count($pendingPayments) . ' 个待处理的支付');
        
        $processedCount = 0;
        
        foreach ($pendingPayments as $tradeNo => $payment) {
            $this->info("检查支付 {$tradeNo}...");
            
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
                                // 计算 USDT 金额 (18位小数)
                                $txAmount = hexdec($tx['value']) / pow(10, 18);
                                
                                // 检查金额是否匹配 (允许0.01的误差)
                                if (abs($txAmount - $usdtAmount) <= 0.01) {
                                    $this->info("找到匹配交易: {$tx['hash']}，金额: {$txAmount} USDT");
                                    
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
                $this->error('BSC-USDT Check Error: ' . $e->getMessage());
            }
        }
        
        $this->info("检查完成，处理了 {$processedCount} 个支付");
        
        return 0;
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
            
            $this->info("已发送支付成功回调: {$callbackUrl}");
        } catch (\Exception $e) {
            $this->error('BSC-USDT Callback Error: ' . $e->getMessage());
        }
    }
}
