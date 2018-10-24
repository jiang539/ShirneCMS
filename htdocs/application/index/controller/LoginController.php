<?php

namespace app\index\controller;

use app\common\model\MemberModel;
use app\common\model\MemberOauthModel;
use app\common\validate\MemberValidate;
use sdk\OAuthFactory;
use think\Db;
use think\Exception;
use think\facade\Log;

/**
 * 用户本地登陆和第三方登陆
 */
class LoginController extends BaseController{



    public function initialize()
    {
        parent::initialize();
        $this->assign('navmodel','member');
    }

    public function index($type=0)
    {
        if($this->userid){
            $this->success('您已登录',url('index/member/index'));
        }
        //方式1：本地账号登陆
        if(empty($type)){
            if($this->request->isPost()){
                $code = $this->request->post('verify','','strtolower');
                //验证验证码是否正确
                if(!($this->check_verify($code))){
                    $this->error('验证码错误');
                }

                $data['username'] = $this->request->post('username');
                $password = $this->request->post('password');
                $member = Db::name('member')->where($data)->find();
                if(!empty($member) && $member['password']==encode_password($password,$member['salt'])){

                    if($member['status']==0){
                        user_log($member['id'], 'login', 0, '账号已禁用' );
                        $this->error("您的账号已禁用");
                    }else {
                        setLogin($member);
                        $redirect=redirect()->restore();
                        if(empty($redirect->getData())){
                            $url=url('index/member/index');
                        }else{
                            $url=$redirect->getTargetUrl();
                        }

                        if(!empty($this->wechatUser)){
                            Db::name('memberOauth')->where('openid',$this->wechatUser['openid'])
                                ->update(['member_id'=>$member['id']]);
                        }

                        $this->success("登陆成功",$url);
                    }
                }else{
                    user_log($member['id'],'login',0,'密码错误:'.$password);
                    $this->error("账号或密码错误");
                }
            }
            return $this->fetch();
        }else {
            $app = Db::name('OAuth')->where('id|type',$type)->find();
            if (empty($app)) {
                if($type=='wechat'){
                    $authid=Db::name('OAuth')->insert([
                        'title'=>'微信登录',
                        'type'=>'wechat',
                        'appid'=>$this->config['appid'],
                        'appkey'=>$this->config['appsecret']
                    ]);
                    $app = Db::name('OAuth')->find($authid);
                }else {
                    $this->error("不允许使用此方式登陆");
                }
            }

            $callbackurl = url('index/login/callback', ['type' => $app['id']], true,true);

            // 使用第三方登陆
            $oauth = OAuthFactory::getInstence($app['type'], $app['appid'], $app['appkey'], $callbackurl);
            $url=$oauth->redirect();

            return redirect($url->getTargetUrl());
        }
    }

