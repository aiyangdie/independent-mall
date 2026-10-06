<?php
/** @var array<string, mixed> $data */
$kind = (string) ($data['kind'] ?? 'terms');
$copy = [
    'terms' => '商业条款、退款规则和授权许可目前均为草案。任何付费销售开始前，必须由版权主体聘请律师审核批准。运营者还应阅读 docs/legal/user-terms.md 与 docs/legal/commercial-agreement.md。',
    'privacy' => '本演示站在本地保存邮箱与密码哈希，模板中未接入第三方统计像素。商业授权中心只保存令牌哈希和实例编号，详见 docs/license-server-data.md；隐私政策长版草案见 docs/legal/privacy.md。',
    'aup' => '运营者不得利用本系统承载违法活动。可接受使用政策草案见 docs/legal/acceptable-use.md。软件限制不能替代运营者依法履责。',
    'refunds' => '本站演示购买不会真实扣款，因此不存在资金退款。正式商城的实物商品须遵守适用于商家的消费者冷静期和质量规则；数字商品只有在付款前明确告知且法律允许时，才可适用较窄的退款范围。本条不排除消费者保护法的强制规定。详见 docs/legal/refunds.md。',
    'prohibited' => '禁止发布违法商品、侵权复制品、外挂、未经授权的机器人、恶意软件、第三方账号交易、流量或排名操纵服务，以及商家无权销售的非官方虚拟货币。关键词拦截不能替代商家的法定义务。详见 docs/legal/prohibited-goods.md。',
    'disclaimer' => '运营者和商家仍须对商品、资质、税务与消费者保护负责。本软件不保证永不中断，也不排除法律禁止排除的责任。严禁违法商品与欺诈行为。详见 docs/legal/disclaimer.md。',
][$kind] ?? '';
?>
<h1><?= htmlspecialchars((string) $data['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
<p><?= htmlspecialchars($copy, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
<p>本页面不构成法律意见，尚未经律师审核，版权主体与软件许可证仍未最终确定。</p>
