-- Phases 3, 4 and 5 — delivery throughput and ingest-to-delivered latency.
--
-- k6 cannot measure this. A delivery happens after the ingest response, so the
-- only honest record of it is PostBox's own ledger. This query reads that
-- ledger; it does not add a benchmarking surface to the application.
--
-- Row Level Security is FORCE, so no role can read across tenants and even the
-- schema owner is subject to the policy. The tenant is therefore established
-- the same way a request establishes it, through the session setting the RLS
-- policies read (RowLevelSecurity::TENANT, postbox.tenant_id).
--
-- Run:
--   psql -v tenant_id=<internal tenant id> -v minutes=10 \
--     -f load/queries/delivery-throughput.sql
--
-- There is no delivered_at column and there should not be: a delivery's
-- success is its status, and the moment it happened is the last attempt that
-- produced it. last_attempted_at is that moment.

select set_config('postbox.tenant_id', :'tenant_id', false);

\echo '-- Delivery outcomes in the window'

select
    status,
    count(*)                                        as deliveries,
    round(avg(attempt_count), 2)                    as avg_attempts
from deliveries
where created_at >= now() - (:'minutes' || ' minutes')::interval
group by status
order by deliveries desc;

\echo '-- Throughput and ingest-to-delivered latency (succeeded only)'

select
    count(*)                                                                    as delivered,
    round(
        count(*)::numeric
        / nullif(extract(epoch from (max(d.last_attempted_at) - min(m.created_at))), 0),
        2
    )                                                                           as deliveries_per_second,
    round((percentile_cont(0.50) within group (
        order by extract(epoch from (d.last_attempted_at - m.created_at)) * 1000
    ))::numeric)                                                                as p50_ms,
    round((percentile_cont(0.95) within group (
        order by extract(epoch from (d.last_attempted_at - m.created_at)) * 1000
    ))::numeric)                                                                as p95_ms,
    round((percentile_cont(0.99) within group (
        order by extract(epoch from (d.last_attempted_at - m.created_at)) * 1000
    ))::numeric)                                                                as p99_ms
from deliveries d
join messages m on m.id = d.message_id
where d.status = 'succeeded'
  and d.created_at >= now() - (:'minutes' || ' minutes')::interval;

\echo '-- Attempt outcomes, including the refusals that never left the process'

select
    outcome,
    count(*)                                        as attempts,
    round(avg(duration_ms))                         as avg_duration_ms
from delivery_attempts
where created_at >= now() - (:'minutes' || ' minutes')::interval
group by outcome
order by attempts desc;