    //登录回调地址
    public function callback($type = null, $code = null) 
    {
      
        if(empty($type) || empty($code)){
            $this->error('参数错误');  
        }
        $app = Db::name('OAuth')->find(['id'=>$type]);
        $oauth=OAuthFactory::getInstence($app['type'], $app['appid'], $app['appkey']);
        try {
            $userInfo = $oauth->user();
            $data['openid'] = $userInfo['id'];
            $data['nickname'] =$userInfo['nickname'];
            $data['name'] =$userInfo['name'];
            $data['email'] =$userInfo['email'];
            $data['avatar'] =$userInfo['avatar'];

            $origin=$userInfo->getOriginal();
            $data['gender'] = empty($origin['gender'])?0:$origin['gender'];
            $data['unionid'] = empty($origin['unionid'])?'':$origin['unionid'];
            $data['data']=json_encode($origin);
            $data['type'] = $app['type'];
            $data['type_id'] = $type;
            if(!empty($userInfo['unionid'])){
                $sameAuth=MemberOauthModel::get(['unionid'=>$userInfo['unionid']]);
                if(!empty($sameAuth)){
                    $data['member_id']=$sameAuth['member_id'];
                }
            }
            $model = MemberOauthModel::get(['openid' => $data['openid']]);
            if (empty($model)) {
                if (empty($data['member_id'])) {
                    if($this->config['m_register']!='1') {
                        $member = MemberModel::create([
                            'username' => $data['openid'],
                            'realname' => $data['nickname'],
                            'avatar' => $data['avatar'],
                            'referer'=>0
                        ]);
                        $data['member_id'] = $member['id'];
                    }
                }
                MemberOauthModel::create($data);
                $model = MemberOauthModel::get(['openid' => $data['openid']]);
            } else {
                unset($data['member_id']);
                $model->save($data);
            }
            session('openid',$data['openid']);
            if($model['member_id']) {
                $member = Db::name('Member')->find($model['member_id']);
                //更新昵称和头像
                if(!empty($model['avatar']) &&
                    (empty($member['avatar']) || is_wechat_avatar($member['avatar']))
                ){
                    Db::name('member')->where('id',$member['id'])->update(
                        [
                            'nickname'=>$model['nickname'],
                            'avatar'=>$model['avatar']
                        ]
                    );
                }

                setLogin($member);
            }
        }catch(Exception $e){
            $this->error('登录失败',url('index/index/index'));
        }
        return redirect()->restore();
    }

    public function getpassword(){
        $step=$this->request->get('step/d',1);
        $username=$this->request->post('username');
        $authtype=$this->request->post('authtype');

        if($step==2 || $step==3){
            $step--;
            if(empty($username))$this->error("请填写用户名");
            if(empty($authtype))$this->error("请选择认证方式");
            $user=Db::name('member')->where('username',$username)->find();
            if(empty($user)){
                $this->error("该用户不存在");
            }
            if(empty($user[$authtype.'_bind']))$this->error("认证方式无效");

            switch ($authtype){
                case 'email':
                    $this->assign('sendtoname',"邮箱");
                    break;
                case 'mobile':
                    $this->assign('sendtoname',"手机");
                    break;
            }
            $step++;
        }
        if($step==3){
            $step--;
            $sendto=$this->request->post('sendto');
            $code=$this->request->post('checkcode');
            $crow=Db::name('checkcode')->where(array('sendto'=>$sendto,'checkcode'=>$code,'is_check',0))->order('create_time DESC')->find();
            $time=time();
            if(!empty($crow) && $crow['create_time']>$time-60*5){
                Db::name('checkcode')->where('id' , $crow['id'])->update(array('is_check' => 1, 'check_at' => $time));
                session('passed',$username);
            }else{
                $this->error("验证码已失效");
            }


            $step++;
        }

        if($step==4){
            $step--;
            $passed=session('passed');
            if(empty($passed)){
                $this->error("非法操作");
            }
            $password=$this->request->post('password');
            $repassword=$this->request->post('repassword');

            if(empty($password))$this->error("请填写密码");
            if(strlen($password)<6 || strlen($password)>20)$this->error("密码长度 6-20");

            if($password != $repassword){
                $this->error("两次密码输入不一致");
            }
            $data['salt'] = random_str(8);
            $data['password'] = encode_password($password, $data['salt']);
            $data['update_time'] = time();
            if (Db::name('member')->where('username',$passed)->update($data)) {
                $this->success("密码设置成功",url('index/login/index'));
            }
        }

        $this->assign('username',$username);
        $this->assign('authtype',$authtype);
        $this->assign('step',$step);
        $this->assign('nextstep',$step+1);
        return $this->fetch();
    }
    public function checkusername(){
        Log::close();
        $username=$this->request->post('username');
        if(empty($username))$this->error("请填写用户名");
        $user=Db::name('member')->where('username',$username)->find();
        if(empty($user)){
            $this->error("该用户不存在");
        }
        $types=array();
        if($user['email_bind'])$types[]='email';
        if($user['mobile_bind'])$types[]='mobile';
        if(empty($types)) {
            $this->error("您的帐户未绑定任何有效资料，请联系客服处理。");
        }else{
            $this->success('', '',$types);
        }
    }

