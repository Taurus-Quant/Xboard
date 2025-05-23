<div class="bsc-usdt-payment">
    <h3>BSC-USDT 支付</h3>
    <div class="payment-info">
        <p>请向以下地址转账 {{ $amount }} USDT (BSC网络)</p>
        <div class="wallet-address">{{ $address }}</div>
        <div class="qr-code">
            {!! QrCode::size(200)->generate($qrContent) !!}
        </div>
        <p class="note">请确保使用 BSC 网络进行转账，其他网络的转账可能会导致资金丢失。</p>
    </div>
</div>