# 邮件与找回密码

## 当前能力与边界

商城的 `Mailer` 接口新增可配置的 `SmtpMailer`，通过 Symfony Mailer / Mime 7.4 发送 UTF-8 纯文本邮件。该系列支持 PHP 8.2+，使用 MIT 许可证；版本锁定在 `composer.lock`。没有连接真实邮箱账户，也没有验证实际邮件投递、垃圾邮件分类或服务商配额。

`MAIL_ADAPTER=null` 只允许本地开发与测试静默丢弃邮件。生产环境选择空适配器、未知适配器或 SMTP 配置不完整时会得到 `UnavailableMailer`，邮件操作失败，商城其他功能仍可启动；不会偷偷回退为 PHP `mail()` 或假发送。

## 生产配置

将以下字段填写到部署服务器被 Git 忽略的 `.env`，不要提交或在工单中提供口令：

```dotenv
APP_ENV=production
APP_DEBUG=0
APP_URL=https://shop.example.invalid
MAIL_ADAPTER=smtp
MAIL_FROM_ADDRESS=no-reply@example.invalid
MAIL_FROM_NAME=您的商城
SMTP_HOST=smtp.example.invalid
SMTP_PORT=587
SMTP_ENCRYPTION=tls
SMTP_AUTH=1
SMTP_USERNAME=REPLACE_WITH_YOUR_ACCOUNT
SMTP_PASSWORD=REPLACE_WITH_YOUR_MAIL_APP_PASSWORD
SMTP_TIMEOUT=10
SMTP_CA_FILE=
```

使用服务商自己的发件账号、经授权的发件域名和应用口令。`tls` 表示强制 STARTTLS，`ssl` 表示隐式 TLS（通常端口 465）。两者都必须验证证书、证书主机名与信任链，仅接受 TLS 1.2 / 1.3，不提供关闭验证的开关。服务商不支持 TLS 时会在认证之前拒绝连接。`SMTP_CA_FILE` 只用于提供自定义可信 CA 文件，不用于放行不可信证书。

`none` 仅限 `APP_ENV=local/dev/development/test/testing` 且 `SMTP_HOST=127.0.0.1/::1/localhost` 的测试收件箱。生产必须启用 SMTP 认证。超时范围 1–30 秒；当前同步发信，没有队列或自动重试，部署者需考虑邮箱服务响应与 PHP 请求超时。

生产 `APP_URL` 必须是可信 HTTPS 站点根地址，不接受账号口令、查询串、片段或换行；本地测试可显式允许回环 HTTP。不能从用户传来的 Host / X-Forwarded-Host 拼接密码重置链接。

## 接入代码

```php
use IndependentMall\Mail\MailerFactory;
use IndependentMall\Mail\ResetLink;

$accounts = new CustomerAccounts(
    $pdo, $hasher, MailerFactory::fromConfig($config), $audit, $auth,
    ResetLink::fromConfig($config),
);
```

所有 HTTP 创建 `CustomerAccounts` 的入口都应如此注入；服务类默认回环链接仅兼容旧的 Array/Null 单元测试，真实邮件适配器缺少显式链接配置会拒绝发送。

找回密码页面须始终返回不暴露账号是否存在的相同反馈，不能输出 SMTP 异常或重置令牌。服务端记录 `user.reset_delivery_failed` 供管理员排查，但不保存邮箱、原始令牌、发件认证口令或服务商错误文本。SMTP 已经收到邮件但连接随后断开的特殊场景会按失败撤销令牌，用户需重新申请。

每个规范化邮箱（只保存哈希）的重置申请最多每小时 3 次，已有和未知账户执行同样的限流。还应在反向代理或 HTTP 入口设置按 IP 的频率限制，降低未知邮箱垃圾请求造成的数据库压力。邮件包含中文一次性 HTTPS 链接，有效期 1 小时；令牌仅存 SHA-256，消费使用事务和条件更新，成功重置后其余未使用链接失效，停用账户不能重置。发送失败会清理相应令牌。

## 部署验收（仍待真实渠道）

1. 配置合法 HTTPS 域名，确认 `APP_DEBUG=0`、发件账号权限及服务商配额。
2. 按邮箱服务商说明配置 SPF / DKIM / DMARC，检查发件域名验证。
3. 由站点管理员使用自己的测试收件地址申请一次重置，检查实际到达、中文标题、正确站点域名与有效期。
4. 确认无效 / 过期 / 重复使用的链接不可改密码，失败页面与日志无凭据和令牌。
5. 检查邮件服务中断时核心商城仍可运行，错误不泄露已注册邮箱。

## 本地验证

```text
php vendor/bin/phpunit --no-coverage tests/MailSmtpTest.php tests/MailResetTest.php
```

测试启动一个临时回环 SMTP 收件器，真实走 SMTP 协议并捕获中文邮件；不访问外部邮箱或使用真实认证信息。另覆盖强制 TLS、禁止降级发送口令、证书验证配置、头部注入、异常脱敏、配置失败关闭、重置链接与哈希存储、失败清理和限流。本地通过不代表真实渠道已上线。

参考：[Symfony 7.4 支持周期](https://symfony.com/releases/7.4)、[Mailer 官方文档](https://symfony.com/doc/7.4/mailer.html)、[MIT 许可证](https://github.com/symfony/mailer/blob/7.4/LICENSE)。
