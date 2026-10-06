<?php
ob_start();
?>
<section>
    <p><a href="/admin">返回后台首页</a></p>
    <h1>商业模块与授权</h1>
    <p class="muted">商业模块是可选功能。社区版的浏览、下单和管理不依赖模块或授权服务器。</p>
    <?php if (!empty($error)): ?><p class="alert" role="alert"><?= $e($error) ?></p><?php endif; ?>
    <?php if (!empty($faults)): ?>
        <ul class="alert" role="status">
            <?php foreach ($faults as $f): ?>
                <li><?= $e($f) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php
    $local = is_array($local_license ?? null) ? $local_license : [];
    $trust = is_array($local['trust'] ?? null) ? $local['trust'] : [];
    ?>
    <div class="card">
        <h2>软件商信任公钥</h2>
        <?php if (!empty($trust['configured'])): ?>
            <p>信任公钥已配置（<?= $e((string) ($trust['source'] ?? '')) ?>）。
                指纹：<code><?= $e((string) ($trust['fingerprint'] ?? '')) ?></code>。
                导入授权时只使用此公钥验证，授权文件不能替换公钥。</p>
            <?php if (!empty($trust['packaged_locked'])): ?>
                <p class="muted">安装包已内置 <code>config/license-public.pem</code>，因此禁止在后台替换信任公钥。</p>
            <?php endif; ?>
        <?php else: ?>
            <p class="muted">尚未安装软件商公钥，请先核对指纹并安装官方公钥，再导入授权文件。</p>
        <?php endif; ?>
    </div>
    <?php if (empty($trust['packaged_locked'])): ?>
    <form method="post" action="/admin/license/trust-key" class="card">
        <?= $csrf ?>
        <h2>安装或更换信任公钥</h2>
        <p class="muted">公钥是独立的信任根，不属于授权文件。更换已有公钥时必须填写当前指纹。</p>
        <label>软件商公钥（PEM）<textarea name="public_pem" rows="6" required></textarea></label>
        <label>当前指纹（更换时必填）<input name="confirm_fingerprint" autocomplete="off" value=""></label>
        <button type="submit">保存信任公钥</button>
    </form>
    <?php endif; ?>
    <div class="card">
        <h2>本地授权文件</h2>
        <?php if (!empty($local['present'])): ?>
            <p>本地验签通过<?= !empty($local['valid']) ? '' : '（签名正确，但当前授权权益不可用）' ?>。
                授权编号 <code><?= $e((string) $local['license_id']) ?></code>，
                绑定实例 <code><?= $e((string) $local['instance_id']) ?></code>，
                到期时间 <?= $e((string) $local['expires_at']) ?>。
                <?php if (($local['product_code'] ?? '') !== ''): ?>
                    产品 <code><?= $e((string) $local['product_code']) ?>@<?= $e((string) ($local['product_version'] ?? '')) ?></code>。
                <?php endif; ?>
                <?php if (($local['customer_id'] ?? '') !== ''): ?>
                    客户编号 <code><?= $e((string) $local['customer_id']) ?></code>。
                <?php endif; ?>
                权益：<?= $e(implode(', ', $local['entitlements'] ?? [])) ?>。
                状态：<?= !empty($local['revoked']) ? '已吊销（商业模块关闭，社区版下单不受影响）' : (!empty($local['valid']) ? '有效' : '无效') ?></p>
        <?php else: ?>
            <p class="muted">没有通过验证的本地授权文件，社区版仍可正常下单。</p>
        <?php endif; ?>
    </div>
    <form method="post" action="/admin/license" class="card">
        <?= $csrf ?>
        <h2>离线授权文件</h2>
        <p class="muted">只粘贴已签名的 JSON 授权文件。系统使用已配置的软件商公钥验证；导入失败不会破坏现有授权。</p>
        <label>签名授权 JSON <textarea name="license_json" rows="8" required></textarea></label>
        <button type="submit">验证并保存到本机</button>
    </form>
    <form method="post" action="/admin/license/refresh" class="card">
        <?= $csrf ?>
        <h2>定时刷新授权</h2>
        <p class="muted">只有此后台操作或 <code>php bin/console license:refresh</code> 会连接授权服务，前台访问绝不会连接。服务器临时不可用时，本地授权在宽限期内继续有效。</p>
        <label>授权编号 <input name="license_id" required value="<?= $e((string) ($local['license_id'] ?? '')) ?>"></label>
        <label>授权令牌 <input name="token" required autocomplete="off"></label>
        <label>实例编号 <input name="instance_id" required value="<?= $e((string) ($local['instance_id'] ?? '')) ?>"></label>
        <button type="submit">从服务器刷新</button>
    </form>
    <?php if (!empty($license_server)): ?>
        <p class="muted">前台不会连接已配置的授权服务器，建议由管理员定时执行刷新。</p>
    <?php endif; ?>
    <?php if ($modules === []): ?>
        <p>在 <code>modules/*/module.php</code> 下未发现可选模块。</p>
    <?php endif; ?>
    <?php foreach ($modules as $m): ?>
        <article class="card">
            <h2><?= $e($m['label']) ?> <span class="muted">(<?= $e($m['id']) ?>)</span></h2>
            <p>已迁移：<?= !empty($m['migrated']) ? '是' : '否' ?> · 已启用：<?= !empty($m['enabled']) ? '是' : '否' ?> · 本地授权：<?= !empty($m['licensed']) ? '是' : '否' ?></p>
            <form method="post" action="/admin/modules/<?= $e($m['id']) ?>/migrate"><?= $csrf ?><button type="submit">迁移数据</button></form>
            <form method="post" action="/admin/modules/<?= $e($m['id']) ?>/enable"><?= $csrf ?><button type="submit">启用</button></form>
            <form method="post" action="/admin/modules/<?= $e($m['id']) ?>/disable"><?= $csrf ?><button type="submit">停用</button></form>
            <form method="post" action="/admin/modules/<?= $e($m['id']) ?>/uninstall" onsubmit="return confirm('确定卸载该模块吗？请先备份数据。')"><?= $csrf ?><button class="danger" type="submit">卸载</button></form>
        </article>
    <?php endforeach; ?>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
