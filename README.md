# Elektrifi

Elektrifi is a PHP 8 electricity bill calculator for configurable domestic energy tariffs. It starts with the published Malaysian domestic tariff, calculates the bill by tariff block, applies the RM300 minimum monthly charge, and applies 6% SST when consumption exceeds 600 kWh.

Users may also create their own tariff profile by changing:

- currency code and display symbol;
- minimum monthly charge;
- SST threshold and percentage;
- tariff block names, sizes, and per-kWh rates;
- the number of tariff blocks, up to 10.

Profiles and the five most recent calculations are stored in Supabase. No other database is used.

## Local requirements

- PHP 8.1 or later with the cURL and JSON extensions
- A Supabase project for persistence

## Supabase setup

1. Create a Supabase project.
2. Open **SQL Editor** in the Supabase dashboard.
3. Run the SQL in [`supabase/migrations/20261005134330_create_electricity_profiles_and_bills.sql`](supabase/migrations/20261005134330_create_electricity_profiles_and_bills.sql).
4. In **Integrations → Data API**, confirm the `public` schema is exposed. The migration explicitly grants only the server role access to these tables.
5. Open the project's **Connect** dialog and copy the project URL and a server-side secret key beginning with `sb_secret_`.
6. Add both values to the server or hosting provider's environment settings:

   ```text
   SUPABASE_URL=https://your-project-ref.supabase.co
   SUPABASE_SECRET_KEY=sb_secret_your-server-only-key
   ```

The secret key must stay on the PHP server. Do not add it to JavaScript, commit it to Git, or expose it in browser configuration.

The migration enables Row Level Security, removes access from the browser-facing `anon` and `authenticated` roles, and grants the server-side `service_role` only the table permissions it needs. The PHP backend performs all ownership checks using a random, private browser identifier.

## Run locally

Export the two environment values, then start PHP from the project directory:

```bash
php -S 127.0.0.1:8000
```

Open `http://127.0.0.1:8000`.

Without the environment values, the calculator still demonstrates its calculation and validation behavior, but it clearly reports that Supabase persistence is unavailable.

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
