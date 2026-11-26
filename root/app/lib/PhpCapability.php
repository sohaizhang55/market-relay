<?php

declare(strict_types=1);

namespace app\lib;

/**
 * PHP 函数可用性探测（disable_functions 感知）。
 *
 * ── 为什么要有这个 ──────────────────────────────────────────────────────
 * 生产环境的 php.ini 常把 exec / shell_exec / readlink / disk_free_space 这类函数
 * 摘进 disable_functions。被摘掉的函数调用时抛的是 **Error**，`@` 压不住，
 * 而 ThinkPHP 的错误处理器会把它转成异常 —— 一个「顺手取一下 CPU 使用率」的调用
 * 就能把整个请求或常驻进程带走。
 *
 * 判据只有一条：`function_exists()` 对被 disable_functions 摘掉的函数同样返回 false。
 * 所以探测本身很简单，问题在于**它此前有两份拷贝**（Index::canCall 与
 * KernelSocketQueue::hasFunctions），各自带一份缓存数组和一句一模一样的注释。
 * 两份拷贝的坏处不是多写几行，而是「哪些函数需要探测」这件事没有单一出处，
 * 加一个新的高危函数时只会想起其中一处。
 *
 * 结果缓存在进程内：常驻进程里这个判断会被调很多次，而 disable_functions
 * 在进程生命周期内不会变。
 */
final class PhpCapability
{
    /** @var array<string,bool> 函数名 => 是否可调用 */
    private static array $cache = [];

    /** 单个函数是否可调用 */
    public static function has(string $fn): bool
    {
        return self::$cache[$fn] ??= \function_exists($fn);
    }

    /**
     * 一组函数是否**全部**可调用。
     * 用「全部」而不是「任一」：调用方通常是"这条路径需要 A 和 B 才能走通"，
     * 缺一个就得整条路径退化，没有部分可用这回事。
     */
    public static function hasAll(string ...$fns): bool
    {
        foreach ($fns as $fn) {
            if (!self::has($fn)) return false;
        }
        return true;
    }
}
