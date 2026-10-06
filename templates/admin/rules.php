<?php
ob_start();
?>
<section>
    <p><a href="/admin">返回后台首页</a></p>
    <h1>禁售规则</h1>
    <div class="table-wrap">
    <table>
        <thead><tr><th>匹配内容</th><th>检查字段</th><th>处理方式</th><th>已启用</th></tr></thead>
        <tbody>
        <?php foreach ($rules as $r): ?>
            <tr>
                <td><?= $e($r['pattern']) ?></td>
                <td><?= $e(\IndependentMall\Support\Chinese::label($r['match_field'])) ?></td>
                <td><?= $e(\IndependentMall\Support\Chinese::label($r['action'])) ?></td>
                <td><?= ((int) $r['enabled'] === 1) ? '是' : '否' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
