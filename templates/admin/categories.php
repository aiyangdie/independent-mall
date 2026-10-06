<?php
ob_start();
?>
<section>
    <p><a href="/admin">返回后台首页</a></p>
    <h1>商品分类</h1>
    <?php if (!empty($error)): ?><p class="alert"><?= $e($error) ?></p><?php endif; ?>
    <form method="post" action="/admin/categories" class="card">
        <?= $csrf ?>
        <label>分类名称 <input name="name" required></label>
        <label>网址标识 <input name="slug" required pattern="[a-z0-9-]{2,64}" aria-describedby="slug-help"></label>
        <small id="slug-help" class="muted">只能使用小写字母、数字和短横线，例如：daily-goods</small>
        <button type="submit">添加分类</button>
    </form>
    <ul>
        <?php foreach ($categories as $c): ?>
            <li><a href="/c/<?= $e($c['slug']) ?>"><?= $e($c['name']) ?></a>（<?= $e(\IndependentMall\Support\Chinese::label($c['status'])) ?>）</li>
        <?php endforeach; ?>
    </ul>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
