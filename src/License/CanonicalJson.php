<?php

declare(strict_types=1);

namespace IndependentMall\License;

final class CanonicalJson
{
    /**
     * @param array<string, mixed> $data
     */
    public static function encode(array $data): string
    {
        return json_encode(self::sort($data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function sort(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $data[$key] = self::sort($value);
            }
        }
        ksort($data);
        return $data;
    }
}
