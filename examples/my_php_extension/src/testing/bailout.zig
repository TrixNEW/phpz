const phpz = @import("phpz");

fn exhaustPhpAllocator(scope_defer: *bool, cleanup_defer: *bool, freed_blocks: *usize) !void {
    const allocator = phpz.heap.php_allocator;

    defer scope_defer.* = true;

    var blocks: [64][]u8 = undefined;
    var count: usize = 0;
    defer {
        for (blocks[0..count]) |block| allocator.free(block);
        freed_blocks.* = count;
        cleanup_defer.* = true;
    }

    while (count < blocks.len) : (count += 1) {
        const block = try allocator.alloc(u8, 1024 * 1024);
        block[0] = @intCast(count);
        block[block.len - 1] = @intCast(count);
        blocks[count] = block;
    }
}

/// PHP: MyPHPExt\Test\allocatorAlignment(): bool
pub fn allocatorAlignment() !bool {
    const std = @import("std");
    const allocator = phpz.heap.php_allocator;
    inline for (.{ 1, 8, 16, 32, 64, 4096 }) |alignment| {
        for ([_]usize{ 1, 7, 24, 100, 3000, 5000, 3 * 1024 * 1024 }) |len| {
            const block = try allocator.alignedAlloc(u8, .fromByteUnits(alignment), len);
            try std.testing.expect(std.mem.isAligned(@intFromPtr(block.ptr), alignment));
            @memset(block, 0xa5);
            try std.testing.expect(allocator.resize(block, len / 2 + 1));
            const grown = try allocator.realloc(block[0 .. len / 2 + 1], len * 2);
            try std.testing.expect(std.mem.isAligned(@intFromPtr(grown.ptr), alignment));
            try std.testing.expect(std.mem.allEqual(u8, grown[0 .. len / 2 + 1], 0xa5));
            allocator.free(grown);
        }
    }
    var list: std.ArrayList(u128) = .empty;
    defer list.deinit(allocator);
    for (0..1000) |i| try list.append(allocator, i);
    try std.testing.expect(std.mem.isAligned(@intFromPtr(list.items.ptr), @alignOf(u128)));
    try std.testing.expectEqual(@as(u128, 999), list.items[999]);
    return true;
}

/// PHP: MyPHPExt\Test\allocatorBailout(): array
pub fn allocatorBailout(ctx: phpz.Ctx) !void {
    try ctx.call.expectNoArgs();

    var scope_defer = false;
    var cleanup_defer = false;
    var freed_blocks: usize = 0;
    var out_of_memory = false;

    exhaustPhpAllocator(&scope_defer, &cleanup_defer, &freed_blocks) catch |err| switch (err) {
        error.OutOfMemory => out_of_memory = true,
    };

    var result = phpz.Zval.Array.empty(ctx.retval.ptr());
    result.set(.bool, "out_of_memory", out_of_memory);
    result.set(.bool, "scope_defer", scope_defer);
    result.set(.bool, "cleanup_defer", cleanup_defer);
    result.set(.bool, "freed_blocks", freed_blocks > 0);
}
