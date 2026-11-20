-- The give-back of RedisRateLimiter: the refill of each bucket of the call,
-- then each unused count into its lane bucket, and their sum into the global
-- bucket, each up to its burst capacity, as one atomic step.
--
-- KEYS: the global key (only when ARGV[1] is 1), then the key of each lane
-- with a limit, in the order of the call.
-- ARGV: 1 = global key present (1 or 0), 2 = global rate, 3 = global burst
-- (0 and 0 with no global key), 4 = N, 5 .. 4+N = the unused counts in the
-- order of the call, then for each lane key: the position of its lane
-- (1 .. N), its rate and its burst.
--
-- The buckets are the HASH keys of acquire.lua, with the same refill.
-- Returns the number of keys that it wrote.

local t = redis.call('TIME')
local now = tonumber(t[1]) * 1000 + math.floor(tonumber(t[2]) / 1000)

local function load(key, rate, burst)
    local stored = redis.call('HMGET', key, 'tokens', 'ts')
    local tokens = tonumber(stored[1])
    local ts = tonumber(stored[2])
    if tokens == nil or ts == nil then
        return burst, now
    end
    local at = math.max(now, ts)
    return math.min(burst, tokens + (at - ts) * rate / 1000), at
end

local function giveBack(key, returned, rate, burst)
    local tokens, at = load(key, rate, burst)
    redis.call('HSET', key, 'tokens', string.format('%.17g', math.min(burst, tokens + returned)), 'ts', string.format('%.0f', at))
    redis.call('PEXPIRE', key, math.ceil(burst * 1000 / rate) + 1000)
end

local hasGlobal = ARGV[1] == '1'
local n = tonumber(ARGV[4])

local unused = {}
local sum = 0
for i = 1, n do
    unused[i] = tonumber(ARGV[4 + i])
    sum = sum + unused[i]
end

local firstLaneKey = hasGlobal and 2 or 1
for k = firstLaneKey, #KEYS do
    local base = 4 + n + (k - firstLaneKey) * 3
    giveBack(KEYS[k], unused[tonumber(ARGV[base + 1])], tonumber(ARGV[base + 2]), tonumber(ARGV[base + 3]))
end
if hasGlobal then
    giveBack(KEYS[1], sum, tonumber(ARGV[2]), tonumber(ARGV[3]))
end

return #KEYS
