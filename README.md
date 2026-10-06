# Elektrifi

Elektrifi is a PHP 8 electricity bill calculator for configurable domestic energy tariffs. It starts with the published Malaysian domestic tariff, calculates the bill by tariff block, applies the RM300 minimum monthly charge, and applies 6% SST when consumption exceeds 600 kWh.

Users may also create their own tariff profile by changing:

- currency code and display symbol;
- minimum monthly charge;
- SST threshold and percentage;
- tariff block names, sizes, and per-kWh rates;
- the number of tariff blocks, up to 10.

Profiles and the five most recent calculations can be stored in Supabase or Neon. Select the provider in the calculator’s connection panel.

## Local requirements

- PHP 8.1 or later with the cURL and JSON extensions
- A Supabase or Neon project for persistence
- For Neon: the PHP `pdo_pgsql` extension and a trusted CA certificate bundle

## Supabase setup

1. Create a Supabase project.
2. Open **SQL Editor** in the Supabase dashboard.
3. Run the SQL in [`supabase/migrations/20261005134330_create_electricity_profiles_and_bills.sql`](supabase/migrations/20261005134330_create_electricity_profiles_and_bills.sql).
4. In **Integrations → Data API**, confirm the `public` schema is exposed. The migration explicitly grants only the server role access to these tables.
5. Open the project's **Connect** dialog and copy the project URL and a server-side secret key beginning with `sb_secret_`.
6. On the calculator page, expand **Connect your database**, enter the project URL and secret key, then choose **Test & connect**. The panel checks both required tables before remembering the connection. It also includes a **Copy setup SQL** button for first-time setup. Your current tariff and usage inputs survive connecting.

Personal connections last 24 hours and are separate for each browser. Credentials are stored in files with owner-only access outside the web directory; the browser receives only an opaque, HttpOnly connection token. These files contain connection configuration only; profiles and bills remain in your selected database. Use HTTPS on a hosted installation. Set `ELEKTRIFI_CONNECTION_DIR` to a persistent private directory outside the document root when needed. **Disconnect my project** removes the credentials, leaving saved data intact. A project and initial table setup must already exist; the Supabase Data API cannot execute the setup SQL.

Alternatively, configure a default connection through server or hosting environment settings:

   ```text
   SUPABASE_URL=https://your-project-ref.supabase.co
   SUPABASE_SECRET_KEY=sb_secret_your-server-only-key
   ```

The secret key must stay on the PHP server. Do not add it to JavaScript, commit it to Git, or expose it in browser configuration.

The migration enables Row Level Security, removes access from the browser-facing `anon` and `authenticated` roles, and grants the server-side `service_role` only the table permissions it needs. The PHP backend performs all ownership checks using a random, private browser identifier.

## Neon setup

1. Create a Neon project and database.
2. Run [`neon/setup.sql`](neon/setup.sql) once in the Neon SQL Editor using the same database role that will connect from PHP. This SQL does not use Supabase-specific roles or API grants.
3. Copy the PostgreSQL URL from Neon’s **Connect** dialog (pooled and direct URLs are supported).
4. In the calculator’s **Connect your database** panel, select **Neon**, paste the URL into **Neon connection string**, and choose **Test & connect**. The password is never populated into the rendered page.

Alternatively, set `NEON_DATABASE_URL` in the PHP server environment. A personal browser connection takes priority over environment settings; the Neon environment default takes priority over the Supabase default when both are configured. Disconnecting a personal connection returns to the server default.

The Neon adapter uses parameterized SQL and forces `sslmode=verify-full`, even if the pasted URL contains another SSL mode. It automatically checks common CA bundle paths. Set `NEON_SSL_ROOT_CERT` to a trusted CA bundle file if none is found. Connection-string query parameters do not override server connection or TLS settings. Only Neon hosts on port 5432 are accepted.

## Run locally

Configure an environment connection if desired, then start PHP from the project directory:

```bash
php -S 127.0.0.1:8000
```

Open `http://127.0.0.1:8000`.

Without an environment connection, connect either provider directly from the page. The calculator remains usable while disconnected.

## Reference result

The practical-test example uses 200, 100, 300, and 180 kWh in the first four tariff blocks:

| Item | Amount |
|---|---:|
| Total consumption | 780 kWh |
| Tariff consumption | RM331.08 |
| SST at 6% | RM19.86 |
| Final current bill | RM350.94 |

The RM331.08 figure is the brief's estimated tariff bill before SST. SST is displayed separately as requested by the final output specification.

## SST discrepancy

The application uses the primary 600 kWh rule. The threshold is editable in the interface, so a user can change it to 700 kWh without changing source code.
