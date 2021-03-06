<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2016 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: yunwuxin <448901948@qq.com>
// +----------------------------------------------------------------------
namespace yunwuxin\auth\guard;

use think\Cookie;
use think\helper\Str;
use think\Hook;
use think\Response;
use yunwuxin\auth\interfaces\Authenticatable;
use yunwuxin\auth\Guard;
use yunwuxin\auth\interfaces\Authorizable;
use yunwuxin\auth\interfaces\StatefulGuard;
use yunwuxin\auth\interfaces\SupportsBasicAuth;
use yunwuxin\auth\Request;

class Session extends Guard implements StatefulGuard, SupportsBasicAuth
{

    /**
     * 上次通过认证的用户
     * @var Authenticatable
     */
    protected $lastAttempted;

    /**
     * 是否通过cookie记住用户
     * @var bool
     */
    protected $viaRemember = false;

    /** @var bool 是否登出 */
    protected $loggedOut = false;

    protected $tokenRetrievalAttempted = false;

    /**
     * 是否通过认证
     * @return mixed
     */
    public function check()
    {
        return !is_null($this->user());
    }

    /**
     * 获取通过认证的用户
     * @return Authenticatable|Authorizable|null
     */
    public function user()
    {
        if ($this->loggedOut) {
            return null;
        }

        if (!is_null($this->user)) {
            return $this->user;
        }

        $id = \think\Session::get($this->getName());

        $user = null;

        if (!is_null($id)) {
            $user = $this->provider->retrieveById($id);
        }

        $recaller = $this->getRecaller();

        if (is_null($user) && !is_null($recaller)) {
            $user = $this->getUserByRecaller($recaller);

            if ($user) {
                \think\Session::set($this->getName(), $user->getAuthId());

                Hook::listen('auth_login', $user, true);
            }
        }

        return $this->user = $user;
    }

    /**
     * 用户id
     * @return int|null
     */
    public function id()
    {
        if ($this->loggedOut) {
            return null;
        }

        $id = \think\Session::get($this->getName());

        if (is_null($id) && $this->user()) {
            $id = $this->user()->getAuthId();
        }

        return $id;
    }

    /**
     * 认证用户
     * @param  array $credentials
     * @return bool
     */
    public function validate(array $credentials = [])
    {
        return $this->attempt($credentials, false, false);
    }

    /**
     * 设置当前用户
     * @param  Authenticatable $user
     * @return Session
     */
    public function setUser(Authenticatable $user)
    {
        $this->user      = $user;
        $this->loggedOut = false;
        return $this;
    }

    /**
     * 获取上次通过认证的用户
     * @return Authenticatable
     */
    public function getLastAttempted()
    {
        return $this->lastAttempted;
    }

    /**
     * Session键名
     * @return string
     */
    protected function getName()
    {
        return 'login_' . sha1(static::class);
    }

    public function getRecallerName()
    {
        return 'remember_' . sha1(static::class);
    }

    protected function getRecaller()
    {
        return Cookie::get($this->getRecallerName());
    }

    protected function getUserByRecaller($recaller)
    {
        if ($this->validRecaller($recaller) && !$this->tokenRetrievalAttempted) {
            $this->tokenRetrievalAttempted = true;

            list($id, $token) = explode('|', $recaller, 2);

            $this->viaRemember = !is_null($user = $this->provider->retrieveByToken($id, $token));

            return $user;
        }
    }

    protected function validRecaller($recaller)
    {
        if (!is_string($recaller) || !Str::contains($recaller, '|')) {
            return false;
        }

        $segments = explode('|', $recaller);

        return count($segments) == 2 && trim($segments[0]) !== '' && trim($segments[1]) !== '';
    }

    /**
     * 尝试登录
     * @param  array $credentials
     * @param  bool  $remember
     * @param  bool  $login
     * @return bool
     */
    public function attempt(array $credentials = [], $remember = false, $login = true)
    {
        $this->lastAttempted = $user = $this->provider->retrieveByCredentials($credentials);

        if ($this->hasValidCredentials($user, $credentials)) {
            if ($login) {
                $this->login($user, $remember);
            }

            return true;
        }

        return false;
    }

