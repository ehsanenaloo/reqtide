-- Bounded maintenance of a registry: removes the expired terminal records at the head of
-- the registry tombstone queue (at most 128) and the idle partitions that are equivalent to fresh state (at most 128
-- candidates of the registry index). It never touches a pending reservation, a lease, a cooldown or any protective balance.
-- KEYS: 1 registry metadata hash, 2 registry tombstone queue, 3 registry rate and budget index (sorted set).
-- ARGV: 1 registry identity, 2 schema version.
-- Reply: { terminal records consumed, partitions removed, more } as decimal text; more is 1 when a batch was full, so
-- another step may find work.

local meta_key, qkey, xkey = KEYS[1], KEYS[2], KEYS[3]

registry.require_access(false)
registry.check_identity(ARGV[1])
local schema = rt_state.int_arg(ARGV[2], 1)
local meta, es, eu = registry.begin(meta_key, ARGV[1], schema, 0, 0)

local consumed = rt_cc.drain_tombstones(meta, { key = '', na = 0, nt = 0 }, qkey, es, eu)
local removed, examined = rt_rate.reclaim(meta, xkey, qkey, es, eu)
rt_state.save_counters(meta_key, meta)
registry.commit_watermark(meta_key, meta, es, eu)
local more = (consumed >= rt_cc.DRAIN_BATCH or examined >= rt_rate.RECLAIM_BATCH) and '1' or '0'
return { exact_int.format_int(consumed), exact_int.format_int(removed), more }
