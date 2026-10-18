const std = @import("std");
const builtin = @import("builtin");

const c = @import("c.zig").c;
const phpz_options = @import("phpz_options");

const zts = c.USING_ZTS != 0;

/// Fallback cache of the calling thread's TSRM storage, like C's ZEND_TSRMLS_CACHE.
/// phpz refreshes it at module, globals and request startup and in initThread(),
/// and fills it on first use otherwise.
threadlocal var ls_cache: ?*anyopaque = null;

pub fn startup() error{TsrmStartupFailed}!void {
    if (comptime zts) {
        if (!c.php_tsrm_startup()) return error.TsrmStartupFailed;
        detectTcbOffset();
    }
}

pub fn shutdown() void {
    if (comptime zts) {
        c.tsrm_shutdown();
        ls_cache = null;
    }
}

pub fn initThread() void {
    if (comptime zts) {
        _ = c.ts_resource_ex(0, null);
        _ = refreshCache();
    }
}

pub fn freeThread() void {
    if (comptime zts) {
        c.ts_free_thread();
        ls_cache = null;
    }
}

pub fn envLock() void {
    if (comptime zts) c.tsrm_env_lock();
}

pub fn envUnlock() void {
    if (comptime zts) c.tsrm_env_unlock();
}

/// On Linux, PHP exports the thread-pointer offset of its own TSRM cache for the
/// JIT. PHP keeps that cache current for every thread running PHP code, so
/// reading it is one load and never stale. Zero means unavailable.
const tcb_supported = builtin.os.tag == .linux and (builtin.cpu.arch == .x86_64 or builtin.cpu.arch == .aarch64);
var tcb_offset: usize = 0;

/// Look up PHP's TLS layout. Called at module startup, before other threads run PHP.
pub fn detectTcbOffset() void {
    if (comptime zts and tcb_supported) tcb_offset = c.tsrm_get_ls_cache_tcb_offset();
}

inline fn readTcb(offset: usize) ?*anyopaque {
    // Same thread pointer reads as std.os.linux.tls, which is off limits with libc.
    const thread_pointer = switch (comptime builtin.cpu.arch) {
        .x86_64 => asm ("movq %%fs:0, %[tp]"
            : [tp] "=r" (-> usize),
        ),
        .aarch64 => asm ("mrs %[tp], tpidr_el0"
            : [tp] "=r" (-> usize),
        ),
        else => unreachable,
    };
    return @as(*const ?*anyopaque, @ptrFromInt(thread_pointer +% offset)).*;
}

/// The calling thread's TSRM storage. ZTS only.
pub inline fn localStorage() *anyopaque {
    if (comptime !zts) unreachable else {
        if (comptime tcb_supported) {
            if (tcb_offset != 0) if (readTcb(tcb_offset)) |ls| return ls;
        }
        // Built-in extensions share PHP's own cache, which PHP keeps current.
        if (comptime !phpz_options.shared) return c.phpz_tsrm_ls_cache().?;
        return ls_cache orelse refreshCache();
    }
}

/// Reload the cached TSRM pointer for the calling thread. ZTS only.
pub noinline fn refreshCache() *anyopaque {
    if (comptime !zts) unreachable else {
        const ls: *anyopaque = c.tsrm_get_ls_cache().?;
        ls_cache = ls;
        return ls;
    }
}

/// Storage at a fast TSRM offset, like TSRMG_FAST_BULK. ZTS only.
pub inline fn fastBulk(comptime T: type, offset: usize) *T {
    const base: [*]u8 = @ptrCast(localStorage());
    return @ptrCast(@alignCast(base + offset));
}

/// Storage of a TSRM resource id, like TSRMG_BULK. ZTS only.
pub inline fn bulk(comptime T: type, id: c_int) *T {
    const table: *const [*]?*anyopaque = @ptrCast(@alignCast(localStorage()));
    return @ptrCast(@alignCast(table.*[@intCast(id - 1)].?));
}

test {
    std.testing.refAllDecls(@This());
}
