<?php /** @var array<string, mixed> $data */ ?>
<h1>产品价格</h1>
<p class="callout callout-fake" role="status">
    <strong>当前仅支持演示支付。</strong>本站尚未连接银行卡、钱包或银行转账。
    客户中心只会记录演示订单，供测试授权签发、绑定、解绑、吊销、下载和更新检查流程。
</p>
<p class="callout callout-legal" role="note">
    <strong>尚未经过律师审核。</strong>页面金额均为占位示例。正式销售前必须确定版权主体，并由律师审核定价、税务、退款和合同文本。
</p>
<ul>
    <li>社区版：本仓库不收取授权费。</li>
    <li>示例公告模块（<code>sample-notice@1.0.0</code>）：展示价 99.00，仅通过演示支付记录 9900 个最小货币单位，由授权中心产品目录签发。</li>
    <li>演示续费：展示价 49.00，仅记录 4900 个最小货币单位，并生成状态为 <code>fake_renewed</code> 的授权订单。</li>
</ul>
<h2>演示操作流程</h2>
<ol>
    <li>在官网注册并打开客户中心。</li>
    <li>演示购买公告模块 → 创建授权客户 <code>www_u{id}</code>、演示商业订单和签名授权。</li>
    <li>绑定实例后演示续费 → 创建续费订单，并延长 <code>expires_at</code>。</li>
    <li>下载签名 JSON 和软件商公钥 <code>license-public.pem</code>。在社区版通过 <code>/admin/license/trust-key</code>（或安装包内的 <code>config/license-public.pem</code>）只安装一次信任公钥，然后仅将签名 JSON 导入 <code>/admin/license</code>。</li>
</ol>
<p><a class="btn" href="/register">创建演示账户</a> <a class="btn ghost" href="/docs">查看授权文档</a> <a class="btn ghost" href="/legal/disclaimer">阅读免责声明草案</a></p>
