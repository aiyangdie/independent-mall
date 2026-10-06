<?php

declare(strict_types=1);

namespace IndependentMall\Catalog;

use DomainException;
use IndependentMall\Security\AuditLogger;
use PDO;

final class ImageStore
{
    /** @var list<string> */
    private const ALLOWED = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(
        private PDO $pdo,
        private string $directory,
        private AuditLogger $audit,
    ) {
    }

    /**
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file
     */
    public function store(int $productId, array $file, ?int $actorId): string
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new DomainException('Upload failed.');
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size < 1 || $size > 2_000_000) {
            throw new DomainException('Image must be between 1 byte and 2 MB.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) {
            throw new DomainException('Missing upload payload.');
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp);
        if (!is_string($mime) || !isset(self::ALLOWED[$mime])) {
            throw new DomainException('Only JPEG, PNG, or WebP images are accepted.');
        }
        $ext = self::ALLOWED[$mime];
        $key = bin2hex(random_bytes(16)) . '.' . $ext;
        if (!is_dir($this->directory) && !mkdir($this->directory, 0770, true) && !is_dir($this->directory)) {
            throw new DomainException('Cannot create upload directory.');
        }
        $dest = $this->directory . DIRECTORY_SEPARATOR . $key;
        $data = file_get_contents($tmp);
        if ($data === false || file_put_contents($dest, $data) === false) {
            throw new DomainException('Could not store image.');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO product_images (product_id, storage_key, original_name, mime, created_at)
             VALUES (:p, :k, :n, :m, :t)'
        );
        $original = basename((string) ($file['name'] ?? 'image'));
        $stmt->execute([
            'p' => $productId,
            'k' => $key,
            'n' => $original,
            'm' => $mime,
            't' => gmdate('c'),
        ]);
        $this->audit->record($actorId, 'product.image', 'product', (string) $productId, ['mime' => $mime]);
        return $key;
    }

    public function pathFor(string $key): ?string
    {
        if (!preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $key)) {
            return null;
        }
        $path = $this->directory . DIRECTORY_SEPARATOR . $key;
        return is_file($path) ? $path : null;
    }
}
