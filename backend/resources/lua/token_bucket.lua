-- Atomic token bucket check-and-consume.
--
-- KEYS[1] the bucket's key, one per tenant
-- ARGV[1] capacity            -- the burst a full bucket holds
-- ARGV[2] refill_per_second   -- whole tokens added back per second
-- ARGV[3] now_ms              -- the caller's clock, not TIME: a test pins this
-- ARGV[4] ttl_seconds         -- how long an idle bucket is kept before it expires
--
-- Returns {allowed, remaining, reset_ms, retry_ms}:
--   allowed    1 if a token was spent, 0 if the bucket was empty
--   remaining  tokens left after this call
--   reset_ms   milliseconds until the bucket is full again
--   retry_ms   milliseconds until the next token; only meaningful when denied
--
-- Tokens are kept as whole integers, never fractions. The accrual below only
-- advances the stored timestamp by the span that actually bought whole
-- tokens, so a fractional remainder is carried forward instead of lost — a
-- bucket refilled by many small requests ends up exactly where one big gap
-- would have left it, not short by a rounding error each time.
local key = KEYS[1]
local capacity = tonumber(ARGV[1])
local refill_per_second = tonumber(ARGV[2])
local now_ms = tonumber(ARGV[3])
local ttl_seconds = tonumber(ARGV[4])

local state = redis.call('HMGET', key, 'tokens', 'updated_ms')
local tokens = tonumber(state[1])
local updated_ms = tonumber(state[2])

if tokens == nil then
    tokens = capacity
    updated_ms = now_ms
end

local elapsed_ms = now_ms - updated_ms
if elapsed_ms > 0 then
    local accrued = math.floor(elapsed_ms * refill_per_second / 1000)
    if accrued > 0 then
        tokens = math.min(capacity, tokens + accrued)
        updated_ms = updated_ms + math.floor(accrued * 1000 / refill_per_second)
    end
end

local allowed = 0
local retry_ms = 0
if tokens >= 1 then
    tokens = tokens - 1
    allowed = 1
else
    retry_ms = math.ceil(1000 / refill_per_second)
end

redis.call('HSET', key, 'tokens', tokens, 'updated_ms', updated_ms)
redis.call('EXPIRE', key, ttl_seconds)

local reset_ms = 0
if tokens < capacity then
    reset_ms = math.ceil((capacity - tokens) * 1000 / refill_per_second)
end

return {allowed, tokens, reset_ms, retry_ms}
