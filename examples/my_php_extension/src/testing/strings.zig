const std = @import("std");
const phpz = @import("phpz");
const String = phpz.zend.String;
const expect = std.testing.expect;

pub fn checkZendStrings() !void {
    for ([_]*String{ String.empty(), String.char('x') }) |interned| {
        const gc_count = interned.ptr().gc.refcount;
        try expect(interned.isInterned() and interned.refcount() == 1);
        interned.addref();
        interned.delref();
        interned.copy().release();
        try expect(interned.ptr().gc.refcount == gc_count);
        try expect(interned.dupe(false) == interned);
        var writable = interned.copy().separate(false);
        defer writable.release();
        try expect(!writable.isInterned() and writable.refcount() == 1);
        writable = writable.extend(3, false);
        @memcpy(writable.mutableSlice(), "abc");
        try expect(interned.len() <= 1);
        try expect(writable.cstr()[3] == 0);
    }

    for ([_]bool{ false, true }) |persistent| {
        var buffer = String.alloc(4, persistent);
        defer buffer.release();
        @memcpy(buffer.mutableSlice(), "a\x00bc");
        try expect(buffer.cstr()[4] == 0);
        const first_hash = buffer.hash();
        try expect(buffer.ptr().h == first_hash and buffer.hash() == first_hash);
        buffer.mutableSlice()[0] = 'z';
        try expect(buffer.ptr().h == 0);
        try expect(buffer.hash() == phpz.c.zend_hash_func("z\x00bc", 4));

        const duplicate = buffer.dupe(persistent);
        defer duplicate.release();
        try expect(duplicate != buffer and buffer.refcount() == 1);
        const original = buffer.copy();
        defer original.release();
        try expect(buffer.refcount() == 2);
        buffer = buffer.separate(persistent);
        try expect(buffer != original and original.refcount() == 1);
        buffer.mutableSlice()[0] = 'q';
        try std.testing.expectEqualStrings("z\x00bc", original.slice());

        buffer = buffer.extend(8, persistent);
        @memcpy(buffer.mutableSlice()[4..], "tail");
        try expect(buffer.cstr()[8] == 0);
        try std.testing.expectEqualStrings("q\x00bctail", buffer.slice());
        const shared = buffer.copy();
        defer shared.release();
        buffer = buffer.truncate(2, persistent);
        try expect(buffer != shared and shared.len() == 8);
        try expect(buffer.cstr()[2] == 0);
        try std.testing.expectEqualStrings("q\x00", buffer.slice());
        buffer = buffer.truncate(0, persistent);
        try expect(buffer.isEmpty() and buffer.cstr()[0] == 0);
        buffer = buffer.extend(0, persistent);
        try expect(buffer.cstr()[0] == 0);
    }

    const source = String.init("source", false);
    defer source.release();
    var grown = source.copy().extend(9, false);
    defer grown.release();
    @memcpy(grown.mutableSlice()[6..], "end");
    try std.testing.expectEqualStrings("source", source.slice());
    try std.testing.expectEqualStrings("sourceend", grown.slice());
    const persistent_copy = source.dupe(true);
    defer persistent_copy.release();
    try expect((phpz.c.GC_FLAGS(persistent_copy.ptr()) & phpz.c.IS_STR_PERSISTENT) != 0);
}
