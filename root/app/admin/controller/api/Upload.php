<?php

declare(strict_types=1);
/**
 * +----------------------------------------------------------------------
 * | ThinkAdmin Plugin for ThinkAdmin
 * +----------------------------------------------------------------------
 * | 版权所有 2014~2026 ThinkAdmin [ thinkadmin.top ]
 * +----------------------------------------------------------------------
 * | 官方网站: https://thinkadmin.top
 * +----------------------------------------------------------------------
 * | 开源协议 ( https://mit-license.org )
 * | 免责声明 ( https://thinkadmin.top/disclaimer )
 * | 会员特权 ( https://thinkadmin.top/vip-introduce )
 * +----------------------------------------------------------------------
 * | gitee 代码仓库：https://gitee.com/zoujingli/ThinkAdmin
 * | github 代码仓库：https://github.com/zoujingli/ThinkAdmin
 * +----------------------------------------------------------------------
 */

namespace app\admin\controller\api;

use think\admin\Controller;
use think\admin\helper\QueryHelper;
use think\admin\model\SystemFile;
use think\admin\service\AdminService;
use think\admin\Storage;
use think\admin\storage\AliossStorage;
use think\admin\storage\AlistStorage;
use think\admin\storage\LocalStorage;
use think\admin\storage\QiniuStorage;
use think\admin\storage\TxcosStorage;
use think\admin\storage\UpyunStorage;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;
use think\exception\HttpResponseException;
use think\file\UploadedFile;
use think\Response;

/**
 * 文件上传接口.
 * @class Upload
 */
class Upload extends Controller
{
    /**
     * 文件上传脚本.
     * @throws \think\admin\Exception
     */
    public function index(): Response
    {
        $data = ['exts' => []];
        [$uuid, $unid, $exts] = $this->initUnid(false);
        $allows = str2arr(sysconf('storage.allow_exts|raw'));
        if (empty($uuid) && $unid > 0) {
            $allows = array_intersect($exts, $allows);
        }
        foreach ($allows as $ext) {
            $data['exts'][$ext] = Storage::mime($ext);
        }
        $data['exts'] = json_encode($data['exts'], JSON_UNESCAPED_UNICODE);
        $data['nameType'] = sysconf('storage.name_type|raw') ?: 'xmd5';
        return view(dirname(__DIR__, 2) . '/view/api/upload.js', $data)->contentType('application/x-javascript');
    }

