<?php
use IndependentMall\Catalog\Money;
use IndependentMall\Support\Chinese;
ob_start();
?>
<section>
    <p><a href="/admin">返回后台首页</a></p>
    <h1>商品管理</h1>
    <?php if (!empty($error)): ?>
        <p class="alert" role="alert"><?= $e($error) ?></p>
    <?php endif; ?>
    <form method="post" action="/admin/products" class="card">
        <?= $csrf ?>
        <h2>新建商品</h2>
        <label>商品分类
            <select name="category_id" required>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= $e($c['id']) ?>"><?= $e($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>商品类型
            <select name="type">
                <option value="physical">实物商品</option>
                <option value="digital">数字商品</option>
                <option value="service">服务商品</option>
            </select>
        </label>
        <label>商品名称 <input name="name" required></label>
        <label>网址标识 <input name="slug" required pattern="[a-z0-9-]+" placeholder="例如 ceramic-mug"></label>
        <label>商品摘要 <input name="summary"></label>
        <label>商品详情 <textarea name="description"></textarea></label>
        <label>规格编码 <input name="sku" required placeholder="每个商品规格使用唯一编码"></label>
        <label>规格名称 <input name="variant_title" value="标准款"></label>
        <label>价格（分） <input name="price_minor" type="number" min="0" required><small class="muted">例如 1990 表示 19.90 元</small></label>
        <label>初始库存 <input name="on_hand" type="number" min="0" value="0"></label>
        <button type="submit">保存草稿</button>
    </form>
    <div class="table-wrap">
    <table>
        <thead><tr><th>商品名称</th><th>状态</th><th>类型</th><th>操作</th></tr></thead>
        <tbody>
        <?php foreach ($products as $p): ?>
            <tr>
                <td><?= $e($p['name']) ?></td>
                <td><?= $e(Chinese::label($p['status'])) ?></td>
                <td><?= $e(Chinese::label($p['type'])) ?></td>
                <td>
                    <form method="post" action="/admin/products/<?= $e($p['id']) ?>/submit"><?= $csrf ?><button>提交审核</button></form>
                    <form method="post" action="/admin/products/<?= $e($p['id']) ?>/publish"><?= $csrf ?><button>发布</button></form>
                    <form method="post" action="/admin/products/<?= $e($p['id']) ?>/reject"><?= $csrf ?><input name="note" value="不符合平台规则" aria-label="驳回原因"><button class="danger">驳回</button></form>
                    <form method="post" action="/admin/products/<?= $e($p['id']) ?>/archive" onsubmit="return confirm('确定归档这个商品吗？')"><?= $csrf ?><button class="danger">归档</button></form>
                    <form method="post" action="/admin/products/<?= $e($p['id']) ?>/image" enctype="multipart/form-data"><?= $csrf ?><input type="file" name="image" accept="image/jpeg,image/png,image/webp" aria-label="选择商品图片"><button>上传图片</button></form>
                    <form method="post" action="/admin/products/<?= $e($p['id']) ?>/stock"><?= $csrf ?><input name="delta" type="number" value="1" aria-label="库存增减数量"><input name="reference" placeholder="业务流水号（防重复）" required><button>调整库存</button></form>
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
