<?php
ob_start();
?>
<section>
    <p><a href="/admin">返回后台首页</a></p>
    <h1>顾客管理</h1>
    <?php if (!empty($error)): ?><p class="alert" role="alert"><?= $e($error) ?></p><?php endif; ?>
    <div class="table-wrap">
    <table>
        <thead><tr><th>编号</th><th>邮箱</th><th>用户名</th><th>角色</th><th>状态</th><th>操作</th></tr></thead>
        <tbody>
        <?php foreach ($accounts as $a): ?>
            <tr>
                <td><?= $e($a['id']) ?></td>
                <td><?= $e($a['email']) ?></td>
                <td><?= $e($a['username']) ?></td>
                <td><?= $e($a['roles'] ?? '') ?></td>
                <td><?= $e(\IndependentMall\Support\Chinese::label($a['status'])) ?></td>
                <td>
                    <?php if (($a['status'] ?? '') === 'active'): ?>
                        <form method="post" action="/admin/customers/<?= $e($a['id']) ?>/locked" onsubmit="return confirm('确定锁定这个账户吗？')"><?= $csrf ?><button class="danger" type="submit">锁定</button></form>
                    <?php else: ?>
                        <form method="post" action="/admin/customers/<?= $e($a['id']) ?>/active"><?= $csrf ?><button type="submit">解除锁定</button></form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
