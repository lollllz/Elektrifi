-- Run once in the Neon SQL Editor using the application's database role.
-- This schema is accessed only through the PHP backend.
BEGIN;
CREATE TABLE public.electricity_profiles (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    client_id uuid NOT NULL UNIQUE,
    currency_code varchar(3) NOT NULL CHECK (currency_code ~ '^[A-Z]{3}$'),
    currency_symbol varchar(8) NOT NULL CHECK (char_length(currency_symbol) BETWEEN 1 AND 8),
    minimum_charge numeric(12,4) NOT NULL CHECK (minimum_charge >= 0),
    sst_rate numeric(7,6) NOT NULL CHECK (sst_rate BETWEEN 0 AND 1),
    sst_threshold_kwh numeric(12,3) NOT NULL CHECK (sst_threshold_kwh >= 0),
    tariff_tiers jsonb NOT NULL CHECK (jsonb_typeof(tariff_tiers) = 'array' AND jsonb_array_length(tariff_tiers) BETWEEN 1 AND 10),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE public.bill_calculations (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    profile_id uuid NOT NULL REFERENCES public.electricity_profiles(id) ON DELETE CASCADE,
    currency_code varchar(3) NOT NULL,
    usage_by_tier jsonb NOT NULL CHECK (jsonb_typeof(usage_by_tier) = 'array'),
    total_kwh numeric(14,3) NOT NULL CHECK (total_kwh >= 0),
    tariff_subtotal numeric(14,4) NOT NULL CHECK (tariff_subtotal >= 0),
    minimum_adjustment numeric(14,4) NOT NULL CHECK (minimum_adjustment >= 0),
    sst_amount numeric(14,4) NOT NULL CHECK (sst_amount >= 0),
    final_total numeric(14,4) NOT NULL CHECK (final_total >= 0),
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX bill_calculations_profile_created_idx ON public.bill_calculations (profile_id, created_at DESC);
REVOKE ALL ON public.electricity_profiles, public.bill_calculations FROM PUBLIC;
REVOKE ALL ON SEQUENCE public.bill_calculations_id_seq FROM PUBLIC;
COMMIT;
