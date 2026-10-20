const std = @import("std");
const phpz = @import("phpz");
const c = phpz.c;
const Globals = @import("../metrics.zig").Globals;

const thread_count = 2;
const generations = 3;
const rounds = 128;

// The caller participates, so every check runs while all worker resources live.
const Barrier = struct {
    arrived: std.atomic.Value(usize) = .init(0),
    generation: std.atomic.Value(usize) = .init(0),

    fn wait(self: *Barrier) void {
        const generation = self.generation.load(.acquire);
        if (self.arrived.fetchAdd(1, .acq_rel) == thread_count) {
            self.arrived.store(0, .monotonic);
            _ = self.generation.fetchAdd(1, .release);
        } else {
            while (self.generation.load(.acquire) == generation) std.atomic.spinLoopHint();
        }
    }
};

const Result = struct {
    storage: usize = 0,
    globals: usize = 0,
    failed: bool = false,
};

const Shared = struct {
    start: std.atomic.Value(bool) = .init(false),
    abort: std.atomic.Value(bool) = .init(false),
    barrier: Barrier = .{},
    results: [thread_count]Result = @splat(.{}),
};

fn cacheMatches() bool {
    const storage = c.tsrm_get_ls_cache() orelse return false;
    if (comptime @hasDecl(c, "phpz_tsrm_ls_cache")) {
        if (c.phpz_tsrm_ls_cache() != storage) return false;
    }
    const base = @intFromPtr(storage);
    return @intFromPtr(c.phpz_executor_globals()) == base + c.executor_globals_offset and
        @intFromPtr(c.phpz_compiler_globals()) == base + c.compiler_globals_offset and
        @intFromPtr(c.phpz_core_globals()) == base + c.core_globals_offset and
        @intFromPtr(c.phpz_sapi_globals()) == base + c.sapi_globals_offset;
}

fn worker(shared: *Shared, index: usize) void {
    while (!shared.start.load(.acquire)) std.atomic.spinLoopHint();
    if (shared.abort.load(.acquire)) return;

    const result = &shared.results[index];
    for (0..generations) |generation| {
        phpz.tsrm.initThread();
        const valid = cacheMatches();
        if (!valid) result.failed = true;
        result.storage = @intFromPtr(c.tsrm_get_ls_cache());
        result.globals = if (valid) @intFromPtr(Globals.get()) else 0;
        // Volatile accesses ensure the interleaved reads actually check storage.
        const marker: ?*volatile u64 = if (valid) &Globals.get().function_calls.total else null;
        if (marker) |value| if (value.* != 0) {
            result.failed = true;
        };
        shared.barrier.wait();
        shared.barrier.wait();

        for (0..rounds) |round| {
            const expected: u64 = @intCast(1 + index + thread_count * (round + rounds * generation));
            if (marker) |value| value.* = expected;
            shared.barrier.wait();
            if (!cacheMatches()) result.failed = true;
            if (marker) |value| if (value.* != expected) {
                result.failed = true;
            };
            shared.barrier.wait();
        }

        phpz.tsrm.freeThread();
        if (c.tsrm_get_ls_cache() != null) result.failed = true;
        if (comptime @hasDecl(c, "phpz_tsrm_ls_cache")) {
            if (c.phpz_tsrm_ls_cache() != null) result.failed = true;
        }
        shared.barrier.wait();
    }
}

/// Test TSRM without running requests or sharing PHP values between native threads.
pub fn checkNativeTsrmThreads() !bool {
    if (comptime !phpz.zts) return error.RequiresZts;
    if (!cacheMatches()) return error.TsrmCacheMismatch;
    const caller_storage = @intFromPtr(c.tsrm_get_ls_cache());
    const caller_globals = @intFromPtr(Globals.get());
    const caller_marker: *volatile u64 = &Globals.get().function_calls.total;
    const original = caller_marker.*;
    var shared: Shared = .{};
    var threads: [thread_count]std.Thread = undefined;
    for (&threads, 0..) |*thread, index| {
        thread.* = std.Thread.spawn(.{}, worker, .{ &shared, index }) catch |err| {
            // Workers must leave the start gate if a later spawn fails.
            shared.abort.store(true, .release);
            shared.start.store(true, .release);
            for (threads[0..index]) |started| started.join();
            return err;
        };
    }
    shared.start.store(true, .release);
    var failed = false;
    for (0..generations) |_| {
        shared.barrier.wait();
        for (shared.results, 0..) |result, index| {
            if (result.storage == 0 or result.globals == 0 or
                result.storage == caller_storage or result.globals == caller_globals) failed = true;
            for (shared.results[0..index]) |previous| {
                if (result.storage == previous.storage or result.globals == previous.globals) failed = true;
            }
        }
        shared.barrier.wait();
        for (0..rounds) |_| {
            shared.barrier.wait();
            if (!cacheMatches() or caller_marker.* != original) failed = true;
            shared.barrier.wait();
        }
        shared.barrier.wait();
    }
    for (threads) |thread| thread.join();
    for (shared.results) |result| {
        if (result.failed) failed = true;
    }
    if (failed) return error.TsrmThreadIsolationFailed;
    return true;
}