    public function register($agent=''){
        $this->seo("会员注册");

        if(!empty($agent)){
            $amem=Db::name('Member')->where(array('is_agent'=>1,'agentcode'=>$agent))->find();
            if(!empty($amem)){
                session('agent',$amem['id']);
            }
        }

        if($this->request->isPost()){
            $data=$this->request->only('username,password,repassword,email,realname,mobile,mobilecheck','post');

            $validate=new MemberValidate();
            $validate->setId();
            if(!$validate->scene('register')->check($data)){
                $this->error($validate->getError());
            }

            $invite_code=$this->request->post('invite_code');
            if(($this->config['m_invite']==1 && !empty($invite_code)) || $this->config['m_invite']==2) {
                if (empty($invite_code)) $this->error("请填写激活码");
                $invite = Db::name('invite_code')->where(array('code' => $invite_code, 'is_lock' => 0, 'member_use' => 0))->find();
                if (empty($invite) || ($invite['invalid_at'] > 0 && $invite['invalid_at'] < time())) {
                    $this->error("激活码不正确");
                }
            }

            if($this->config['sms_code'] ) {
                if (empty($data['mobilecheck'])) {
                    $this->error(' 请填写手机验证码');
                }
                $verifyed=$this->verify_checkcode($data['mobile'],$data['mobilecheck']);
                if(!$verifyed){
                    $this->error(' 手机验证码填写错误');
                }
                unset($data['mobilecheck']);
            }

            Db::startTrans();
            if(!empty($invite)) {
                $invite = Db::name('invite_code')->lock(true)->find($invite['id']);
                if (!empty($invite['member_use'])) {
                    Db::rollback();
                    $this->error("激活码已被使用");
                }
                $data['referer']=$invite['member_id'];
                if($invite['level_id']){
                    $data['level_id']=$invite['level_id'];
                }else{
                    $data['level_id']=getDefaultLevel();
                }
            }else{
                $data['referer']=session('agent');
                $data['level_id']=getDefaultLevel();
            }
            $data['salt']=random_str(8);
            $data['password']=encode_password($data['password'],$data['salt']);
            $data['login_ip']=$this->request->ip();

            unset($data['repassword']);
            $model=MemberModel::create($data);

            if(empty($model['id'])){
                Db::rollback();
                $this->error("注册失败");
            }
            if(!empty($invite)) {
                $invite['member_use'] = $model['id'];
                $invite['use_at'] = time();
                Db::name('invite_code')->update($invite);
            }
            if(!empty($this->wechatUser)){
                Db::name('memberOauth')->where('openid',$this->wechatUser['openid'])
                    ->update(['member_id'=>$model['id']]);
            }
            Db::commit();
            setLogin($model);
            $redirect=redirect()->restore();
            if(empty($redirect->getData())){
                $url=url('index/member/index');
            }else{
                $url=$redirect->getTargetUrl();
            }
            $this->success("注册成功",$url);
        }else{
            $this->assign('nocode',$this->config['m_invite']<1);
            return $this->fetch();
        }
    }

    public function checkunique($type='username'){
        Log::close();
        if(!in_array($type,array('username','email','mobile'))){
            $this->error('参数不合法');
        }
        $member=Db::name('member');
        $val=$this->request->get('value');
        $m=$member->where($type,$val)->find();
        $json=array();
        $json['error']=0;
        if(!empty($m))$json['error']=1;
        return json($json);
    }

