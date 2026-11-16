# Retail POS

Point of Sale and retail management for a single business with multiple branches, with Indian GST.
Built with Laravel 13, Vue 3 (Inertia 3) and Tailwind CSS 4.

- **Documentation:** [docs/README.md](docs/README.md)
- **Setup:** [docs/03-getting-started.md](docs/03-getting-started.md)
- **Roadmap:** [docs/07-roadmap.md](docs/07-roadmap.md)

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
composer run dev     # or: php artisan serve + npm run dev
```

Demo login: `admin@pos.test` / `password`.
