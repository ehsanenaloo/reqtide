-- Test-only driver for resources/lua/lib/exact_int.lua. The test places the library text in front of this file and runs
-- both with EVAL: ARGV[1] is the operation, the remaining ARGV are decimal strings. The reply is a flat list of strings.
-- Not shipped (tests/ is export-ignore); it still avoids number-to-text conversion outside exact_int.format_int.

local op = ARGV[1]
local out = {}
local function push(value)
    out[#out + 1] = value
end

local DIGITS = '0123456789'
local TEN = exact_int.from_num(10)

local function dec_to_limbs(text)
    local limbs = {}
    for i = 1, string.len(text) do
        local digit = string.find(DIGITS, string.sub(text, i, i), 1, true) - 1
        limbs = exact_int.add(exact_int.mul(limbs, TEN), exact_int.from_num(digit))
    end
    return limbs
end

local function limbs_to_dec(limbs)
    if #limbs == 0 then
        return '0'
    end
    local reversed = {}
    local value = limbs
    while #value > 0 do
        local quotient, remainder = exact_int.divmod(value, TEN)
        local digit = exact_int.to_num(remainder)
        reversed[#reversed + 1] = string.sub(DIGITS, digit + 1, digit + 1)
        value = quotient
    end
    local digits = {}
    for i = #reversed, 1, -1 do
        digits[#digits + 1] = reversed[i]
    end
    return table.concat(digits)
end

local function ordering(value)
    if value < 0 then
        return 'lt'
    elseif value > 0 then
        return 'gt'
    end
    return 'eq'
end

if op == 'parse' then
    for i = 2, #ARGV do
        local ok, value = pcall(exact_int.parse_int, ARGV[i])
        if ok then
            push(exact_int.format_int(value))
        else
            push('err')
        end
    end
elseif op == 'format' then
    for i = 2, #ARGV do
        local ok, text = pcall(exact_int.format_int, tonumber(ARGV[i]))
        if ok then
            push(text)
        else
            push('err')
        end
    end
elseif op == 'mad' then
    for i = 2, #ARGV, 5 do
        local q, r, capped = exact_int.mul_add_div_floor_capped(
            exact_int.parse_int(ARGV[i]), exact_int.parse_int(ARGV[i + 1]), exact_int.parse_int(ARGV[i + 2]),
            exact_int.parse_int(ARGV[i + 3]), exact_int.parse_int(ARGV[i + 4])
        )
        push(exact_int.format_int(q))
        push(exact_int.format_int(r))
        push(capped and '1' or '0')
    end
elseif op == 'cmp' then
    for i = 2, #ARGV, 4 do
        push(ordering(exact_int.compare_products(
            exact_int.parse_int(ARGV[i]), exact_int.parse_int(ARGV[i + 1]),
            exact_int.parse_int(ARGV[i + 2]), exact_int.parse_int(ARGV[i + 3])
        )))
    end
elseif op == 'msc' then
    for i = 2, #ARGV, 5 do
        local q, capped = exact_int.mul_sub_ceil_div_capped(
            exact_int.parse_int(ARGV[i]), exact_int.parse_int(ARGV[i + 1]), exact_int.parse_int(ARGV[i + 2]),
            exact_int.parse_int(ARGV[i + 3]), exact_int.parse_int(ARGV[i + 4])
        )
        push(exact_int.format_int(q))
        push(capped and '1' or '0')
    end
elseif op == 'cws' then
    for i = 2, #ARGV, 6 do
        push(ordering(exact_int.compare_weighted_sum(
            exact_int.parse_int(ARGV[i]), exact_int.parse_int(ARGV[i + 1]), exact_int.parse_int(ARGV[i + 2]),
            exact_int.parse_int(ARGV[i + 3]), exact_int.parse_int(ARGV[i + 4]), exact_int.parse_int(ARGV[i + 5])
        )))
    end
elseif op == 'mul' or op == 'add' or op == 'sub' then
    for i = 2, #ARGV, 2 do
        local a, b = dec_to_limbs(ARGV[i]), dec_to_limbs(ARGV[i + 1])
        push(limbs_to_dec(exact_int[op](a, b)))
    end
elseif op == 'divmod' then
    for i = 2, #ARGV, 2 do
        local quotient, remainder = exact_int.divmod(dec_to_limbs(ARGV[i]), dec_to_limbs(ARGV[i + 1]))
        push(limbs_to_dec(quotient))
        push(limbs_to_dec(remainder))
    end
elseif op == 'rejects' then
    -- Each following argument names a call that must raise a domain error.
    local probes = {
        negative = function() return exact_int.from_num(-1) end,
        fraction = function() return exact_int.from_num(1.5) end,
        above_max = function() return exact_int.from_num(9007199254740992) end,
        zero_divisor = function() return exact_int.mul_add_div_floor_capped(1, 1, 0, 0, 5) end,
        empty_divisor = function() return exact_int.divmod({ 1 }, {}) end,
        sub_below_zero = function() return exact_int.sub({ 1 }, { 2 }) end,
        mad_above_max = function() return exact_int.mul_add_div_floor_capped(9007199254740992, 1, 0, 1, 5) end,
        cmp_fraction = function() return exact_int.compare_products(1.5, 1, 1, 1) end,
        msc_zero_divisor = function() return exact_int.mul_sub_ceil_div_capped(1, 1, 0, 0, 5) end,
        msc_above_max = function() return exact_int.mul_sub_ceil_div_capped(9007199254740992, 1, 0, 1, 5) end,
        cws_fraction = function() return exact_int.compare_weighted_sum(1.5, 1, 1, 1, 1, 1) end,
    }
    for i = 2, #ARGV do
        local ok = pcall(probes[ARGV[i]])
        push(ok and 'accepted' or 'rejected')
    end
else
    error('unknown operation')
end

return out
