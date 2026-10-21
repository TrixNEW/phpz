const std = @import("std");
const phpz = @import("phpz");

const Value = struct {
    first: i64 = 0,
    second: i64 = 0,
    trace_cleanup: bool = false,

    pub fn __construct(ctx: phpz.Ctx, first: i64, second: ?i64, fail: ?bool) !Value {
        if (fail orelse false) {
            // Trace the already initialized backing when construction fails.
            const instance = try ValueClass.fromObject(ctx.call.this().?);
            instance.backing().?.trace_cleanup = true;
            return phpz.exception.throwError(null, "constructor failed", .{});
        }
        return .{ .first = first, .second = second orelse 7 };
    }

    pub fn values(self: *const Value, ctx: phpz.Ctx) !void {
        try ctx.call.expectNoArgs();
        const result = phpz.Zval.Array.empty(ctx.retval.ptr());
        try result.append(.int, self.first);
        try result.append(.int, self.second);
    }

    pub fn __destruct(self: *const Value) void {
        if (self.trace_cleanup) std.debug.print("PHP destructor\n", .{});
    }

    fn deinit(self: *Value) void {
        if (self.trace_cleanup) std.debug.print("backing cleanup\n", .{});
    }
};

const ValueClass = phpz.Class("MyPHPExt\\Test\\NewValue", Value, .{ .deinit = Value.deinit });
const BareClass = phpz.Class("MyPHPExt\\Test\\NewBare", struct { value: i64 = 0 }, .{});

const FallibleStage = enum { none, init, clone };
threadlocal var fail_stage: FallibleStage = .none;

const Fallible = struct {
    amount: i64,

    fn init() !Fallible {
        defer fail_stage = .none;
        if (fail_stage == .init) return error.InitRefused;
        return .{ .amount = 7 };
    }

    fn clone(self: *const Fallible) !Fallible {
        defer fail_stage = .none;
        if (fail_stage == .clone) return error.CloneRefused;
        return self.*;
    }

    pub fn value(self: *const Fallible) i64 {
        return self.amount;
    }
};

const FallibleClass = phpz.Class("MyPHPExt\\Test\\FallibleValue", Fallible, .{ .init = Fallible.init, .clone = Fallible.clone });
pub const classes = .{ ValueClass, BareClass, FallibleClass };

/// Make the next FallibleValue initialization or clone fail.
pub fn failNextFallible(stage: []const u8) !void {
    fail_stage = std.meta.stringToEnum(FallibleStage, stage) orelse return phpz.exception.argumentValueError(1, "must be none, init or clone", .{});
}

/// Exercise Class.new()/tryNew() with borrowed positional and named arguments.
pub fn newObject(ctx: phpz.Ctx, bare: bool, positional: *phpz.zend.Array, named: phpz.Nullable(*phpz.zend.Array), guarded: bool) !void {
    if (bare) {
        try create(BareClass, ctx, positional, named.asOptional(), guarded);
    } else {
        try create(ValueClass, ctx, positional, named.asOptional(), guarded);
    }
}

fn create(comptime Class: type, ctx: phpz.Ctx, positional: *phpz.zend.Array, named: ?*phpz.zend.Array, guarded: bool) !void {
    switch (positional.len()) {
        0 => try construct(Class, ctx, .{}, named, guarded),
        1 => try construct(Class, ctx, .{(positional.findIndex(0) orelse return error.InvalidPositionalArguments).*}, named, guarded),
        2 => try construct(Class, ctx, .{
            (positional.findIndex(0) orelse return error.InvalidPositionalArguments).*,
            (positional.findIndex(1) orelse return error.InvalidPositionalArguments).*,
        }, named, guarded),
        else => return error.TooManyTestArguments,
    }
}

fn construct(comptime Class: type, ctx: phpz.Ctx, params: anytype, named: ?*phpz.zend.Array, guarded: bool) !void {
    const instance = if (guarded) try Class.tryNew(params, named) else try Class.new(params, named);
    // Transfer the owned object reference to PHP's return slot.
    ctx.retval.set(.object, instance.object());
}
