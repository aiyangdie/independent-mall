<?php

declare(strict_types=1);

namespace IndependentMall\Http;

final class Csrf
{
    public const SESSION_KEY = '_csrf';

    public function token(): string
    {
        if (empty($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::SESSION_KEY];
    }

    public function field(): string
    {
        $t = htmlspecialchars($this->token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<input type="hidden" name="_csrf" value="' . $t . '">';
    }

    public function assert(Request $request): bool
    {
        $sent = $request->post('_csrf');
        $ok = hash_equals($this->token(), $sent);
        return $ok;
    }
}
