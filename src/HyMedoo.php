<?php

/**
 * Created by IntelliJ IDEA.
 * Author: sgenmi
 * Date: 2020/5/22 下午2:51
 * Email: 150560159@qq.com
 *
 * Medoo database framework
 * https://medoo.in
 * Version 1.7.10
 *
 * Copyright 2020, Angel Lai
 * Released under the MIT license
 */

namespace Sgenmi\HyMedoo;
use Hyperf\Utils\ApplicationContext;

class HyMedoo {

    public function __construct()
    {
    }

    public function __call($name, $arguments)
    {
        $container = ApplicationContext::getContainer();
        $self = $container->get(Medoo::class);
        return $self->{$name}(...$arguments);
    }

    public static function __callStatic($name,$arguments)
    {
        $container = ApplicationContext::getContainer();
        $self = $container->get(Medoo::class);
        return $self->{$name}(...$arguments);
    }


}