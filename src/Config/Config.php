<?php

declare(strict_types=1);

namespace IndependentMall\Config;

final class Config
{
    /** @var array<string, string> */
    private array $values;

    /**
     * @param array<string, string> $values
     */
    public function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function load(string $root): self
    {
        $file = $root . DIRECTORY_SEPARATOR . '.env';
        $values = [
            'ROOT' => $root,
            'APP_ENV' => 'local',
            'APP_DEBUG' => '0',
            'APP_URL' => 'http://127.0.0.1:8090',
            'APP_KEY' => '',
            'APP_TIMEZONE' => 'UTC',
            'APP_CURRENCY' => 'CNY',
            'APP_LOCALE' => 'en',
            'DATABASE_URL' => 'sqlite:///' . $root . '/var/data/app.sqlite',
            'SESSION_NAME' => 'im_session',
            'SESSION_SECURE' => '0',
            'SESSION_LIFETIME' => '7200',
            'ADMIN_EMAIL' => '',
            'ADMIN_PASSWORD' => '',
        ];

        $example = $root . DIRECTORY_SEPARATOR . '.env.example';
        if (is_file($example)) {
            $values = array_merge($values, self::parseFile($example));
        }
        if (is_file($file)) {
            $values = array_merge($values, self::parseFile($file));
        }

        $values['ROOT'] = $root;
        if (isset($values['DATABASE_URL'])) {
            $values['DATABASE_URL'] = str_replace('%ROOT%', str_replace('\\', '/', $root), $values['DATABASE_URL']);
        }

        return new self($values);
    }

    /**
     * @return array<string, string>
     */
    private static function parseFile(string $path): array
    {
        $out = [];
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return [];
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1));
            if ($val !== '' && (($val[0] === '"' && str_ends_with($val, '"')) || ($val[0] === "'" && str_ends_with($val, "'")))) {
                $val = stripcslashes(substr($val, 1, -1));
            }
            $out[$key] = $val;
        }
        return $out;
    }

    public function get(string $key, string $default = ''): string
    {
        return $this->values[$key] ?? $default;
    }

    public function bool(string $key): bool
    {
        $v = strtolower($this->get($key, '0'));
        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->get($key, '');
        return $v === '' ? $default : (int) $v;
    }

    public function root(): string
    {
        return $this->get('ROOT');
    }
}
