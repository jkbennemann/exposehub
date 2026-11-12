<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Service settings admins can change in the admin area.
 *
 * Each one has a default from config (the environment, so the chart still
 * decides a fresh install). A value saved in the admin area is stored in the
 * settings table and laid over the config at boot, so every place that reads
 * config() sees it without knowing about this class.
 */
final class Settings
{
    private const CACHE_KEY = 'exposehub.settings';

    /**
     * The editable settings: key => config key and kind.
     *
     * @var array<string, array{config: string, kind: string}>
     */
    public const EDITABLE = [
        'allowed_email_domains' => ['config' => 'exposehub.allowed_email_domains', 'kind' => 'list'],
        'hidden_email_domains' => ['config' => 'exposehub.hidden_email_domains', 'kind' => 'list'],
        'log_retention_days' => ['config' => 'exposehub.log_retention_days', 'kind' => 'int'],
        'contact_email' => ['config' => 'exposehub.contact_email', 'kind' => 'string'],
    ];

    /**
     * The config values before any stored setting was applied.
     *
     * @var array<string, mixed>|null
     */
    private static ?array $defaults = null;

    /**
     * Lay the stored values over config. Called once per request at boot;
     * one cache read, and no failure when the table does not exist yet
     * (before the first migration).
     */
    public static function apply(): void
    {
        self::$defaults ??= collect(self::EDITABLE)->mapWithKeys(fn ($s, $key) => [$key => config($s['config'])])->all();

        try {
            $stored = Cache::rememberForever(self::CACHE_KEY, fn () => DB::table('settings')->pluck('value', 'key')
                ->map(fn ($value) => json_decode((string) $value, true))
                ->all());
        } catch (Throwable) {
            return;
        }

        foreach ($stored as $key => $value) {
            if (isset(self::EDITABLE[$key])) {
                config([self::EDITABLE[$key]['config'] => $value]);
            }
        }
    }

    /**
     * The value from config, ignoring what is stored.
     */
    public static function default(string $key): mixed
    {
        return self::$defaults[$key] ?? config(self::EDITABLE[$key]['config']);
    }

    public static function isOverridden(string $key): bool
    {
        return DB::table('settings')->where('key', $key)->exists();
    }

    public static function put(string $key, mixed $value, ?int $by): void
    {
        DB::table('settings')->updateOrInsert(['key' => $key], [
            'value' => json_encode($value),
            'updated_by' => $by,
            'updated_at' => now(),
            'created_at' => now(),
        ]);
        config([self::EDITABLE[$key]['config'] => $value]);
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Go back to the value from config.
     */
    public static function reset(string $key): void
    {
        DB::table('settings')->where('key', $key)->delete();
        config([self::EDITABLE[$key]['config'] => self::default($key)]);
        Cache::forget(self::CACHE_KEY);
    }
}