    public function send_checkcode($mobile,$code){

        //图形验证码
        if(!$this->check_verify($code)){
            $this->error('验证码错误');
        }

        //号码格式验证
        if(!preg_match('/^1[2-9]\d{9}$/',$mobile)){
            $this->error('手机号码格式错误');
        }

        //已注册验证
        $member=Db::name('member')->where('mobile',$mobile)->find();
        if(!empty($member)){
            $this->error('该手机号码已注册');
        }

        //发送限制
        $ip_address_limit=10;
        $phone_limit=5;
        $second_limit=120;

        //根据ip地址限制发送次数
        $ip=$this->request->ip();
        $ipcount=Db::name('checkcodeLimit')->where('type','ip')
            ->where('key',$ip)->find();
        if(empty($ipcount)){
            Db::name('checkcodeLimit')->insert([
                'type'=>'ip',
                'key'=>$ip,
                'create_time'=>time(),
                'count'=>1
            ]);
        }else{
            if($ipcount['create_time']<strtotime(date('Y-m-d'))){
                Db::name('checkcodeLimit')->where('type','ip')->where('key',$ip)
                    ->update(['create_time'=>time(),'count'=>1]);
            }else{
                if($ipcount['count']>=$ip_address_limit){
                    $this->error('验证码发送过于频繁');
                }
                Db::name('checkcodeLimit')->where('type','ip')->where('key',$ip)
                    ->setInc('count',1);
            }
        }

        //根据手机号码限制发送次数
        $phonecount=Db::name('checkcodeLimit')->where('type','mobile')
            ->where('key',$mobile)->find();
        if(empty($phonecount)){
            Db::name('checkcodeLimit')->insert([
                'type'=>'mobile',
                'key'=>$mobile,
                'create_time'=>time(),
                'count'=>1
            ]);
        }else{
            if($phonecount['create_time']<strtotime(date('Y-m-d'))){
                Db::name('checkcodeLimit')->where('type','mobile')->where('key',$mobile)
                    ->update(['create_time'=>time(),'count'=>1]);
            }else{
                if($phonecount['count']>=$phone_limit){
                    $this->error('验证码发送过于频繁');
                }
                Db::name('checkcodeLimit')->where('type','mobile')->where('key',$mobile)
                    ->setInc('count',1);
            }
        }

        $exist=Db::name('Checkcode')->where('type',0)
            ->where('sendto',$mobile)
            ->where('is_check',0)->find();
        $newcode=random_str(6, 'number');
        if(empty($exist)) {
            $data = [
                'type' => 0,
                'sendto' => $mobile,
                'code' => $newcode,
                'create_time' => time(),
                'is_check' => 0
            ];
            Db::name('Checkcode')->insert($data);
        }else{

            //验证发送时间间隔
            if(time()-$exist['create_time']<$second_limit){
                $this->error('验证码发送过于频繁');
            }

            Db::name('Checkcode')->where('type',0)->where('sendto',$mobile)
                ->update(['code'=>$newcode,'create_time'=>time()]);
        }
        @session_write_close();
        $content="您本次验证码为{$newcode}仅用于会员注册。请在10分钟内使用！";
        $sms=new UmsHttp($this->config);
        $sended=$sms->send($mobile,$content);
        if($sended) {
            $this->success('验证码发送成功！');
        }else{
            $this->error($sms->errMsg);
        }
    }
    protected function verify_checkcode($mobile,$code){
        $checkcode=Db::name('Checkcode')->where('type',0)
            ->where('sendto',$mobile)
            ->where('is_check',0)
            ->where('code',$code)->find();
        if(empty($checkcode)){
            return false;
        }else{
            Db::name('Checkcode')->where('type',0)
                ->where('sendto',$mobile)
                ->where('is_check',0)
                ->update(['is_check'=>1,'check_at'=>time()]);
            return true;
        }
    }

    public function verify(){
        $verify = new \think\captcha\Captcha(array('seKey'=>config('session.sec_key')));
        //$Verify->codeSet = '0123456789';
        $verify->fontSize = 13;
        $verify->length = 4;
        return $verify->entry('foreign');
    }
    protected function check_verify($code){
        $verify = new \think\captcha\Captcha(array('seKey'=>config('session.sec_key')));
        return $verify->check($code,'foreign');
    }

    public function logout()
    {
        clearLogin();
        $this->success("已成功退出登陆");

    }

    /**
     * 忘记密码
     */
    public function forgot()
    {
        return $this->fetch();
    }
}
