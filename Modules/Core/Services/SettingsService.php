<?php

namespace Modules\Core\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Modules\Core\Models\Setting;

/**
 * Application settings with at-rest encryption for secrets.
 *
 * Encrypted values (API keys, credentials) are written with
 * Crypt::encryptString and never leave the server decrypted — admin
 * screens show only a "saved, ends in …xyz" hint, never the value.
 */
class SettingsService
{
    protected const CACHE_KEY = 'core.settings.all';

    /**
     * Get a setting value (decrypted when applicable).
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $row = $this->all()->get($key);

        if ($row === null) {
            return $default;
        }

        if ($row['is_encrypted']) {
            try {
                return Crypt::decryptString($row['value']);
            } catch (DecryptException) {
                return $default;
            }
        }

        return $row['value'];
    }

    /**
     * Create or update a setting. Pass encrypt: true for secrets.
     */
    public function set(string $key, ?string $value, string $group = 'general', bool $encrypt = false): void
    {
        Setting::query()->updateOrCreate(
            ['key' => $key],
            [
                'value' => $encrypt && $value !== null ? Crypt::encryptString($value) : $value,
                'group' => $group,
                'is_encrypted' => $encrypt,
            ]
        );

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * True when a (possibly encrypted) setting has a non-empty value —
     * lets admin UIs show "configured" without exposing the secret.
     */
    public function filled(string $key): bool
    {
        $row = $this->all()->get($key);

        return $row !== null && $row['value'] !== null && $row['value'] !== '';
    }

    /**
     * Last four characters of a secret for "ends in …" admin hints.
     */
    public function hint(string $key): ?string
    {
        $value = $this->get($key);

        return $value ? '…' . substr($value, -4) : null;
    }

    protected function all(): \Illuminate\Support\Collection
    {
        return Cache::remember(self::CACHE_KEY, 300, function () {
            return Setting::query()->get()
                ->keyBy('key')
                ->map(fn ($s) => ['value' => $s->value, 'is_encrypted' => $s->is_encrypted]);
        });
    }
}
