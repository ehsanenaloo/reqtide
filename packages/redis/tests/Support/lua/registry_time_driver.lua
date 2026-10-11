-- Test-only driver for lib/time.lua and lib/registry.lua. The test places exact_int.lua, time.lua and registry.lua in
-- front of this file and sends all with EVAL. ARGV[1] is the operation; the reply is a flat list of strings. A raised
-- registered error is reported as "err:<text>" so that the test can compare its code. Not shipped.

local op = ARGV[1]
local out = {}
local function push(value)
    out[#out + 1] = value
end
local parse = exact_int.parse_int
local fmt = exact_int.format_int

local function failure(thrown)
    if type(thrown) == 'table' then
        return 'err:' .. thrown.err
    end
    return 'err:' .. thrown
end

if op == 'effective' then
    for i = 2, #ARGV, 6 do
        local ok, es, eu, regressed = pcall(function()
            return rt_time.effective(parse(ARGV[i]), parse(ARGV[i + 1]), parse(ARGV[i + 2]), parse(ARGV[i + 3]), parse(ARGV[i + 4]), parse(ARGV[i + 5]))
        end)
        if ok then
            push(fmt(es))
            push(fmt(eu))
            push(regressed and '1' or '0')
        else
            push(failure(es))
            push('')
            push('')
        end
    end
elseif op == 'add' then
    for i = 2, #ARGV, 3 do
        local ok, s, u = pcall(function()
            return rt_time.add_micros(parse(ARGV[i]), parse(ARGV[i + 1]), parse(ARGV[i + 2]))
        end)
        if ok then
            push(fmt(s))
            push(fmt(u))
        else
            push(failure(s))
            push('')
        end
    end
elseif op == 'diff' then
    for i = 2, #ARGV, 4 do
        local ok, value = pcall(function()
            return rt_time.diff_micros(parse(ARGV[i]), parse(ARGV[i + 1]), parse(ARGV[i + 2]), parse(ARGV[i + 3]))
        end)
        push(ok and fmt(value) or failure(value))
    end
elseif op == 'begin' then
    -- KEYS[1] metadata; ARGV: identity, schema, state seconds, state micros, commit flag ("1" advances the watermark).
    local ok, thrown = pcall(function()
        local meta, es, eu, regressed = registry.begin(KEYS[1], ARGV[2], parse(ARGV[3]), parse(ARGV[4]), parse(ARGV[5]))
        local before_s, before_u = meta.wm_s, meta.wm_u
        if ARGV[6] == '1' then
            registry.commit_watermark(KEYS[1], meta, es, eu)
        end
        local after = registry.load(KEYS[1], ARGV[2], parse(ARGV[3]))
        push(fmt(es))
        push(fmt(eu))
        push(regressed and '1' or '0')
        push(fmt(before_s))
        push(fmt(before_u))
        push(fmt(after.wm_s))
        push(fmt(after.wm_u))
    end)
    if not ok then
        out = { failure(thrown) }
    end
elseif op == 'commit_raw' then
    -- KEYS[1] metadata; ARGV: identity, schema, seconds, micros. Tries to store an arbitrary effective time.
    local ok, thrown = pcall(function()
        local meta = registry.load(KEYS[1], ARGV[2], parse(ARGV[3]))
        registry.commit_watermark(KEYS[1], meta, parse(ARGV[4]), parse(ARGV[5]))
        local after = registry.load(KEYS[1], ARGV[2], parse(ARGV[3]))
        push(fmt(after.wm_s))
        push(fmt(after.wm_u))
    end)
    if not ok then
        out = { failure(thrown) }
    end
else
    error('unknown operation')
end

return out
