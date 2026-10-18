const phpz = @import("phpz");

/// PHP: MyPHPExt\Test\checkTsrmCache(): bool
/// phpz's TSRM fast path must agree with PHP's own lookup on every thread.
pub fn checkTsrmCache() bool {
    if (comptime !phpz.zts) return true else return phpz.tsrm.localStorage() == phpz.c.tsrm_get_ls_cache();
}
