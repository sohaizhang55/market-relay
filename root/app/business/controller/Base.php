<?php

namespace app\business\controller;

use think\admin\Controller;
use think\facade\Lang;

class Base extends Controller
{

    protected $sys_lang = 'zh_CN';

    function initialize()
    {
        parent::initialize();

        $forceLang = "zh-cn";
        Lang::setLangSet($forceLang);
        // 强制后台管理面板锁定为中文语言环境
        $this->sys_lang = lang_converter(Lang::getLangSet());
    }
}
