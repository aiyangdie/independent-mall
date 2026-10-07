<?php
/** @var array<string, mixed> $data */
/** @var \IndependentMall\Http\Csrf $csrf */
$csrf = $data['csrf'];
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$flash = $e((string) ($data['flash'] ?? ''));
$zh = static fn (mixed $v): string => \IndependentMall\Support\Chinese::label($v);
?>
<h1>客户中心</h1>
<?php if ($flash !== ''): ?><p class="ok"><?= $flash ?></p><?php endif; ?>
<form method="post" action="/logout"><?= $csrf->field() ?><button type="submit">退出登录</button></form>
<p><a href="/account/updates">检查更新（只验证，不自动安装）</a></p>

<h2>演示购买</h2>
<p class="callout callout-fake">此操作只记录演示订单，并为 <code>sample-notice@1.0.0</code> 签发测试授权，不会真实扣款。</p>
<form method="post" action="/account/buy"><?= $csrf->field() ?><button type="submit">演示购买公告模块</button></form>

<h2>官网演示订单</h2>
<ul>
<?php foreach ($data['orders'] as $row): ?>
    <li>#<?= (int) $row['id'] ?> <?= $e((string) $row['sku']) ?> · <?= $e($zh($row['status'])) ?> · <?= $e($zh($row['adapter'])) ?></li>
<?php endforeach; ?>
</ul>

<h2>授权中心商业订单</h2>
<p>授权中心的演示记账（币种 <code>XXX</code>），不涉及银行卡扣款。
<?php if (($data['issuer_customer_id'] ?? '') !== ''): ?>
    客户编号 <code><?= $e((string) $data['issuer_customer_id']) ?></code>。
<?php endif; ?>
</p>
<?php if (empty($data['issuer_orders'])): ?>
<p>暂无授权订单，请使用上方演示购买功能。</p>
<?php else: ?>
<ul>
<?php foreach ($data['issuer_orders'] as $row): ?>
    <li>
        <code><?= $e((string) $row['id']) ?></code>
        · <?= $e((string) $row['product_code']) ?>@<?= $e((string) $row['product_version']) ?>
        · 授权 <code><?= $e((string) $row['license_id']) ?></code>
        · <?= (int) $row['amount_minor'] ?> <?= $e((string) $row['currency']) ?>
        · <?= $e($zh($row['status'])) ?> · <?= $e($zh($row['adapter'])) ?>
    </li>
<?php endforeach; ?>
</ul>
<?php endif; ?>

<h2>我的授权</h2>
<ul>
<?php foreach ($data['licenses'] as $row): ?>
    <li><code><?= $e((string) $row['license_id']) ?></code>
        状态 <strong><?= $e($zh($row['status'] ?? 'unknown')) ?></strong>
        <?php if (($row['product_code'] ?? '') !== ''): ?>
            · 产品 <code><?= $e((string) $row['product_code']) ?>@<?= $e((string) ($row['product_version'] ?? '')) ?></code>
        <?php endif; ?>
        <?php if (($row['customer_id'] ?? '') !== ''): ?>
            · 客户 <code><?= $e((string) $row['customer_id']) ?></code>
        <?php endif; ?>
        <?php if (!empty($row['revoked'])): ?>
            （商业模块已停用，社区版下单不受影响）
        <?php elseif (!empty($row['bound'])): ?>
            · 已绑定 <code><?= $e((string) $row['instance_id']) ?></code>
        <?php else: ?>
            · 尚未绑定实例
        <?php endif; ?>
        <?php if (($row['expires_at'] ?? '') !== ''): ?>
            · 到期时间 <code><?= $e((string) $row['expires_at']) ?></code>
        <?php endif; ?>
        · <a href="/account/license.json?id=<?= $e((string) $row['license_id']) ?>">下载签名授权 JSON</a>
        </li>
<?php endforeach; ?>
</ul>
<p>用于社区版安装信任根和核对指纹的演示软件商公钥：
    <a href="/account/license-public.pem">下载演示软件商公钥（PEM）</a>
    （只需在 <code>/admin/license/trust-key</code> 安装一次；不要随每份授权 JSON 重复粘贴。文件不包含长期授权令牌。）</p>
<form method="post" action="/account/activate">
    <?= $csrf->field() ?>
    <label>授权编号 <input name="license_id" required></label>
    <label>授权令牌 <input name="token" required></label>
    <label>激活随机码 <input name="nonce" required></label>
    <label>实例编号 <input name="instance_id" required></label>
    <button type="submit">绑定实例</button>
