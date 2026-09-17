-- Phases 3, 4 and 5 — delivery throughput, ingest-to-delivered latency, and
-- (Phase 5 only) the breaker activation history behind a degraded endpoint.
--
-- k6 cannot measure any of this. A delivery happens after the ingest
-- response, so the only honest record of it is PostBox's own ledger. This
-- query reads that ledger; it does not add a benchmarking surface to the
-- application.
--
-- Row Level Security is FORCE, so no role can read across tenants and even
-- the schema owner is subject to the policy. The tenant is therefore
-- established the same way a request establishes it, through the session
-- setting the RLS policies read (RowLevelSecurity::TENANT,
-- postbox.tenant_id) — which only has an effect for a role that is not a
-- superuser. Run this as the application role (postbox_app) or the schema
-- owner (postbox), never as the postgres superuser: FORCE does not stop a
-- superuser bypassing every policy, so set_config below would be silently
-- inert and the query would span every tenant in the database.
--
-- Run:
--   psql -U <postbox or postbox_app> -v tenant_id=<internal tenant id> -v minutes=10 \
--     -f load/queries/delivery-throughput.sql
--
-- There is no delivered_at column and there should not be: a delivery's
-- success is its status, and the moment it happened is the last attempt that
-- produced it. last_attempted_at is that moment.
--
-- Phase 4's backlog drain reads the same tables a different way: deliveries
-- per second computed from ingest to delivered (deliveries_per_second below)
-- includes the accumulation window a drain deliberately front-loads, so it is
-- labelled rather than reused as the drain's own throughput number.
-- drain_per_second, built from attempt timestamps alone, is that number.
--
-- Phase 5's degraded-receiver question — does a slow endpoint starve a
-- healthy one — needs one more dimension than Phase 3 or 4 do: which
-- endpoint. Both the outcome and the throughput blocks below carry it, which
-- costs nothing when a tenant has only one endpoint and answers the question
-- outright when it has two.

select set_config('postbox.tenant_id', :'tenant_id', false);

\echo '-- Delivery outcomes in the window, by endpoint'

select
    e.public_id                                     as endpoint,
    d.status,
    count(*)                                        as deliveries,
    round(avg(d.attempt_count), 2)                  as avg_attempts
from deliveries d
join endpoints e on e.id = d.endpoint_id
where d.created_at >= now() - (:'minutes' || ' minutes')::interval
group by e.public_id, d.status
order by e.public_id, deliveries desc;

\echo '-- Throughput and ingest-to-delivered latency (succeeded only), by endpoint'
\echo '-- deliveries_per_second and the percentiles measure from ingest (m.created_at);'
\echo '-- for a backlog drain this includes the accumulation window on purpose —'
\echo '-- see drain_per_second below for the number that does not.'

select
    e.public_id                                                                    as endpoint,
    count(*)                                                                       as delivered,
    round(
        count(*)::numeric
        / nullif(extract(epoch from (max(d.last_attempted_at) - min(m.created_at))), 0),
        2
    )                                                                              as deliveries_per_second,
    round((percentile_cont(0.50) within group (
        order by extract(epoch from (d.last_attempted_at - m.created_at)) * 1000
    ))::numeric)                                                                   as p50_ms,
    round((percentile_cont(0.95) within group (
        order by extract(epoch from (d.last_attempted_at - m.created_at)) * 1000
    ))::numeric)                                                                   as p95_ms,
    round((percentile_cont(0.99) within group (
        order by extract(epoch from (d.last_attempted_at - m.created_at)) * 1000
    ))::numeric)                                                                   as p99_ms
from deliveries d
join messages m on m.id = d.message_id
join endpoints e on e.id = d.endpoint_id
where d.status = 'succeeded'
  and d.created_at >= now() - (:'minutes' || ' minutes')::interval
group by e.public_id;

\echo '-- Drain throughput (Phase 4): attempt timestamps only, no ingest time —'
\echo '-- the number a backlog drain actually measures.'

select
    e.public_id                                                                    as endpoint,
    count(*)                                                                       as delivered,
    round(
        count(*)::numeric
        / nullif(extract(epoch from (max(d.last_attempted_at) - min(d.last_attempted_at))), 0),
        2
    )                                                                              as drain_per_second
from deliveries d
join endpoints e on e.id = d.endpoint_id
where d.status = 'succeeded'
  and d.created_at >= now() - (:'minutes' || ' minutes')::interval
group by e.public_id;

\echo '-- Attempt outcomes, including the refusals that never left the process, by endpoint'

select
    e.public_id                                     as endpoint,
    a.outcome,
    count(*)                                        as attempts,
    round(avg(a.duration_ms))                       as avg_duration_ms
from delivery_attempts a
join endpoints e on e.id = a.endpoint_id
where a.created_at >= now() - (:'minutes' || ' minutes')::interval
group by e.public_id, a.outcome
order by e.public_id, attempts desc;

\echo '-- Breaker activations in the window (Phase 5): the full history, not just the current state'

select
    entity_public_id                                as endpoint,
    action,
    count(*)                                        as activations
from audit_log
where entity_type = 'endpoint'
  and action like 'breaker.%'
  and created_at >= now() - (:'minutes' || ' minutes')::interval
group by entity_public_id, action
order by entity_public_id, action;
