<?php

declare(strict_types=1);

namespace IndependentMall\Auth;

final class PasswordHasher
{
    public function hash(string $plain): string
    {
        $this->assertStrong($plain);
        return password_hash($plain, PASSWORD_ARGON2ID);
    }

    public function verify(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }

    public function assertStrong(string $plain): void
    {
        if (strlen($plain) < 10) {
            throw new \InvalidArgumentException('Password must be at least 10 characters.');
        }
        $lower = strtolower($plain);
        foreach (['password', '1234567890', 'qwertyuiop'] as $weak) {
            if ($lower === $weak) {
                throw new \InvalidArgumentException('Password is too common.');
            }
        }
    }
}
