<?php

/**
 * ThinkPHP 8 专用 phpBolt 加密脚本
 * 运行：php8.3 tp8_encrypt.php
 */

// ============================================================
// 配置区域
// ============================================================

$project_dir = dirname(dirname(dirname(__FILE__)));
$project_parent_dir = dirname($project_dir);

$src_last_dir = $project_parent_dir . '/work';
$src_root_dir = $project_dir . '/root';

$dst_dir = $project_parent_dir . '/pub';
$dst_root_dir = $dst_dir . '/root';

$bolt_key = get_cfg_var('bolt.key');
if (empty($bolt_key)) {
    die('[ERROR] config not found');
}

define('PHP_BOLT_KEY', $bolt_key); // 加密密钥（务必保密）
define('SRC_PATH', $src_root_dir);     // 项目根目录（绝对路径）
define('DEST_PATH', $dst_root_dir); // 输出目录

// 需要加密的目录（基于项目物理根目录 $project_dir）
$encrypt_dirs = [
    'root/app',
    'root/extend',
    'Workerman',
];

$src_path_list = [
    $project_dir . '/root/app',
    $project_dir . '/root/extend',
    $project_dir . '/Workerman',
];

// 不加密、直接复制的目录
$copy_only_dirs = [
    'root/public',
    'root/config',
];

// 不加密的文件扩展名（直接复制）
$skip_extensions = [
    'html',
    'htm',
    'css',
    'js',
    'json',
    'xml',
    'txt',
    'md',
    'jpg',
    'png',
    'gif',
    'svg',
    'env',
    'lock',
    'htaccess'
];

// 特定不加密的相对路径（直接复制，防止 Composer 和 ThinkPHP 核心引导文件因各种反射或强制 return 机制而损坏）
$skip_encrypt_paths = [
    'Workerman/vendor/',
    'Workerman/config/',
    'Workerman/start.php',
    'Workerman/logger.xml',
    'Workerman/bootstrap.php',
    'root/app/business/middleware.php',
    'root/app/api/middleware.php',
    'root/app/inner_api/middleware.php',
    'root/app/admin/lang/',
    'root/app/api/lang/',
    'root/app/business/lang/',
];

// 根目录下直接复制的文件
$root_copy_files = [
    '.env',
    '.env.example',
    'composer.json',
    'composer.lock',
    'think',
];

// 需要直接跳过不复制、如果目标目录存在则立刻删除的文件或目录（基于项目物理根目录的相对路径）
$skip_copy_files = [
    // 例如：'root/runtime', 'root/.env'
    'plugin.md',
    'readme.md',
];

$abs_skip_copy_files = array_map(function ($path) use ($project_dir) {
    return $project_dir . '/' . $path;
}, $skip_copy_files);
// ============================================================

$total_encrypted = 0;
$total_copied    = 0;
$total_skipped   = 0;

// ---------- 工具函数 ----------

function ensure_dir(string $dir): void
{
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

function encrypt_file(string $src, string $dest, string $key): bool
{
    $code = file_get_contents($src);

    if (trim($code) === '') {
        copy($src, $dest);
        return false;
    }

    // ← 关键：先移除 <?php 标签
    $code = preg_replace('/<\?php/m', '', $code);

    $encrypted = bolt_encrypt($code, $key);
    $final  = "<?php bolt_decrypt(__FILE__,PHP_BOLT_KEY);return 0;" . PHP_EOL;
    $final .= '##!!!##' . $encrypted;
    file_put_contents($dest, $final);
    return true;
}

function process_directory(
    string $src_dir,
    string $dest_dir,
    array  $skip_ext,
    string $key,
    bool   $do_encrypt,
    int    &$encrypted,
    int    &$copied
): void {
    ensure_dir($dest_dir);
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $src_dir,
            FilesystemIterator::SKIP_DOTS
        )
    );

    global $modify_files, $project_dir, $skip_copy_files;

    foreach ($items as $item) {
        $src_file = $item->getPathname();

        $root_relative = str_replace('\\', '/', substr($src_file, strlen($project_dir) + 1));
        $should_skip_copy = false;
        foreach ($skip_copy_files as $skip_path) {
            if ($root_relative === $skip_path || str_starts_with($root_relative, $skip_path . '/')) {
                $should_skip_copy = true;
                break;
            }
        }
        if ($should_skip_copy) {
            continue;
        }

        $relative  = substr($src_file, strlen($src_dir) + 1);
        $dest_file = $dest_dir . DIRECTORY_SEPARATOR . $relative;

        if (file_exists($dest_file) && !in_array($src_file, $modify_files)) {
            continue;
        }

        ensure_dir(dirname($dest_file));

        $ext = strtolower($item->getExtension());

        $should_encrypt = $do_encrypt && $ext === 'php' && !in_array($ext, $skip_ext);

        if ($should_encrypt) {
            global $skip_encrypt_paths;
            if (!empty($skip_encrypt_paths)) {
                $root_relative = str_replace('\\', '/', substr($src_file, strlen($project_dir) + 1));
                foreach ($skip_encrypt_paths as $skip_path) {
                    if (str_starts_with($root_relative, $skip_path)) {
                        $should_encrypt = false;
                        break;
                    }
                }
            }
        }

        if ($should_encrypt) {
            encrypt_file($src_file, $dest_file, $key);
            $encrypted++;
            echo "  [ENC] $relative\n";
        } else {
            copy($src_file, $dest_file);
            $copied++;
            echo "  [CPY] $relative\n";
        }
    }
}

