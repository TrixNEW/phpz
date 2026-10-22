const std = @import("std");
const phpz = @import("phpz");

pub fn readLastError(clear_exception: bool) !?*phpz.zend.Array {
    if (clear_exception) {
        const thrown = phpz.exception.throwException(phpz.ClassEntry.from(phpz.globals.class.entry("Exception")), "pending fixture", 0);
        const pending = phpz.exception.pending();
        phpz.exception.clear();
        try std.testing.expect(thrown == error.PhpException);
        try std.testing.expect(pending != null);
        try std.testing.expect(pending.? == error.PhpException);
        try std.testing.expect(!phpz.exception.hasPending());
    }
    const record = phpz.errors.last() orelse return null;
    var value = phpz.Zval.raw.undef;
    const result = phpz.Zval.Array.empty(&value);
    result.set(.int, "type", @backingInt(record.level));
    result.set(.string, "message", record.message);
    if (record.file) |file| result.set(.string, "file", file) else result.set(.null, "file", {});
    result.set(.int, "line", record.line);
    return result.zendArray();
}