    /**
     * 文件选择器.
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function image()
    {
        [$uuid, $unid] = $this->initUnid();
        SystemFile::mQuery()->layTable(function () {
            $this->title = '文件选择器';
        }, function (QueryHelper $query) use ($unid, $uuid) {
            if ($unid && $uuid) {
                $query->where(function ($query) use ($uuid, $unid) {
                    /* @var \think\db\Query $query */
                    $query->whereOr([['uuid', '=', $uuid], ['unid', '=', $unid]]);
                });
            } else {
                $query->where($unid ? ['unid' => $unid] : ['uuid' => $uuid]);
            }
            $query->where(['status' => 2, 'issafe' => 0])->in('xext#type');
            $query->like('name,hash')->dateBetween('create_at')->order('id desc');
        });
    }

    /**
     * 文件上传检查.
     */
    public function state()
    {
        try {
            [$uuid, $unid] = $this->initUnid();
            [$name, $safe] = [input('name'), $this->getSafe()];
            $data = ['uptype' => $this->getType(), 'safe' => intval($safe), 'key' => input('key')];
            $file = SystemFile::mk()->data($this->_vali([
                'xkey.value' => $data['key'],
                'type.value' => $this->getType(),
                'uuid.value' => $uuid,
                'unid.value' => $unid,
                'name.require' => '名称不能为空！',
                'hash.require' => '哈希不能为空！',
                'xext.require' => '后缀不能为空！',
                'size.require' => '大小不能为空！',
                'mime.default' => '',
                'status.value' => 1,
            ]));
            $mime = $file->getAttr('mime');
            if (empty($mime)) {
                $file->setAttr('mime', Storage::mime($file->getAttr('xext')));
            }
            $info = Storage::instance($data['uptype'])->info($data['key'], $safe, $name);
            if (isset($info['url'], $info['key'])) {
                $file->save(['xurl' => $info['url'], 'isfast' => 1, 'issafe' => $data['safe']]);
                $extr = ['id' => $file->id ?? 0, 'url' => $info['url'], 'key' => $info['key']];
                $this->success('文件已经上传', array_merge($data, $extr), 200);
            } elseif ($data['uptype'] === 'local') {
                $local = LocalStorage::instance();
                $data['url'] = $local->url($data['key'], $safe, $name);
                $data['server'] = $local->upload();
            } elseif ($data['uptype'] === 'qiniu') {
                $qiniu = QiniuStorage::instance();
                $data['url'] = $qiniu->url($data['key'], $safe, $name);
                $data['token'] = $qiniu->token($data['key'], 3600, $name);
                $data['server'] = $qiniu->upload();
            } elseif ($data['uptype'] === 'alioss') {
                $alioss = AliossStorage::instance();
                $token = $alioss->token($data['key'], 3600, $name);
                $data['url'] = $token['siteurl'];
                $data['policy'] = $token['policy'];
                $data['signature'] = $token['signature'];
                $data['OSSAccessKeyId'] = $token['keyid'];
                $data['server'] = $alioss->upload();
            } elseif ($data['uptype'] === 'txcos') {
                $txcos = TxcosStorage::instance();
                $token = $txcos->token($data['key'], 3600, $name);
                $data['url'] = $token['siteurl'];
                $data['q-ak'] = $token['q-ak'];
                $data['policy'] = $token['policy'];
                $data['q-key-time'] = $token['q-key-time'];
                $data['q-signature'] = $token['q-signature'];
                $data['q-sign-algorithm'] = $token['q-sign-algorithm'];
                $data['server'] = $txcos->upload();
            } elseif ($data['uptype'] === 'upyun') {
                $upyun = UpyunStorage::instance();
                $token = $upyun->token($data['key'], 3600, $name, input('hash', ''));
                $data['url'] = $token['siteurl'];
                $data['policy'] = $token['policy'];
                $data['server'] = $upyun->upload();
                $data['authorization'] = $token['authorization'];
            } elseif ($data['uptype'] === 'alist') {
                $alist = AlistStorage::instance();
                $data['url'] = $alist->url($data['key']);
                $data['server'] = $alist->upload();
                $data['filepath'] = $alist->real($data['key']);
                $data['authorization'] = $alist->token();
            } else {
                $this->error('未知的存储引擎！');
            }
            $file->save(['xurl' => $data['url'], 'isfast' => 0, 'issafe' => $data['safe']]);
            $this->success('获取上传授权参数', array_merge($data, ['id' => $file->id ?? 0]), 404);
        } catch (HttpResponseException $exception) {
            throw $exception;
        } catch (\Exception $exception) {
            $this->error($exception->getMessage());
        }
    }

    /**
     * 更新文件状态
     */
    public function done()
    {
        [$uuid, $unid] = $this->initUnid();
        $data = $this->_vali([
            'id.require' => '编号不能为空！',
            'hash.require' => '哈希不能为空！',
            'uuid.value' => $uuid,
            'unid.value' => $unid,
        ]);
        $file = SystemFile::mk()->where($data)->findOrEmpty();
        if ($file->isEmpty()) {
            $this->error('文件不存在！');
        }
        if ($file->save(['status' => 2])) {
            $this->success('更新成功！');
        } else {
            $this->error('更新失败！');
        }
    }

    /**
     * 文件上传入口.
     * @throws \think\admin\Exception
     */
    public function file()
    {
        [$uuid, $unid, $unexts] = $this->initUnid();
        // 开始处理文件上传
        $file = $this->getFile();
        $extension = strtolower($file->getOriginalExtension());
        $saveFileName = input('key') ?: Storage::name($file->getPathname(), $extension, '', 'md5_file');
        // 检查文件名称是否合法
        if (strpos($saveFileName, '..') !== false) {
            \think\facade\Log::record(["msg" => "path error 文件路径不能出现跳级操作！"], "error");
            $this->error('文件路径不能出现跳级操作！');
        }
        // 检查文件后缀是否被恶意修改
        if (strtolower(pathinfo(parse_url($saveFileName, PHP_URL_PATH), PATHINFO_EXTENSION)) !== $extension) {
            \think\facade\Log::record(["msg" => "img verify failed 文件后缀异常，请重新上传文件！"], "error");
            $this->error('文件后缀异常，请重新上传文件！');
        }
        // 屏蔽禁止上传指定后缀的文件
        if (!in_array($extension, str2arr(sysconf('storage.allow_exts|raw')))) {
            \think\facade\Log::record(["msg" => "file type not allowed 文件类型受限！"], "error");
            $this->error('文件类型受限，请在后台配置规则！');
        }
        // 前端用户上传后缀检查处理
        if (empty($uuid) && $unid > 0 && !in_array($extension, $unexts)) {
            \think\facade\Log::record(["msg" => "file type not allowed 文件类型受限！"], "error");
            $this->error('文件类型受限，请上传允许的文件类型！');
        }
        if (in_array($extension, ['sh', 'asp', 'bat', 'cmd', 'exe', 'php'])) {
            \think\facade\Log::record(["msg" => "file type not allowed 文件类型受限！"], "error");
            $this->error('文件安全保护，禁止上传可执行文件！');
        }
        try {
            $safeMode = $this->getSafe();
            if (($type = $this->getType()) === 'local') {
                $local = LocalStorage::instance();
                $distName = $local->path($saveFileName, $safeMode);
                if (PHP_SAPI === 'cli') {
                    is_dir(dirname($distName)) || mkdir(dirname($distName), 0777, true);
                    rename($file->getPathname(), $distName);
                } else {
                    $file->move(dirname($distName), basename($distName));
                }
                $info = $local->info($saveFileName, $safeMode, $file->getOriginalName());
                if (in_array($extension, ['jpg', 'gif', 'png', 'bmp', 'jpeg', 'wbmp'])) {
                    if ($this->imgNotSafe($distName)) {
                        $local->del($saveFileName);
                        \think\facade\Log::record(["msg" => "img verify failed 图片未通过安全检查！"], "error");
                        $this->error('图片未通过安全检查！');
                    }
                    [$width, $height] = getimagesize($distName);
                    if (($width < 1 || $height < 1) && $local->del($saveFileName)) {
                        \think\facade\Log::record(["msg" => "img verify failed 读取图片的尺寸失败！"], "error");
                        $this->error('读取图片的尺寸失败！');
                    }
                }
            } else {
                $bina = file_get_contents($file->getPathname());
                $info = Storage::instance($type)->set($saveFileName, $bina, $safeMode, $file->getOriginalName());
            }
            if (isset($info['url'])) {
                $this->success('文件上传成功！', ['url' => $safeMode ? $saveFileName : $info['url']]);
            } else {
                \think\facade\Log::record(["msg" => "file process failed 文件处理失败！"], "error");
                $this->error('文件处理失败，请稍候再试！');
            }
        } catch (HttpResponseException $exception) {
            throw $exception;
        } catch (\Exception $exception) {
            trace_file($exception);
            \think\facade\Log::record(["msg" => "upload error " . $exception->getMessage()], "error");
            $this->error($exception->getMessage());
        }
    }

    /**
     * 获取上传类型.
     */
    private function getSafe(): bool
    {
        return boolval(input('safe', '0'));
    }

    /**
     * 获取上传方式.
     * @throws \think\admin\Exception
     */
    private function getType(): string
    {
        $type = strtolower(input('uptype', ''));
        if (in_array($type, array_keys(Storage::types()))) {
            return $type;
        }
        return strtolower(sysconf('storage.type|raw'));
    }

    /**
     * 获取文件对象
     * @return UploadedFile|void
     */
    private function getFile(): UploadedFile
    {
        try {
            $file = $this->request->file('file');
            if ($file instanceof UploadedFile) {
                return $file;
            }
            $this->error('读取临时文件失败！');
        } catch (HttpResponseException $exception) {
            throw $exception;
        } catch (\Exception $exception) {
            trace_file($exception);
            $this->error(lang($exception->getMessage()));
        }
    }

    /**
     * 初始化用户状态
     */
    private function initUnid(bool $check = true): array
    {
        $uuid = AdminService::getUserId();
        [$unid, $exts] = AdminService::withUploadUnid();
        if ($check && empty($uuid) && empty($unid)) {
            $this->error('未登录，禁止使用文件上传！');
        } else {
            return [$uuid, $unid, $exts];
        }
    }

    private function imgNotSafe(string $filename): bool
    {
        // 检查文件是否存在
        if (!file_exists($filename)) {
            \think\facade\Log::record(["msg" => "img verify failed 文件不存在！"], "error");
            return true;
        }

        // 文件检查，测试获取图片大小
        if (@getimagesize($filename) === false) {
            \think\facade\Log::record(["msg" => "img verify failed 获取图片尺寸失败！"], "error");
            return true;
        }

        // 文件检查，测试获取文件内容
        $content = file_get_contents($filename);
        if ($content === false) {
            \think\facade\Log::record(["msg" => "img verify failed 获取图片内容失败！"], "error");
            return true;
        }

        // 转换文件 hexs
        $source = fopen($filename, 'rb');
        if (($size = filesize($filename)) > 512) {
            $hexs = bin2hex(fread($source, 512));
            fseek($source, $size - 512);
            $hexs .= bin2hex(fread($source, 512));
        } else {
            $hexs = bin2hex(fread($source, $size));
        }
        if (is_resource($source)) {
            fclose($source);
        }

        // 特别处理敏感字符
        $php_str = '<' . '?' . 'php' . ' ';

        // 定义严谨的禁止标签（去掉可能引起误报的短词）
        foreach ([$php_str, '<script '] as $invalid_key_index => $invalid_key) {
            $trimed_key = trim($invalid_key);
            // 核心修复：确保 $tag 不为空
            if (empty($trimed_key)) {
                \think\facade\Log::record([
                    "msg" => "invalid key cannot be empty 安全检查已被跳过",
                    "position" => $invalid_key_index,
                    "trimed_key" => $trimed_key,
                ], "error");
                continue;
            }
            if (stripos($content, $trimed_key) !== false) {
                \think\facade\Log::record([
                    "msg" => "img verify failed 图片中包含非法标签！",
                    "position" => $invalid_key_index,
                    "trimed_key" => $trimed_key
                ], "error");
                return true;
            }
        }

        // 正则检查（建议只针对最危险的特征）
        // 注意：某些图片包含 'system' 字符串是正常的（比如厂商名），所以正则要精准
        $pattern = '/(3c25.*?28.*?29.*?253e)|(3c3f.*?28.*?29.*?3f3e)|(3C534352495054)|(2F5343524950543E)|(3C736372697074)|(2F7363726970743E)/is';
        if (preg_match($pattern, $hexs)) {
            \think\facade\Log::record(["msg" => "img verify failed 命中正则危险特征！"], "error");
            return true;
        }

        return false;
    }
}
