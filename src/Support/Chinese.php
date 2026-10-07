<?php

declare(strict_types=1);

namespace IndependentMall\Support;

final class Chinese
{
    /** @var array<string, string> */
    private const LABELS = [
        'active' => '正常', 'inactive' => '未启用', 'hidden' => '已隐藏', 'locked' => '已锁定',
        'draft' => '草稿', 'review_pending' => '待审核', 'in_review' => '待审核', 'published' => '已发布',
        'rejected' => '已驳回', 'archived' => '已归档', 'pending' => '待处理',
        'pending_payment' => '待付款', 'paid' => '已付款', 'completed' => '已完成',
        'cancelled' => '已取消', 'refund_requested' => '退款申请中', 'refunded' => '已退款', 'requested' => '待审核',
        'awaiting_fulfillment' => '待履约', 'fulfilling' => '待收货', 'shipped' => '已发货', 'delivered' => '已收货',
        'approved' => '已批准待渠道处理', 'succeeded' => '成功', 'failed' => '失败',
        'open' => '待处理', 'answered' => '已回复', 'closed' => '已关闭',
        'bound' => '已绑定', 'unbound' => '未绑定', 'revoked' => '已吊销', 'unknown' => '未知',
        'expired' => '已过期', 'absent' => '未配置', 'valid' => '有效', 'invalid' => '无效',
        'physical' => '实物商品', 'digital' => '数字商品', 'service' => '服务商品',
        'fake' => '演示支付', 'fake_completed' => '演示订单完成', 'fake_renewed' => '演示续费完成',
        'alipay' => '支付宝', 'wechat' => '微信支付', 'wechat_pay' => '微信支付',
        'packaged' => '安装包内置', 'local' => '本地配置', 'environment' => '环境配置',
        'unavailable' => '商品已下架', 'insufficient_stock' => '库存不足，请调整商品数量',
        'refused_auto_install' => '已拒绝自动安装', 'verified_not_applied' => '验签通过，尚未安装',
        'unverified' => '尚未验证', 'unexpected_unsigned_ok' => '未签名检查异常，请联系管理员',
        'register' => '创建账户', 'login' => '登录成功', 'login_fail' => '登录失败',
        'fake_buy' => '演示购买', 'fake_renew' => '演示续费', 'activate' => '绑定授权实例',
        'unbind' => '解除授权绑定', 'revoke' => '吊销授权', 'ticket_open' => '提交工单',
        'ticket_answer' => '回复工单', 'ticket_close' => '关闭工单',
        'download_token_issue' => '生成下载链接', 'download_token_revoke' => '撤销下载链接',
        'download_ok' => '下载成功', 'inspect_updates' => '检查更新',
        'license_document_download' => '下载签名授权文件',
        'allow' => '允许', 'block' => '禁止', 'title' => '标题', 'description' => '描述',
    ];

