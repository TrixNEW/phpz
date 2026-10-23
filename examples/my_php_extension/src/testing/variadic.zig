const std = @import("std");
const phpz = @import("phpz");

/// A context-only closure checks the collected tail before parsing elements.
pub fn parse(ctx: phpz.Ctx) !void {
    const args = try ctx.call.expectArgs(&.{
        .{ .string = .{} },
        .{ .string = .{} },
        .{ .variadic = .{} },
    }, {});
    const mode = std.meta.stringToEnum(phpz.Variadic.Mode, args[0]) orelse return error.UnknownVariadicMode;
    try args[2].check(mode);
    const result = phpz.Zval.Array.empty(ctx.retval.ptr());
    var it = args[2].iterator();
    while (it.next()) |entry| {
        var value = phpz.Zval.raw.undef;
        errdefer phpz.Zval.raw.release(&value);
        try parseEntry(.{ .call = ctx.call, .retval = .from(&value) }, entry, args[1]);
        if (entry.name()) |name| result.set(.mixed, name, &value) else try result.append(.mixed, &value);
        if (std.mem.eql(u8, args[1], "first-int")) break;
    }
}

fn parseEntry(ctx: phpz.Ctx, entry: phpz.Variadic.Entry, kind: []const u8) !void {
    if (std.mem.eql(u8, kind, "int") or std.mem.eql(u8, kind, "first-int")) {
        ctx.ret(.int, try entry.expect(.{ .int = .{} }, {}));
    } else if (std.mem.eql(u8, kind, "u8")) {
        ctx.ret(.int, try entry.expect(.{ .int = .{ .as = .u8 } }, {}));
    } else if (std.mem.eql(u8, kind, "float")) {
        ctx.ret(.float, try entry.expect(.{ .float = .{} }, {}));
    } else if (std.mem.eql(u8, kind, "bool")) {
        ctx.ret(.bool, try entry.expect(.{ .bool = .{} }, {}));
    } else if (std.mem.eql(u8, kind, "string")) {
        ctx.ret(.string, try entry.expect(.{ .string = .{ .as = .cstr } }, {}));
    } else if (std.mem.eql(u8, kind, "nullable-int")) {
        switch (try entry.expect(.{ .int = .{ .nullable = true } }, {})) {
            .null => ctx.ret(.null, {}),
            .value => |value| ctx.ret(.int, value),
        }
    } else if (std.mem.eql(u8, kind, "union")) {
        switch (try entry.expect(.{ .mixed = .{ .one_of = &.{ .int, .string } } }, {})) {
            .int => |value| ctx.ret(.int, value),
            .string => |value| ctx.ret(.string, value),
        }
    } else if (std.mem.eql(u8, kind, "callable")) {
        var callable: phpz.zend.Callable = .nil;
        _ = try entry.expect(.{ .callable = .{ .resolve = true } }, .{ .out = &callable });
        try callable.call(ctx.retval.ptr(), .{}, null);
    } else if (std.mem.eql(u8, kind, "object")) {
        const class = (try phpz.ClassEntry.lookup("stdClass", true)).?;
        _ = try entry.expect(.{ .object = .{ .instanceof = true } }, .{ .class = class });
        phpz.Zval.raw.copy(ctx.retval.ptr(), entry.value.ptr());
    } else if (std.mem.eql(u8, kind, "stream")) {
        _ = try entry.expect(.{ .resource = .{ .as = .stream } }, {});
        phpz.Zval.raw.copy(ctx.retval.ptr(), entry.value.ptr());
    } else if (std.mem.eql(u8, kind, "mixed")) {
        const value = try entry.expect(.{ .mixed = .{} }, {});
        try std.testing.expect(value == entry.value);
        phpz.Zval.raw.copy(ctx.retval.ptr(), value.ptr());
    } else return error.UnknownVariadicKind;
}

/// A typed closure exercises the runtime policy API without a leading context.
pub fn modeCount(mode: []const u8, rest: phpz.Variadic) !usize {
    const choice = std.meta.stringToEnum(phpz.Variadic.Mode, mode) orelse return error.UnknownVariadicMode;
    try rest.check(choice);
    return rest.len();
}