/**
 * 文件夹文件拷贝
 *
 * @param string $src 来源文件夹
 * @param string $dst 目的地文件夹
 * @return bool
 */
function dir_copy($src = '', $dst = '', $exclude_dirs = [], &$modify_files = [])
{
    if (empty($src) || empty($dst)) {
        return false;
    }

    if (in_array($src, $exclude_dirs)) {
        echo "skip exclude dir:" . $src . "\n";
        return;
    }

    $dir = opendir($src);
    dir_mkdir($dst);
    while (false !== ($file = readdir($dir))) {
        if (($file != '.') && ($file != '..') && ($file != '.git')
            && ($file != 'install.sh') && ($file != 'install_append.sh')
        ) {
            if (is_dir($src . '/' . $file)) {
                dir_copy($src . '/' . $file, $dst . '/' . $file, $exclude_dirs, $modify_files);
            } else {
                if (in_array($src . '/' . $file, $exclude_dirs)) {
                    continue;
                }

                if (
                    !is_file($dst . '/' . $file)
                    || filesize($src . '/' . $file) != filesize($dst . '/' . $file)
                    || md5_file($src . '/' . $file) != md5_file($dst . '/' . $file)
                ) {

                    // var_dump($src . '/' . $file);
                    // var_dump(md5($src . '/' . $file));
                    // var_dump($dst . '/' . $file);
                    // var_dump(md5($dst . '/' . $file)); exit(1);
                    copy($src . '/' . $file, $dst . '/' . $file);
                    echo "copy modify file:" . $src . '/' . $file . "\n";
                    $modify_files[] = $src . '/' . $file;
                }
            }
        }
    }
    closedir($dir);

    return true;
}

/**
 * 创建文件夹
 *
 * @param string $path 文件夹路径
 * @param int $mode 访问权限
 * @param bool $recursive 是否递归创建
 * @return bool
 */
function dir_mkdir($path = '', $mode = 0755, $recursive = true)
{
    clearstatcache();
    if (!is_dir($path)) {
        mkdir($path, $mode, $recursive);
        return chmod($path, $mode);
    }
}

/**
 * 递归删除文件或目录
 *
 * @param string $path
 */
function remove_dir_or_file($path)
{
    if (is_dir($path)) {
        $objects = scandir($path);
        foreach ($objects as $object) {
            if ($object != "." && $object != "..") {
                if (is_dir($path . "/" . $object) && !is_link($path . "/" . $object)) {
                    remove_dir_or_file($path . "/" . $object);
                } else {
                    unlink($path . "/" . $object);
                }
            }
        }
        rmdir($path);
    } elseif (is_file($path) || is_link($path)) {
        unlink($path);
    }
}

/**
 * 清理目标目录中存在但源目录中已删除的文件和文件夹
 *
 * @param string $src 源文件夹
 * @param string $dst 目的地文件夹
 */
function clean_deleted_files($src, $dst)
{
    if (!is_dir($dst)) {
        return;
    }
    $dir = opendir($dst);
    while (false !== ($file = readdir($dir))) {
        if (($file != '.') && ($file != '..') && ($file != '.git')
            && ($file != 'install.sh') && ($file != 'install_append.sh')
        ) {
            $src_path = $src . '/' . $file;
            $dst_path = $dst . '/' . $file;

            if (!file_exists($src_path)) {
                remove_dir_or_file($dst_path);
                echo "delete removed file/dir: " . $dst_path . "\n";
            } else {
                if (is_dir($dst_path) && is_dir($src_path)) {
                    clean_deleted_files($src_path, $dst_path);
                }
            }
        }
    }
    closedir($dir);
}

