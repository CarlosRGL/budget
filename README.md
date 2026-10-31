# Budget

Envelope budgeting for a household, self-hosted. Give every euro a job, see what is left in each envelope, and let the bank feed in the transactions.

Budget follows the envelope method popularised by YNAB: money you have is assigned to categories (envelopes) month by month, spending comes out of them, and "Ready to assign" tells you what still has no job. It is built for a household that shares one budget, with your bank accounts synced automatically through [EnableBanking](https://enablebanking.com/).

## Features

- **Envelope budgeting**: groups and categories, monthly assignments, money moves between envelopes, targets (refill up to, set aside, weekly, by a date) and auto-assign. Balances are always recomputed from the transactions, never stored.
- **A household, several members**: everyone works on the same budget with their own login. Invite members by email or share the invitation link.
- **Read-only simple view**: give a member the viewer role and they get a simpler screen: what is planned, spent and left this month, without being able to change anything.
- **Bank sync for European banks** through EnableBanking (PSD2 open banking): up to 4 automatic syncs a day, imported transactions waiting for approval, duplicate detection, payee clean-up and renaming rules. Each member connects their own bank. See [docs/enablebanking.md](docs/enablebanking.md).
- **Accounts**: checking, savings, cash, investment and loan accounts, split transactions, and a check of each account against the bank balance.
- **Loans**: track mortgages and other loans, their amortization and the capital repaid each month.
- **Stats**: spending by category and over time.
- **Installable app (PWA)** that works well on a phone.
- **English, French and Spanish**, chosen per member. Amounts are in euros.

## Screenshots

Coming soon: screenshots go in `docs/screenshots/`.

## Quick start (local development)

Requirements: PHP 8.4 or later (with the `openssl`, `mbstring` and `pdo_mysql` or `pdo_sqlite` extensions), [Composer](https://getcomposer.org/), [Bun](https://bun.sh/) and a database (MariaDB/MySQL, or SQLite for a quick try).

```bash
git clone <this repository> budget
cd budget
composer install
cp .env.example .env
php artisan key:generate
```

Set the database in `.env` (`DB_CONNECTION`, `DB_DATABASE`, …). For a quick try with SQLite: `DB_CONNECTION=sqlite`, remove the other `DB_*` lines and run `touch database/database.sqlite`. Then:

```bash
php artisan migrate
bun install && bun run build
composer run dev
```

`composer run dev` starts the web server (<http://localhost:8000>), a queue listener, the log viewer and Vite with hot reload. The scheduler is not part of it: run `php artisan schedule:work` in another terminal if you want the automatic bank syncs locally.

`composer run setup` does the install, key, migrate and build steps in one go.

### First run

Open the site: the first person to register creates the household and becomes its owner. After that, registration is by invitation only: invite the other members from the household settings.

On a server without a browser at hand, `php artisan budget:install` creates the household and its first member from the command line.

To look around with fake data, `php artisan budget:demo` fills a household with a demo budget (see `php artisan budget:demo --help`).

## Self-hosting

A Docker image runs the web server, the queue worker and the scheduler in one container. See [docs/self-hosting.md](docs/self-hosting.md) for the environment variables, a Docker Compose example, HTTPS, backups and updates.

## Bank sync

Bank sync needs your own (free, for personal use) EnableBanking application. Once the instance runs, open **Bank connections → Set up bank sync** for a step-by-step guide with a live checklist, or read [docs/enablebanking.md](docs/enablebanking.md).

## Tech stack

- [Laravel 13](https://laravel.com/) (PHP 8.4+), [Fortify](https://laravel.com/docs/fortify) for authentication (with two-factor and passkeys)
- [Inertia.js 3](https://inertiajs.com/) and [React 19](https://react.dev/) with TypeScript
- [Tailwind CSS 4](https://tailwindcss.com/) and [shadcn/ui](https://ui.shadcn.com/) components
- [Vite+](https://viteplus.dev/) for the front-end build, [Wayfinder](https://github.com/laravel/wayfinder) for typed routes
- MariaDB/MySQL in production, SQLite for the tests
- PHPUnit, Pint and Larastan

## Contributing

Contributions are welcome. Read [CONTRIBUTING.md](CONTRIBUTING.md) for the development setup, the code rules and how to add a language.

## License

Budget is free software, released under the [GNU Affero General Public License v3.0 or later](LICENSE). If you run a modified version for others over a network, you must offer them its source code.
