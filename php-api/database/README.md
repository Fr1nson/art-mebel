# Database Setup

## Local (XAMPP)
1. Open `phpMyAdmin`.
2. Import `all_in_one_xampp.sql` from this folder.
3. Confirm data:
```sql
SELECT COUNT(*) AS total_products FROM products;
SELECT id, name, rating FROM products ORDER BY rating DESC, id DESC LIMIT 8;
```
4. Copy `php-api/.env.example` to `php-api/.env` and set DB values if needed.

## Hosting
1. Create database and user in hosting panel.
2. Import `schema.sql`.
3. Optionally import `seed_original_catalog.sql` for demo catalog.
4. Set production variables in `php-api/.env`:
```env
APP_ENV=production
JWT_SECRET=your-long-random-secret
DB_HOST=your-db-host
DB_PORT=3306
DB_DATABASE=your-db-name
DB_USERNAME=your-db-user
DB_PASSWORD=your-db-pass
DB_SSL_CA=/absolute/path/to/ca.pem
```

## Files
- `schema.sql` - only database structure (safe for production).
- `seed_original_catalog.sql` - demo reset + catalog seed.
- `all_in_one_xampp.sql` - one-shot import for local XAMPP.
- `migrations/` - optional incremental SQL upgrades.