    /** Only system messages belong here; merchant product names and descriptions are never translated. */
    private const MESSAGES = [
        'The form expired. Try again.' => '表单已过期，请刷新页面后重试。',
        'Sign-in failed or this account is not an administrator.' => '登录失败，或当前账户没有管理员权限。',
        'A valid email is required.' => '请输入有效的邮箱地址。',
        'Enter a valid email address.' => '请输入有效的邮箱地址。',
        'Username must be 3-32 letters, numbers, or underscore.' => '用户名须为 3–32 位字母、数字或下划线。',
        'That email or username is already registered.' => '该邮箱或用户名已注册，请直接登录或使用其他信息。',
        'Unknown account or password.' => '账户或密码不正确。',
        'Current password is incorrect.' => '当前密码不正确。',
        'Password must be at least 10 characters.' => '密码至少需要 10 个字符。',
        'Password is too common.' => '密码过于常见，请使用更安全的密码。',
        'Reset token is invalid or expired.' => '重置链接无效或已过期，请重新申请。',
        'Unsupported account status.' => '不支持该账户状态。',
        'You cannot change your own account status here.' => '不能在此修改自己的账户状态。',
        'Administrator accounts cannot be locked from this screen.' => '不能在此锁定管理员账户。',
        'Category name and slug are required.' => '请填写分类名称和有效的网址标识。',
        'Unknown product type.' => '请选择有效的商品类型。',
        'Price and stock must be non-negative integers.' => '价格和库存须为非负整数，价格以分为单位。',
        'Cannot publish without a variant.' => '请先添加商品规格，再发布商品。',
        'Product not found.' => '商品不存在或已移除。',
        'Unknown variant.' => '商品规格不存在或已移除。',
        'Inventory would become invalid.' => '库存调整无效，请检查剩余库存和预留数量。',
        'Upload failed.' => '上传失败，请重新选择文件。',
        'Image must be between 1 byte and 2 MB.' => '图片不能为空，且大小不能超过 2 MB。',
        'Missing upload payload.' => '没有收到上传文件，请重新选择图片。',
        'Only JPEG, PNG, or WebP images are accepted.' => '仅支持 JPEG、PNG 和 WebP 图片。',
        'Cannot create upload directory.' => '图片保存目录不可用，请联系管理员。',
        'Could not store image.' => '图片保存失败，请稍后重试。',
        'Address not found.' => '收货地址不存在，请重新选择。',
        'Recipient and address line are required.' => '请填写收件人和详细地址。',
        'Quantity must be between 1 and 99.' => '购买数量须在 1–99 之间。',
        'Not enough stock.' => '商品库存不足，请减少购买数量。',
        'This item is not available.' => '商品已下架或暂不可购买。',
        'Checkout idempotency key is required.' => '订单提交信息已失效，请刷新结算页后重试。',
        'Cart is empty.' => '购物车为空，请先选择商品。',
        'Cart contains unavailable items.' => '购物车中有已下架或库存不足的商品，请检查后重试。',
        'A shipping address is required for physical goods.' => '购买实物商品时，请先选择收货地址。',
        'Only unpaid orders can be cancelled.' => '只有未付款订单可以取消。',
        'Order not found.' => '订单不存在或无权访问。',
        'This order cannot be refunded.' => '该订单当前无法申请退款。',
        'Refund amount exceeds the paid total.' => '退款金额不能超过已付款金额。',
        'Refund not found.' => '退款申请不存在。',
        'Refund already decided.' => '该退款申请已审核，请勿重复操作。',
        'Unknown refund decision.' => '请选择有效的退款审核结果。',
        'Renewal days are not acceptable.' => '续费天数无效，请检查输入。',
        'Current expiry is not acceptable.' => '当前授权到期时间异常，请联系管理员。',
        'Ticket needs a title and body.' => '请填写工单标题和问题描述。',
        'Reply body is required.' => '请填写回复内容。',
        'Closed tickets cannot be answered.' => '已关闭的工单不能继续回复。',
        'Ticket status is not acceptable.' => '工单当前状态不支持此操作。',
        'Unknown ticket.' => '工单不存在。',
        'Ticket does not belong to this account.' => '没有权限访问该工单。',
        'Unknown file.' => '下载文件不存在。',
        'File missing.' => '下载文件暂不可用，请联系管理员。',
        'Unknown download token.' => '下载链接不存在或已失效。',
        'Bad download token.' => '下载链接无效，请重新生成。',
        'Unknown license.' => '授权不存在。',
        'License does not belong to this account.' => '没有权限访问该授权。',
        'License document is corrupt.' => '授权文件异常，请联系管理员。',
        'Account email is missing.' => '账户邮箱未设置，请联系管理员。',
        'Public key PEM is invalid.' => '公钥格式无效，请使用官方 PEM 公钥文件。',
        'Private keys must not be stored on the shop.' => '商城不能保存签名私钥，请只导入公钥。',
        'License JSON is required.' => '请填写签名授权 JSON。',
        'Vendor public key is not configured. Install the trust public key before importing a license.' => '请先核对并安装软件商信任公钥，再导入授权文件。',
        'License document failed verification. Existing license was left unchanged.' => '授权文件验证失败，原有授权未被修改。',
        'This instance is already installed.' => '当前商城已安装，不能重复安装。',
        'A .env file already exists. Refusing to overwrite it.' => '本机已有配置文件，为保护原有配置，已拒绝覆盖。',
        'Environment checks failed.' => '运行环境检查未通过，请处理上方问题后重试。',
        'A valid administrator email is required.' => '请输入有效的管理员邮箱。',
        'APP_URL must start with http:// or https://' => '网站地址须以 http:// 或 https:// 开头。',
    ];

    public static function message(mixed $value, bool $genericOnUnknown = true): string
    {
        $message = trim((string) $value);
        if ($message === '') {
            return '';
        }
        if (isset(self::MESSAGES[$message])) {
            return self::MESSAGES[$message];
        }
        if (str_starts_with($message, 'Cannot publish: ') || str_starts_with($message, 'Listing blocked by prohibited-goods rule: ')) {
            return '商品不符合禁售规则，暂时无法上架。请检查商品内容。';
        }
        if (!$genericOnUnknown || preg_match('/^[\p{Han}]/u', $message) === 1) {
            return $message;
        }
        // Do not surface unknown SQL, server paths, or provider messages to customers.
        return '操作未完成，请检查输入或稍后重试。如问题持续，请联系管理员。';
    }

    public static function label(mixed $value): string
    {
        $key = strtolower(trim((string) $value));
        return self::LABELS[$key] ?? (string) $value;
    }

    public static function yesNo(mixed $value): string
    {
        return $value ? '是' : '否';
    }
}
