-- The acquire of RedisRateLimiter: the refill of each bucket of the call, the
-- max-min division of the global grant (the algorithm of PermitDivision of the
-- core, on whole numbers), and the subtraction of each grant from its lane
-- bucket and of their sum from the global bucket, as one atomic step.
--
-- KEYS: the global key (only when ARGV[1] is 1), then the key of each lane
-- with a limit, in the order of the call.
-- ARGV: 1 = global key present (1 or 0), 2 = total, 3 = global rate,
-- 4 = global burst (0 and 0 with no global key), 5 = N, 6 .. 5+N = the wanted
-- counts in the order of the call, then for each lane key: the position of
-- its lane (1 .. N), its rate and its burst.
--
-- Each bucket is a HASH: tokens (a decimal string) and ts (milliseconds of
-- the Redis clock). An absent key is a full bucket. A time earlier than the
-- stored time gives a refill of 0 and keeps the stored time.
-- Returns the N whole grants, in the order of the call.

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

local function store(key, tokens, at, rate, burst)
    redis.call('HSET', key, 'tokens', string.format('%.17g', tokens), 'ts', string.format('%.0f', at))
    redis.call('PEXPIRE', key, math.ceil(burst * 1000 / rate) + 1000)
end

local hasGlobal = ARGV[1] == '1'
local total = tonumber(ARGV[2])
local n = tonumber(ARGV[5])

local available = {}
for i = 1, n do
    available[i] = tonumber(ARGV[5 + i])
end

local lanes = {}
local firstLaneKey = hasGlobal and 2 or 1
for k = firstLaneKey, #KEYS do
    local base = 5 + n + (k - firstLaneKey) * 3
    local lane = {
        key = KEYS[k],
        position = tonumber(ARGV[base + 1]),
        rate = tonumber(ARGV[base + 2]),
        burst = tonumber(ARGV[base + 3]),
    }
    lane.tokens, lane.at = load(lane.key, lane.rate, lane.burst)
    available[lane.position] = math.min(available[lane.position], math.floor(lane.tokens))
    lanes[#lanes + 1] = lane
end

local grant = total
local global
if hasGlobal then
    global = { key = KEYS[1], rate = tonumber(ARGV[3]), burst = tonumber(ARGV[4]) }
    global.tokens, global.at = load(global.key, global.rate, global.burst)
    grant = math.min(total, math.floor(global.tokens))
end

-- The max-min division of PermitDivision::divide().
local sum = 0
for i = 1, n do
    sum = sum + available[i]
end
local grants = {}
if sum <= grant then
    for i = 1, n do
        grants[i] = available[i]
    end
else
    local sorted = {}
    for i = 1, n do
        sorted[i] = available[i]
    end
    table.sort(sorted)
    local remaining = grant
    local lanesAboveLevel = n
    for _, availability in ipairs(sorted) do
        if availability * lanesAboveLevel > remaining then
            break
        end
        remaining = remaining - availability
        lanesAboveLevel = lanesAboveLevel - 1
    end
    local level = math.floor(remaining / lanesAboveLevel)
    local rest = remaining - level * lanesAboveLevel
    for i = 1, n do
        local share = math.min(available[i], level)
        if rest > 0 and available[i] > level then
            share = share + 1
            rest = rest - 1
        end
        grants[i] = share
    end
end

local granted = 0
for i = 1, n do
    granted = granted + grants[i]
end
for _, lane in ipairs(lanes) do
    store(lane.key, lane.tokens - grants[lane.position], lane.at, lane.rate, lane.burst)
end
if hasGlobal then
    store(global.key, global.tokens - granted, global.at, global.rate, global.burst)
end

return grants
