<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The languages of the interface. English strings are the translation keys;
 * the other languages live in lang/{locale}.json and lang/{locale}/*.php.
 */
class Locales
{
    /**
     * @var array<string, string>
     */
    public const SUPPORTED = [
        'en' => 'English',
        'fr' => 'Français',
        'es' => 'Español',
    ];

    public static function isSupported(?string $locale): bool
    {
        return $locale !== null && array_key_exists($locale, self::SUPPORTED);
    }

    /**
     * The member's language, else the browser's, else the app default.
     */
    public static function forRequest(Request $request): string
    {
        $chosen = $request->user()?->locale;

        if (self::isSupported($chosen)) {
            return $chosen;
        }

        $preferred = $request->getPreferredLanguage(array_keys(self::SUPPORTED));
        $default = (string) config('app.locale');

        return self::isSupported($preferred) && $request->headers->has('Accept-Language')
            ? (string) $preferred
            : (self::isSupported($default) ? $default : 'en');
    }

    /**
     * The language of the names the app stores in the household's data
     * (payees it creates, memos), whoever triggers it: the app default, not
     * the member's language, so a shared payee is not duplicated per language.
     */
    public static function forData(): string
    {
        $locale = (string) config('app.data_locale');

        return self::isSupported($locale) ? $locale : 'en';
    }

    /**
     * Translate a text stored in the household's data.
     *
     * @param  array<string, string>  $replace
     */
    public static function data(string $key, array $replace = []): string
    {
        return (string) __($key, $replace, self::forData());
    }

    /**
     * The JSON strings of a language, for the browser.
     *
     * @return array<string, string>
     */
    public static function messages(string $locale): array
    {
        $path = lang_path("{$locale}.json");

        return $locale === 'en' || ! is_file($path) ? [] : (array) json_decode((string) file_get_contents($path), true);
    }
}
