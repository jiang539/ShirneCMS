<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2018 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

Route::pattern([
    'name' => '[a-zA-Z]\w*',
    'id'   => '\d+',
    'group'=> '[a-zA-Z]\w*',
    'action'=> '[a-zA-Z]\w*',
]);

Route::get('index$', 'index/index/index');

Route::group('article',[
    ':name'=>'index/article/index',
    ':id'=>'index/article/view'
])->method('GET');

Route::get('page/:group/[:name]','index/page/index');

Route::get('notice/:id', 'index/article/notice');

Route::group('auth',[
    'login/[:type]'=>'index/login/index',
    'callback'=>'index/login/callback',
    'getpassword'=>'index/login/getpassword',
    'register'=>'index/login/register',
    'checkusername'=>'index/login/checkusername',
    'checkunique'=>'index/login/checkunique',
    'verify'=>'index/login/verify',
    'forgot'=>'index/login/forgot',
])->method('GET|POST');

Route::group('user',[
    'index'=>'index/member/index',
    'profile'=>'index/member/profile',
    'avatar'=>'index/member/avatar',
    'security'=>'index/member/security',
    'log'=>'index/member/actionlog',
    'balance'=>'index/member/moneylog',
    'logout'=>'index/member/logout'
])->method('GET|POST');


return [

];