// ---------- 主流程 ----------

echo "\n============================\n";
echo " ThinkPHP 8 phpBolt 加密工具\n";
echo "============================\n\n";

// 检查 bolt 扩展
if (!function_exists('bolt_encrypt')) {
    die("[ERROR] bolt 扩展未加载，请确认 php8.3 -m | grep bolt\n");
}

// 检查源目录
if (!is_dir(SRC_PATH)) {
    die("[ERROR] 源目录不存在：" . SRC_PATH . "\n");
}

// 增量更新：不删除旧目录，保留未修改的文件
if (is_dir(DEST_PATH)) {
    echo "[INFO] 保留旧目录，仅覆盖更新文件...\n";
} else {
    ensure_dir(DEST_PATH);
}

echo "=====clean skip copy files\n";
foreach ($skip_copy_files as $skip_path) {
    $dst_skip_path = $dst_dir . '/' . $skip_path;
    if (file_exists($dst_skip_path) || is_dir($dst_skip_path)) {
        remove_dir_or_file($dst_skip_path);
        echo "delete skip config file/dir: " . $dst_skip_path . "\n";
    }
    $work_skip_path = $src_last_dir . '/' . $skip_path;
    if (file_exists($work_skip_path) || is_dir($work_skip_path)) {
        remove_dir_or_file($work_skip_path);
    }
}

echo "=====clean deleted files\n";
clean_deleted_files($project_dir, $src_last_dir);
clean_deleted_files($project_dir, $dst_dir);

echo "=====compair modify files\n";
$modify_files = [];
dir_copy($project_dir, $src_last_dir, $abs_skip_copy_files, $modify_files);

echo "======copy modify files\n";
dir_copy($project_dir, $dst_dir, array_merge($src_path_list, $abs_skip_copy_files));

echo "======start encode files\n";

// 加密指定目录
echo "\n[1/3] 加密核心目录...\n";
foreach ($encrypt_dirs as $dir) {
    $src  = $project_dir  . '/' . $dir;
    $dest = $dst_dir . '/' . $dir;
    if (!is_dir($src)) {
        echo "  [SKIP] $dir 目录不存在\n";
        $total_skipped++;
        continue;
    }
    echo "\n  → 加密目录：$dir\n";
    process_directory(
        $src,
        $dest,
        $skip_extensions,
        PHP_BOLT_KEY,
        true,
        $total_encrypted,
        $total_copied
    );
}

// 直接复制不加密目录
echo "\n[2/3] 复制不加密目录...\n";
foreach ($copy_only_dirs as $dir) {
    $src  = $project_dir  . '/' . $dir;
    $dest = $dst_dir . '/' . $dir;
    if (!is_dir($src)) {
        echo "  [SKIP] $dir 不存在\n";
        continue;
    }
    echo "\n  → 复制目录：$dir\n";
    process_directory(
        $src,
        $dest,
        $skip_extensions,
        PHP_BOLT_KEY,
        false,
        $total_encrypted,
        $total_copied
    );
}

// 复制根目录文件
echo "\n[3/3] 复制根目录文件...\n";
foreach ($root_copy_files as $file) {
    $src  = SRC_PATH  . '/' . $file;
    $dest = DEST_PATH . '/' . $file;

    $root_relative = 'root/' . $file;
    $should_skip_copy = false;
    foreach ($skip_copy_files as $skip_path) {
        if ($root_relative === $skip_path || str_starts_with($root_relative, $skip_path . '/')) {
            $should_skip_copy = true;
            break;
        }
    }
    if ($should_skip_copy) {
        continue;
    }

    if (file_exists($src)) {
        if (file_exists($dest) && !in_array($src, $modify_files)) {
            continue;
        }
        copy($src, $dest);
        echo "  [CPY] $file\n";
        $total_copied++;
    }
}

// ---------- 完成报告 ----------
echo "\n============================\n";
echo " 完成！\n";
echo "============================\n";
echo " 已加密文件：$total_encrypted\n";
echo " 直接复制：  $total_copied\n";
echo " 跳过目录：  $total_skipped\n";
echo " 输出目录：  " . DEST_PATH . "\n\n";
