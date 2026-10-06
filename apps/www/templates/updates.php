<?php
/** @var array<string, mixed> $data */
$r = $data['report'];
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<h1>检查更新</h1>
<p>此页面只验证更新元数据，不会替换文件、执行 PHP 或下载远程安装程序。</p>
<ul>
    <li>未签名通道：<?= $e((string) $r['unsigned']) ?></li>
    <li>签名通道：<?= $e((string) $r['signed']) ?></li>
    <li>版本：<?= $e((string) $r['version']) ?></li>
    <li>SHA-256: <code><?= $e((string) $r['sha256']) ?></code></li>
    <li>是否已安装：<?= !empty($r['applied']) ? '是' : '否' ?></li>
</ul>
<p>要在你控制的服务器安装更新，请复制更新包，人工核对哈希后运行 <code>php bin/console update:apply</code>。</p>
<p><a href="/account">返回客户中心</a></p>
