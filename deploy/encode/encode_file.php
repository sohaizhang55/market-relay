<?php

/**
 * Usage:
 * php encode_file.php --oldfile old_file_path --newfile new_file_path --expire "2016-10-10 10:10:10 --config config"
 * @author: liexusong
 */

$bolt_key = get_cfg_var('bolt.key');
if (empty($bolt_key)) {
    die('[ERROR] config not found');
}

$newfile  = null;
$oldfile  = null;
$config = null;
$datetime = 0;

for ($i = 1; $i < count($argv); $i++)
{
    switch ($argv[$i]) {
    case '--newfile':
        $newfile = @$argv[++$i];
        break;
    case '--oldfile':
        $oldfile = @$argv[++$i];
        break;
    case '--expire':
        $datetime = @$argv[++$i];
        break;
    case '--config':
        $config = @$argv[++$i];
        break;
    default:
        echo "Fatal error: Invaild option `{$argv[$i]}'\n";
        exit(1);
    }
}

if (empty($oldfile) || !file_exists($oldfile)) {
    echo "Encrypt file `{$oldfile}' not found!\n";
    exit(1);
}

if (empty($newfile)) {

    $paths = explode('.', basename($oldfile));

    $exten = $paths[count($paths)-1];

    unset($paths[count($paths)-1]);

    $name = implode('.', $paths);

    $newfile = dirname($oldfile) . '/' . $name . '_enc.' . $exten;
}

$expire = $datetime ? strtotime($datetime) : 0;

// 读取文件
$code = file_get_contents($oldfile);

// 移除 <?php 标签
$code = preg_replace('/<\?php/m', '', $code);

// 检查并处理 declare(strict_types=1);
$hasStrict = false;
if (preg_match('/declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/', $code)) {
    $hasStrict = true;
    // 从原始内容中移除它，避免位置错误
    $code = preg_replace('/declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/', '', $code);
}

// 检查 + 组装注入逻辑
if (!$config) {
    $config = pathinfo($oldfile, PATHINFO_FILENAME);
    $config = preg_replace('/\.sample/m', '', $config);
}

// 检查是否为 Workerman 的配置文件
$isWorkerman = (strpos($oldfile, 'Workerman') !== false) || (strpos($oldfile, 'GatewayWorker') !== false);

// 设置 prefix 
$prefix = $hasStrict ? "declare(strict_types=1);\n" : "";
$suffix = "";

if ($isWorkerman) {
    // --- Workerman Mode: Return the result of the anonymous function ---
    // $prefix .= "return (function(){\n";
    // $suffix = "\n})();";
} else {
    // --- TP8 Mode: Inject into the Config Facade ---
    $prefix .= "\n\\think\\facade\\Config::set((function(){\n";
    $suffix = "\n})(), '{$config}');\n";
}

// 最终待加密的完整代码
$code = $prefix . $code . $suffix;

// php-bolt 加密代码
$encrypted = bolt_encrypt($code, $bolt_key);
$final  = "<?php bolt_decrypt(__FILE__,PHP_BOLT_KEY);" . PHP_EOL;
$final .= '##!!!##' . $encrypted;

// 写入文件
file_put_contents($newfile, $final);

echo "Encrypt file `{$oldfile}' and save to `{$newfile}' success!\n";