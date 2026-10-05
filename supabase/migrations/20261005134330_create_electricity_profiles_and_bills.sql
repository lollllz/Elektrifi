create extension if not exists pgcrypto;

create table public.electricity_profiles (
  id uuid primary key default gen_random_uuid(),
  client_id uuid not null unique,
  currency_code varchar(3) not null default 'MYR'
    check (currency_code ~ '^[A-Z]{3}$'),
  currency_symbol varchar(8) not null default 'RM'
    check (char_length(currency_symbol) between 1 and 8),
  minimum_charge numeric(12, 4) not null default 300
    check (minimum_charge >= 0),
  sst_rate numeric(7, 6) not null default 0.06
    check (sst_rate between 0 and 1),
  sst_threshold_kwh numeric(12, 3) not null default 600
    check (sst_threshold_kwh >= 0),
  tariff_tiers jsonb not null,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  constraint tariff_tiers_is_array check (jsonb_typeof(tariff_tiers) = 'array'),
  constraint tariff_tiers_has_items check (jsonb_array_length(tariff_tiers) between 1 and 10)
);

create table public.bill_calculations (
  id bigint generated always as identity primary key,
  profile_id uuid not null references public.electricity_profiles(id) on delete cascade,
  currency_code varchar(3) not null,
  usage_by_tier jsonb not null,
  total_kwh numeric(14, 3) not null check (total_kwh >= 0),
  tariff_subtotal numeric(14, 4) not null check (tariff_subtotal >= 0),
  minimum_adjustment numeric(14, 4) not null check (minimum_adjustment >= 0),
  sst_amount numeric(14, 4) not null check (sst_amount >= 0),
  final_total numeric(14, 4) not null check (final_total >= 0),
  created_at timestamptz not null default now(),
  constraint usage_by_tier_is_array check (jsonb_typeof(usage_by_tier) = 'array')
);

create index bill_calculations_profile_created_idx
  on public.bill_calculations (profile_id, created_at desc);

alter table public.electricity_profiles enable row level security;
alter table public.bill_calculations enable row level security;

-- The PHP server is the only database client. The secret key maps to service_role,
-- while browser-facing roles receive no direct table access.
revoke all on table public.electricity_profiles from anon, authenticated;
revoke all on table public.bill_calculations from anon, authenticated;
revoke all on sequence public.bill_calculations_id_seq from anon, authenticated;

grant select, insert, update, delete on table public.electricity_profiles to service_role;
grant select, insert, update, delete on table public.bill_calculations to service_role;
grant usage, select on sequence public.bill_calculations_id_seq to service_role;
