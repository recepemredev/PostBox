-- Atomic stream slot admission with a TTL backstop.
--
-- KEYS[1] the slots key, one per tenant ("stream:slots:{tenant public_id}")
-- ARGV[1] now_ms          -- the caller's clock, not TIME: a test pins this
-- ARGV[2] ttl_ms          -- max_lifetime_seconds + grace, in milliseconds
-- ARGV[3] max_concurrent  -- the per-tenant cap
-- ARGV[4] connection_id   -- a ULID minted per connection
--
-- Returns {allowed, active}:
--   allowed  1 if a slot was admitted, 0 if the cap is already reached
--   active   slots active after this call (including the new one, if admitted)
--
-- The member's score is its own expiry timestamp (now_ms + ttl_ms).
-- ZREMRANGEBYSCORE clears everything already past its expiry before the cap
-- is read, so a crashed FPM child that never called release() costs one
-- slot for one lifetime rather than forever — the same "the worker that
-- claimed this never ran" reasoning the outbox lease already makes.
local key = KEYS[1]
local now_ms = tonumber(ARGV[1])
local ttl_ms = tonumber(ARGV[2])
local max_concurrent = tonumber(ARGV[3])
local connection_id = ARGV[4]

redis.call('ZREMRANGEBYSCORE', key, '-inf', now_ms)

local active = redis.call('ZCARD', key)
if active >= max_concurrent then
    return {0, active}
end

redis.call('ZADD', key, now_ms + ttl_ms, connection_id)
redis.call('PEXPIRE', key, ttl_ms + 1000)

return {1, active + 1}
