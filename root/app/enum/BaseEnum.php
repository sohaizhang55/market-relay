<?php

namespace app\enum;

use app\enum\lib\Label;
use think\facade\Lang;

/**
 * 基础枚举功能 Trait
 * 统一管理通用的 label和labelText 方法，支持基于 PHP 8 原生注解获取 labels
 */
trait BaseEnum
{
    /**
     * 全部显示映射 (自动通过 #[Label] 注解提取并翻译)
     * @return array
     */
    public static function labels(): array
    {
        static $enumLabelCache = [];
        $class = static::class;
        $lang = Lang::getLangSet(); // 获取当前系统侦测到的语言标识
        $cacheKey = $class . ':' . $lang;
        
        if (isset($enumLabelCache[$cacheKey])) {
            return $enumLabelCache[$cacheKey];
        }

        $labels = [];
        try {
            $ref = new \ReflectionEnum($class);
            foreach ($ref->getCases() as $case) {
                $val = $case instanceof \ReflectionEnumBackedCase ? $case->getBackingValue() : $case->getName();
                
                // 读取标注的 #[Label('中文')]
                $attributes = $case->getAttributes(Label::class);
                if (!empty($attributes)) {
                    $rawText = $attributes[0]->newInstance()->text;
                    // 通过 i18n 系统转换
                    $labels[$val] = lang($rawText);
                } else {
                    $labels[$val] = $val;
                }
            }
        } catch (\ReflectionException $e) {
            // fallback if anything goes wrong
        }

        $enumLabelCache[$cacheKey] = $labels;
        return $labels;
    }

    /**
     * 单个显示名称 (通过底层值)
     * @param string $value
     * @return string
     */
    public static function label(string $value): string
    {
        return static::labels()[$value] ?? $value;
    }

    /**
     * 获取当前实例显示名称
     * @return string
     */
    public function labelText(): string
    {
        return static::labels()[$this->value] ?? (string) $this->value;
    }

    /**
     * 检查当前枚举是否允许开放给前端获取接口
     * 默认拒绝。在子定义中增加 `public const EXPORT_API = true;` 来开放
     * @return bool
     */
    public static function allowFrontend(): bool
    {
        return defined('static::EXPORT_API') && constant('static::EXPORT_API') === true;
    }

    /**
     * 转换为适用于前端的选项列表
     * @return array
     */
    public static function toOptions(): array
    {
        $options = [];
        foreach (static::labels() as $value => $label) {
            $options[] = [
                'label' => $label,
                'value' => $value,
            ];
        }
        return $options;
    }
}
