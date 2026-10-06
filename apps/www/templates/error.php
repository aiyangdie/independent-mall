<?php /** @var array<string, mixed> $data */ ?>
<h1>请求失败</h1>
<p><?= htmlspecialchars((string) ($data['message'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
