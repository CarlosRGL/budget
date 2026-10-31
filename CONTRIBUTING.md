# Contributing

Thanks for your interest in Budget. Bug reports, translations and pull requests are welcome.

## Development setup

Requirements: PHP 8.4+, Composer, Bun, and MariaDB/MySQL or SQLite.

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
bun install && bun run build
composer run dev            # web server, queue listener, logs and Vite
php artisan budget:demo     # optional: fill a household with demo data
```

The scheduler is not part of `composer run dev`: run `php artisan schedule:work` when you work on the scheduled bank sync. For bank sync itself you need an EnableBanking application: see [docs/enablebanking.md](docs/enablebanking.md). Tests never call EnableBanking (HTTP is faked with recorded answers from `tests/Fixtures/enablebanking/`).

Routes are typed for the front end by [Wayfinder](https://github.com/laravel/wayfinder): after adding or changing a route, run `php artisan wayfinder:generate --with-form` (the Vite plugin also does it during `bun run dev`/`build`). The generated files (`resources/js/routes`, `resources/js/actions`) are not committed.

## Code rules

- **Money is in integer cents** everywhere (`int` in PHP, `bigInteger` columns, `number` of cents in TypeScript). Never use floats for amounts. A budget month is a `date` on the 1st of the month.
- **Everything belongs to a household.** The household is Laravel's `Team` in the code. Business models are scoped to the current team (`BelongsToTeam` global scope, routes under `/{current_team}`): data must never leak to another household. Add an isolation test when you add a model or a route.
- **Balances are computed, not stored**: envelope balances and "Ready to assign" are always recomputed from assignments and transactions.
- **Members with the viewer role are read-only**: they only reach the simple screens (`app/Http/Middleware/KeepViewersReadOnly.php`). Keep new pages and actions out of their reach unless they are meant for them.
- **Portable SQL**: the app runs on MariaDB/MySQL and the tests on SQLite in memory. Avoid database-specific functions.
- **Tests**: PHPUnit feature tests for behaviour; the budget engine and the bank sync are written test-first. Use the model factories.
- **No hard-coded interface text**: every string goes through the translation helpers (see below).
- Follow the conventions of the surrounding code (controllers, Inertia pages in `resources/js/pages`, shadcn/ui components in `resources/js/components/ui`).

### Checks

Run these before opening a pull request; CI runs them too (`composer ci:check`).

```bash
php artisan test --compact     # PHPUnit
vendor/bin/pint --dirty        # PHP code style
vendor/bin/phpstan analyse     # static analysis (Larastan, level 7)
bunx vp check                  # front-end lint and format (bunx vp check --fix to fix)
bunx tsc --noEmit              # TypeScript
```

## Translations

English is the source language: the English text is the translation key.

- Front end: `t('Add account')` and `tc(':count account|:count accounts', count)` from `resources/js/lib/i18n.ts`, with Laravel's placeholder and plural syntax. For a sentence with a link or bold text inside, use `<Trans>` from `resources/js/components/trans.tsx` so the sentence stays one key.
- Back end: `__('Add account')`.
- Translations live in `lang/{locale}.json` (the app's strings) and `lang/{locale}/*.php` (Laravel's validation, auth, password messages…, from [laravel-lang](https://laravel-lang.com/)).

### Fixing or completing a translation

Edit `lang/{locale}.json`. Keys are the English strings, exactly as in the code; keep the placeholders (`:name`, `:count`) and the `|` plural forms.

### Adding a language

1. Create `lang/{locale}.json` with every key of an existing language file (for example copy `lang/fr.json` and translate the values).
2. Add `lang/{locale}/*.php`: `php artisan lang:add {locale}` (laravel-lang) or copy and translate the files of `lang/fr/`.
3. Add the language to `SUPPORTED` in `app/Support/Locales.php`, with its name in that language (`'de' => 'Deutsch'`).
4. Add its `Intl` tag to the `intlLocales` map in `resources/js/lib/i18n.ts` (`de: 'de-DE'`), used for dates and amounts.
5. Check the language switch in the profile settings and a few screens; run the tests.

## Pull requests

- Open an issue first for a large change or a new feature, so we can agree on the approach.
- Keep a pull request focused on one thing, with tests for behaviour changes.
- Describe what changes for the user, and add a screenshot for interface changes (desktop and mobile).
- Write commit messages in English, in the imperative ("Add …", "Fix …").
- Never commit secrets: `.env`, EnableBanking keys (`*.pem`), database dumps.

By contributing, you agree that your contributions are licensed under the [GNU AGPL-3.0-or-later](LICENSE), like the rest of the project.
