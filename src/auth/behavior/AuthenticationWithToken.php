<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2017 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: yunwuxin <448901948@qq.com>
// +----------------------------------------------------------------------

namespace yunwuxin\auth\behavior;

use yunwuxin\auth\exception\AuthenticationException;

class AuthenticationWithToken
{
    public function run()
    {
        if (!auth()->guard('token')->check()) {
            throw new AuthenticationException;
        }
        auth()->shouldUse('token');
    }
}