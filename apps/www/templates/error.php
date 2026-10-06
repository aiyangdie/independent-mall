<?php /** @var array<string, mixed> $data */ ?>
<h1>Request failed</h1>
<p><?= htmlspecialchars((string) ($data['message'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
