<?php

namespace Plugin\Bscusdtpayment;

use App\Services\Plugin\BasePlugin;

class Plugin extends BasePlugin
{
    public function boot()
    {
        // 注册支付方式
        app('payment.manager')->register('BscUsdtPay', function ($config) {
            return new Payments\BscUsdtPay($config);
        });
    }

    public function install()
    {
        // 安装时执行的代码
        // 例如添加默认配置到数据库
    }

    public function uninstall()
    {
        // 卸载时执行的代码
    }
}
