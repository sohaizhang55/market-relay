<?php

namespace app\enum\lib;

/**
 * Label 注解类
 */
#[\Attribute(\Attribute::TARGET_CLASS_CONSTANT)]
class Label
{
    public function __construct(
        public string $text,
    ) {
    }
}
