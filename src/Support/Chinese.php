<?php

declare(strict_types=1);

namespace IndependentMall\Support;

final class Chinese
{
    /** @var array<string, string> */
    private const LABELS = [
        'active' => '正常', 'inactive' => '未启用', 'locked' => '已锁定',
        'draft' => '草稿', 'review_pending' => '待审核', 'published' => '已发布',
        'rejected' => '已驳回', 'archived' => '已归档', 'pending' => '待处理',
        'pending_payment' => '待付款', 'paid' => '已付款', 'completed' => '已完成',
        'cancelled' => '已取消', 'refund_requested' => '退款申请中', 'requested' => '待审核',
        'approved' => '已批准', 'succeeded' => '成功', 'failed' => '失败',
        'open' => '待处理', 'answered' => '已回复', 'closed' => '已关闭',
        'bound' => '已绑定', 'revoked' => '已吊销', 'unknown' => '未知',
        'physical' => '实物商品', 'digital' => '数字商品', 'service' => '服务商品',
        'fake' => '演示支付', 'fake_completed' => '演示订单完成', 'fake_renewed' => '演示续费完成',
        'allow' => '允许', 'block' => '禁止', 'title' => '标题', 'description' => '描述',
    ];

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