    /**
     * 登录（当前请求有效）
     * @param  array $credentials
     * @return bool
     */
    public function once(array $credentials = [])
    {
        if ($this->validate($credentials)) {
            $this->setUser($this->lastAttempted);

            return true;
        }

        return false;
    }

    /**
     * 设置登录用户
     * @param  Authenticatable $user
     * @param  bool            $remember
     * @return void
     */
    public function login(Authenticatable $user, $remember = false)
    {
        \think\Session::set($this->getName(), $user->getAuthId());

        if ($remember) {
            $this->createRememberTokenIfDoesntExist($user);
            $this->createRecaller($user);
        }

        Hook::listen('auth_login', $user, $remember);

        $this->setUser($user);
    }

    /**
     * 通过用户id登录
     * @param  mixed $id
     * @param  bool  $remember
     * @return false|Authenticatable
     */
    public function loginUsingId($id, $remember = false)
    {
        $user = $this->provider->retrieveById($id);

        if (!is_null($user)) {
            $this->login($user, $remember);

            return $user;
        }

        return false;
    }

    /**
     * 通过用户id登录（当前请求有效）
     * @param  mixed $id
     * @return bool|Authenticatable
     */
    public function onceUsingId($id)
    {
        $user = $this->provider->retrieveById($id);

        if (!is_null($user)) {
            $this->setUser($user);

            return $user;
        }

        return false;
    }

    /**
     * 用户是否使用了“记住我”
     * @return bool
     */
    public function viaRemember()
    {
        return $this->viaRemember;
    }

    /**
     * 登出
     * @return void
     */
    public function logout()
    {
        $user = $this->user();

        $this->clearUserDataFromStorage();

        if (!is_null($this->user)) {
            $this->refreshRememberToken($user);
        }

        $this->user = null;

        $this->loggedOut = true;
    }

    public function basic($field = 'email', $extraConditions = [])
    {
        if ($this->check()) {
            return;
        }

        if ($this->attemptBasic($this->getRequest(), $field, $extraConditions)) {
            return;
        }

        return $this->failedBasicResponse();
    }

    public function onceBasic($field = 'email', $extraConditions = [])
    {
        $credentials = $this->basicCredentials($this->getRequest(), $field);

        if (!$this->once(array_merge($credentials, $extraConditions))) {
            return $this->failedBasicResponse();
        }
    }

    protected function attemptBasic(Request $request, $field, $extraConditions = [])
    {
        if (!$request->getUser()) {
            return false;
        }

        return $this->attempt(array_merge(
            $this->basicCredentials($request, $field), $extraConditions
        ));
    }

    protected function basicCredentials(Request $request, $field)
    {
        return [$field => $request->getUser(), 'password' => $request->getPassword()];
    }

    protected function failedBasicResponse()
    {
        return new Response('Invalid credentials.', 401, ['WWW-Authenticate' => 'Basic']);
    }

    protected function clearUserDataFromStorage()
    {
        \think\Session::delete($this->getName());

        if (!is_null($this->getRecaller())) {
            $recaller = $this->getRecallerName();
            Cookie::delete($recaller);
        }
    }

    protected function hasValidCredentials($user, $credentials)
    {
        return !is_null($user) && $this->provider->validateCredentials($user, $credentials);
    }

    protected function createRememberTokenIfDoesntExist(Authenticatable $user)
    {
        if (empty($user->getRememberToken())) {
            $this->refreshRememberToken($user);
        }
    }

    protected function refreshRememberToken(Authenticatable $user)
    {
        $user->setRememberToken($token = Str::random(60));

        $this->provider->updateRememberToken($user, $token);
    }

    protected function createRecaller(Authenticatable $user)
    {
        $value = $user->getAuthId() . '|' . $user->getRememberToken();
        Cookie::forever($this->getRecallerName(), $value);
    }

    protected function getRequest()
    {
        return Request::instance();
    }
}