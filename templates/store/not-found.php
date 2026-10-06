<?php
ob_start();
?>
<section>
    <h1>页面不存在</h1>
    <p>该商品或页面不可用。</p>
    <p><a href="/">返回商品目录</a></p>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
