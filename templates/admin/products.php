<?php
use IndependentMall\Catalog\Money;
ob_start();
?>
<section>
    <p><a href="/admin">Dashboard</a></p>
    <h1>Products</h1>
    <?php if (!empty($error)): ?>
        <p class="alert" role="alert"><?= $e($error) ?></p>
    <?php endif; ?>
    <form method="post" action="/admin/products" class="card">
        <?= $csrf ?>
        <h2>Create listing</h2>
        <label>Category
            <select name="category_id" required>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= $e($c['id']) ?>"><?= $e($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Type
            <select name="type">
                <option value="physical">Physical</option>
                <option value="digital">Digital</option>
                <option value="service">Service</option>
            </select>
        </label>
        <label>Name <input name="name" required></label>
        <label>Slug <input name="slug" required pattern="[a-z0-9-]+"></label>
        <label>Summary <input name="summary"></label>
        <label>Description <textarea name="description"></textarea></label>
        <label>SKU <input name="sku" required></label>
        <label>Variant title <input name="variant_title" value="Standard"></label>
        <label>Price (minor units) <input name="price_minor" type="number" min="0" required></label>
        <label>On hand <input name="on_hand" type="number" min="0" value="0"></label>
        <button type="submit">Save draft</button>
    </form>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Name</th><th>Status</th><th>Type</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($products as $p): ?>
            <tr>
                <td><?= $e($p['name']) ?></td>
                <td><?= $e($p['status']) ?></td>
                <td><?= $e($p['type']) ?></td>
                <td>
                    <form method="post" action="/admin/products/<?= $e($p['id']) ?>/submit"><?= $csrf ?><button>Review</button></form>
                    <form method="post" action="/admin/products/<?= $e($p['id']) ?>/publish"><?= $csrf ?><button>Publish</button></form>
                    <form method="post" action="/admin/products/<?= $e($p['id']) ?>/reject"><?= $csrf ?><input name="note" value="Does not meet policy"><button>Reject</button></form>
                    <form method="post" action="/admin/products/<?= $e($p['id']) ?>/archive"><?= $csrf ?><button>Archive</button></form>
                    <form method="post" action="/admin/products/<?= $e($p['id']) ?>/image" enctype="multipart/form-data"><?= $csrf ?><input type="file" name="image" accept="image/jpeg,image/png,image/webp"><button>Upload image</button></form>
                    <form method="post" action="/admin/products/<?= $e($p['id']) ?>/stock"><?= $csrf ?><input name="delta" type="number" value="1"><input name="reference" placeholder="idempotency key"><button>Adjust stock</button></form>
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
