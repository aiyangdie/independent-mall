<?php
declare(strict_types=1);
namespace IndependentMall\Payment;

use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

final class PaymentQr
{
    public static function svg(string $codeUrl): string
    {
        if (!str_starts_with($codeUrl, 'weixin://wxpay/bizpayurl?') || strlen($codeUrl) > 2048) {
            throw new \DomainException('微信支付二维码无效。');
        }
        return (new Writer(new ImageRenderer(new RendererStyle(280, 16), new SvgImageBackEnd())))->writeString($codeUrl);
    }
}