</form>
<form method="post" action="/account/unbind">
    <?= $csrf->field() ?>
    <label>授权编号 <input name="license_id" required></label>
    <label>授权令牌 <input name="token" required></label>
    <button class="danger" type="submit">解除绑定</button>
</form>
<form method="post" action="/account/renew">
    <?= $csrf->field() ?>
    <p class="callout callout-fake">仅演示续费：记录官网与授权中心演示订单，并延长签名授权，不会真实扣款。</p>
    <label>授权编号 <input name="license_id" required></label>
    <label>授权令牌 <input name="token" required></label>
    <label>续费天数 <input name="days" type="number" min="1" max="3660" value="365" required></label>
    <button type="submit">演示续费</button>
</form>
<form method="post" action="/account/revoke">
    <?= $csrf->field() ?>
    <label>授权编号 <input name="license_id" required></label>
    <label>授权令牌 <input name="token" required></label>
    <button class="danger" type="submit">吊销授权（演示）</button>
</form>

<h2>文件下载（单次、10 分钟有效）</h2>
<p>每个链接只能使用一次。重新打开客户中心可生成新令牌；未使用的链接可在下方撤销。安全日志不会记录完整令牌。</p>
<ul>
<?php foreach ($data['files'] as $id => $token): ?>
    <li><a href="/account/download?token=<?= $e((string) $token) ?>"><?= $e((string) $id) ?></a></li>
<?php endforeach; ?>
</ul>
<?php if (!empty($data['active_tokens'])): ?>
<ul>
<?php foreach ($data['active_tokens'] as $tok): ?>
    <li><code><?= $e((string) $tok['file_id']) ?></code>
        已使用 <?= (int) $tok['use_count'] ?>/<?= (int) $tok['max_uses'] ?> 次
        · 到期 <?= $e(gmdate('c', (int) $tok['expires_at'])) ?>
        <form method="post" action="/account/download/revoke" style="display:inline">
            <?= $csrf->field() ?>
            <input type="hidden" name="jti" value="<?= $e((string) $tok['jti']) ?>">
            <button class="danger" type="submit">撤销</button>
        </form>
    </li>
<?php endforeach; ?>
</ul>
<?php endif; ?>

<h2>下载审计</h2>
<ul>
<?php foreach ($data['downloads'] as $row): ?>
    <li><?= $e((string) $row['created_at']) ?> <?= $e((string) $row['file_id']) ?> <?= ((int) $row['ok'] === 1) ? '已允许' : '已拒绝' ?></li>
<?php endforeach; ?>
</ul>

<h2>售后工单</h2>
<p>状态流程：待处理 → 已回复（演示客服）→ 已关闭。当前尚未连接正式客服邮箱。</p>
<form method="post" action="/account/ticket">
    <?= $csrf->field() ?>
    <label>工单标题 <input name="title" required></label>
    <label>问题描述 <textarea name="body" required></textarea></label>
    <button type="submit">提交工单</button>
</form>
<ul>
<?php foreach ($data['tickets'] as $row): ?>
    <li>
        #<?= (int) $row['id'] ?>
        <strong><?= $e((string) $row['title']) ?></strong>
        状态 <code><?= $e($zh($row['status'] ?? 'open')) ?></code>
        <?php if ((string) ($row['staff_reply'] ?? '') !== ''): ?>
            · 回复：<?= $e((string) $row['staff_reply']) ?>
        <?php endif; ?>
        <?php if (($row['status'] ?? 'open') !== 'closed'): ?>
            <form method="post" action="/account/ticket/answer" style="margin-top:.5rem">
                <?= $csrf->field() ?>
                <input type="hidden" name="ticket_id" value="<?= (int) $row['id'] ?>">
                <label>演示客服回复 <input name="reply" required></label>
                <button type="submit">回复（演示客服）</button>
            </form>
            <form method="post" action="/account/ticket/close" style="display:inline">
                <?= $csrf->field() ?>
                <input type="hidden" name="ticket_id" value="<?= (int) $row['id'] ?>">
                <button type="submit">关闭工单</button>
            </form>
        <?php endif; ?>
    </li>
<?php endforeach; ?>
</ul>

<h2>安全日志</h2>
<ul>
<?php foreach ($data['security'] as $row): ?>
    <li><?= $e((string) $row['created_at']) ?> <?= $e($zh($row['action'])) ?></li>
<?php endforeach; ?>
</ul>
