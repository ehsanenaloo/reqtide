-- Test-only observation of a retry budget, placed after the production libraries and sent with EVAL.
-- KEYS: 1 registry metadata hash, 2 budget partition hash, 3 pending-reservation sorted set.
-- ARGV: 1 registry identity, 2 schema version.
-- Reply: {} when the partition does not exist, else { balance, pending cost } in scaled units as of the protected
-- effective time (accrue, then expire, on local values only). It writes nothing.

local state = rt_rb.load(KEYS[2])
local meta, es, eu = registry.begin(KEYS[1], ARGV[1], rt_state.int_arg(ARGV[2], 1), state and state.ls_s or 0, state and state.ls_u or 0)
if state == nil then
    return {}
end
local balance, pending = rt_rb.view(state, KEYS[3], es, eu)
return { exact_int.format_int(balance), exact_int.format_int(pending) }
