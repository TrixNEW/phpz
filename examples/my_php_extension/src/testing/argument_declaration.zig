const std = @import("std");
const builtin = @import("builtin");
const phpz = @import("phpz");

/// Deliberately mismatched specs exercise Debug diagnostics in subprocesses.
pub fn checkArgumentDeclaration(ctx: phpz.Ctx) !bool {
    try ctx.call.expectArgCount(1, 2);
    const mode = try ctx.call.expectArg(1, .{ .string = .{} }, {});
    if (std.mem.eql(u8, mode, "fixed")) {
        _ = try ctx.call.expectArgs(&.{.{ .string = .{} }}, {});
    } else if (std.mem.eql(u8, mode, "required")) {
        _ = try ctx.call.expectArgs(&.{ .{ .string = .{} }, .{ .mixed = .{} } }, {});
    } else if (std.mem.eql(u8, mode, "variadic")) {
        _ = try ctx.call.expectArgs(&.{
            .{ .string = .{} },
            .{ .mixed = .{ .optional = true } },
            .{ .variadic = .{} },
        }, {});
    } else {
        _ = try ctx.call.expectArgs(&.{ .{ .string = .{} }, .{ .mixed = .{ .optional = true } } }, {});
    }
    return builtin.mode == .debug;
}
