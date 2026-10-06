<?php /** @var array<string, mixed> $data */ ?>
<h1>产品文档</h1>
<p>商城文档保存在社区版仓库中，本站不会从 CDN 动态加载文档。</p>

<h2>部署与运行社区版</h2>
<ul>
    <li><code>docs/deploy.md</code> — TLS 代理、会话配置和生产签名说明</li>
    <li><code>docs/ops.md</code> — 安装、备份、恢复和离线核心</li>
    <li><code>docs/release-checklist.md</code> — 本地发布核对清单</li>
</ul>

<h2>架构与版本边界</h2>
<ul>
    <li><code>docs/architecture.md</code> — 模块化单体架构</li>
    <li><code>docs/licensing-design.md</code> — 双版本和商业权益设计</li>
    <li><code>docs/community-commercial-boundary.md</code> — 社区版禁止强制联网的功能边界</li>
</ul>

<h2>商业授权中心（独立进程）</h2>
<ul>
    <li><code>apps/license-server/README.md</code> — 密钥、产品版本目录、签发、续费、激活、吊销和清理</li>
    <li><code>docs/license-server-data.md</code> — 保存字段、保留周期和永不收集的数据</li>
    <li><a href="/pricing">价格演示流程</a> — 演示购买 → 授权订单 → 续费 → 导入社区版后台</li>
</ul>

<p class="callout callout-fake" role="status">
    更新客户端必须在替换文件前验证 SHA-256 和 Ed25519。未签名通道绝不能自动安装。
    本官网只检查更新元数据，不会把更新包自动安装到商城中。
</p>
