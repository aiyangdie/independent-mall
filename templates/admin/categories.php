<?php
ob_start();
?>
<section>
    <p><a href="/admin">Dashboard</a></p>
    <h1>Categories</h1>
    <?php if (!empty($error)): ?><p class="alert"><?= $e($error) ?></p><?php endif; ?>
    <form method="post" action="/admin/categories" class="card">
        <?= $csrf ?>
        <label>Name <input name="name" required></label>
        <label>Slug <input name="slug" required pattern="[a-z0-9-]{2,64}"></label>
        <button type="submit">Add category</button>
    </form>
    <ul>
        <?php foreach ($categories as $c): ?>
            <li><a href="/c/<?= $e($c['slug']) ?>"><?= $e($c['name']) ?></a> (<?= $e($c['status']) ?>)</li>
        <?php endforeach; ?>
    </ul>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
