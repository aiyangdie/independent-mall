<?php

declare(strict_types=1);

namespace IndependentMall\License;

final class AdminRefresh
{
    public function __construct(
        private string $root,
        private JsonTransport $transport,
    ) {
    }

    public function refresh(string $serverUrl, string $licenseId, string $token, string $instanceId): void
    {
        $serverUrl = trim($serverUrl);
        if ($serverUrl === '') {
            throw new \DomainException('LICENSE_SERVER_URL is empty. Paste an offline document instead of calling a server.');
        }
        $url = rtrim($serverUrl, '/') . '/refresh';
        $docPath = $this->root . '/var/license.json';
        try {
            $doc = $this->transport->postJson($url, [
                'license_id' => $licenseId,
                'token' => $token,
                'instance_id' => $instanceId,
            ]);
        } catch (\Throwable $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
        if (!is_dir($this->root . '/var')) {
            mkdir($this->root . '/var', 0775, true);
        }
        $tmp = $docPath . '.tmp';
        file_put_contents($tmp, json_encode($doc, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $pub = $this->root . '/var/license-public.pem';
        if (!is_file($pub)) {
            $pub = $this->root . '/config/license-public.pem';
        }
        $gate = new FileLicenseGate($tmp, $pub);
        if ($gate->payload() === null) {
            @unlink($tmp);
            throw new \RuntimeException('Refreshed document failed local verification. Existing local document kept for grace.');
        }
        if (!rename($tmp, $docPath)) {
            copy($tmp, $docPath);
            @unlink($tmp);
        }
    }
}
