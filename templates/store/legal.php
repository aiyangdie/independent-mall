<?php
ob_start();
$kind = (string) ($kind ?? '');
$copy = [
    'refunds' => '实体商品遵循适用于商家的冷静期与质量规则。数字商品仅在上架前已说明且法律允许的范围内适用更窄的退款安排。本社区版演示中的 Fake 支付不会产生真实可退款项。本页不取代强制性消费者保护法。更长草案见 docs/legal/refunds.md。',
    'prohibited' => '请勿上架非法商品、侵权复制品、外挂、未授权机器人、恶意软件、第三方账号交易、刷量刷榜，或商家无权销售的虚拟货币。店铺关键词拦截不能替代商家的法定义务。更长草案见 docs/legal/prohibited-goods.md。',
    'disclaimer' => '商家对商品、资质、税务与消费者保护承担责任。本社区版软件不保证不间断运行。法律禁止排除的责任，本页不作排除。更长草案见 docs/legal/disclaimer.md。',
][$kind] ?? '未知法律页面。';
?>
<section class="narrow">
    <h1><?= $e($title ?? '法律信息') ?></h1>
    <p class="notice" role="note"><strong>仅为草案。</strong>未经律师审核。版权主体待定。</p>
    <p><?= $e($copy) ?></p>
    <p><a href="/">返回目录</a> · <a href="/legal/refunds">退款说明</a> · <a href="/legal/prohibited">禁售商品</a> · <a href="/legal/disclaimer">免责声明</a></p>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
