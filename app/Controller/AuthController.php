<?php
App::uses('HttpSocket', 'Network/Http');
class AuthController extends AppController {   
    public $components = array('Emailing'); 
    var $helpers = array();
    var $uses = array('Ticket');

    var $userRequired = false;
    var $bridge = false;

    public function beforeFilter() {
        parent::beforeFilter();

        $this->layout = 'login';

        $this->bridge = json_decode($this->Setting->getOption('bridge'));
        $this->set('bridge', $this->bridge);
    }

    public function index() {
        $this->redirect('/auth/login');
    }

    public function login() {
        $this->pageTitle = __('Login to MushRaider').' - '.$this->pageTitle;

        if($this->user) {            
            $this->redirect('/');
        }

        if(!$this->Session->check('User.id')) {
            if($user = $this->Cookie->read('User')) {
                return $this->cookieLogin($user);
            }
        }

        if(!empty($this->request->data['User'])) {            
            if(!empty($this->bridge) && $this->bridge->enabled && !empty($this->bridge->url) && !empty($this->bridge->secret)) {
                $iv_size = mcrypt_get_iv_size(MCRYPT_BLOWFISH, MCRYPT_MODE_ECB);
                $iv = mcrypt_create_iv($iv_size, MCRYPT_RAND);
                $pwd = mcrypt_encrypt(MCRYPT_BLOWFISH, $this->bridge->secret, utf8_encode($this->request->data['User']['password']), MCRYPT_MODE_ECB, $iv);

                $HttpSocket = new HttpSocket();
                $auth = $HttpSocket->post($this->bridge->url, array('login' => $this->request->data['User']['login'], 'pwd' => $pwd));
                $auth = json_decode($auth->body);
                if(empty($auth) || !$auth->authenticated) {
                    $this->Session->setFlash(__('MushRaider can\'t find your account, maybe you need some sleep ?'), 'flash_warning');
                    unset($this->request->data['User']);
                }else {
                    $params = array();
                    $params['fields'] = array('id', 'username', 'email', 'password');
                    $params['conditions']['or']['username'] = $this->request->data['User']['login'];
                    $params['conditions']['or']['email'] = $this->request->data['User']['login'];
                    $params['conditions']['password'] = md5($this->request->data['User']['password']);
                    $params['conditions']['status'] = array(1, 0);
                    if($user = $this->User->find('first', $params)) {
                        $toSave = array();
                        $toSave['id'] = $user['User']['id'];
                        $toSave['status'] = 1;
                        $toSave['bridge'] = 1;
                        $this->User->save($toSave);

                    }else {
                        $user = array();
                        $user['User']['username'] = $this->request->data['User']['login'];
                        $user['User']['email'] = $auth->email;
                        $user['User']['password'] = md5($this->request->data['User']['password']);
                        $user['User']['verify_password'] = md5($this->request->data['User']['password']);
                        $user['User']['status'] = 1;
                        $user['User']['bridge'] = 1;
                        $user['User']['role_id'] = $this->Role->getIdByAlias('member');
                        $this->User->save($user['User']);
                        $user['User']['id'] = $this->User->getLastInsertId();
                    }

                    if(empty($this->request->data['User']['remember'])) {
                        $this->Cookie->delete('User');
                    }else{
                        $cookie = array();
                        $cookie['username'] = $user['User']['username'];
                        $cookie['password'] = $user['User']['password'];
                        $this->Cookie->write('User', $cookie, true, '+2 weeks');
                    }
                    $this->Session->write('User.id', $user['User']['id']);
                    $this->Session->setFlash(__('Congratulation %s, you are now logged in', $user['User']['username']), 'flash_success');

                    if($this->Session->check('redirectFrom')) {
                        $redirect = $this->Session->read('redirectFrom');
                        $this->Session->delete('redirectFrom');
                        $this->redirect($redirect);
                    }else {
                        $this->redirect('/');
                    }
                }
            }else {
                $params = array();
                $params['fields'] = array('id', 'username', 'email', 'password');
                $params['conditions']['or']['username'] = $this->request->data['User']['login'];
                $params['conditions']['or']['email'] = $this->request->data['User']['login'];
                $params['conditions']['password'] = md5($this->request->data['User']['password']);
                if($user = $this->User->find('first', $params)) {
                    if(empty($this->request->data['User']['remember'])) {
                        $this->Cookie->delete('User');
                    }else{
                        $cookie = array();
                        $cookie['username'] = $user['User']['username'];
                        $cookie['password'] = $user['User']['password'];
                        $this->Cookie->write('User', $cookie, true, '+2 weeks');
                    }
                    $this->Session->write('User.id', $user['User']['id']);
                    $this->Session->setFlash(__('Congratulation %s, you are now logged in', $user['User']['username']), 'flash_success');

                    if($this->Session->check('redirectFrom')) {
                        $redirect = $this->Session->read('redirectFrom');
                        $this->Session->delete('redirectFrom');
                        $this->redirect($redirect);
                    }else {
                        $this->redirect('/');
                    }
                }else {
                    $params['conditions']['status'] = 0;
                    if($user = $this->User->find('first', $params)) {
                        $this->Session->setFlash(__('You have to wait until an admin activate your account, go farm while waiting !'), 'flash_warning');
                    }else {
                        $this->Session->setFlash(__('MushRaider can\'t find your account, maybe you need some sleep ?'), 'flash_warning');
                    }
                    unset($this->request->data['User']);
                }
            }
        }
    }

    public function signup() {
        if(!empty($this->bridge) && $this->bridge->enabled) {
            $this->Session->setFlash(__('Signup are disabled because bridge system is enabled.'), 'flash_warning');
            $this->redirect('/auth/login');
        }

        $this->pageTitle = __('Signup to MushRaider').' - '.$this->pageTitle;

        if(!empty($this->request->data['User'])) {
            $toSave = array();
            $toSave['username'] = $this->request->data['User']['username'];
            $toSave['email'] = $this->request->data['User']['email'];
            $toSave['password'] = md5($this->request->data['User']['password']);
            $toSave['verify_password'] = md5($this->request->data['User']['verify_password']);
            $toSave['status'] = 0;
            $toSave['role_id'] = $this->Role->getIdByAlias('member');
            if($this->User->save($toSave)) {
                $this->Session->setFlash(__('Yeah, your account has been created, but now you need to wait until your account as been validated by an admin to access the raid planner (security stuff).'), 'flash_success');
                $this->redirect('/account');
            }

            $this->Session->setFlash(__('Something wrong happen, please fix the errors below'), 'flash_error');            
        }
    }

    public function recovery() {
        if(!empty($this->bridge) && $this->bridge->enabled) {
            $this->Session->setFlash(__('Password recovery is disabled because bridge system is enabled.'), 'flash_warning');
            $this->redirect('/auth/login');
        }

        $this->pageTitle = __('Password Recovery').' - '.$this->pageTitle;

        if(!empty($this->request->data['User']) && !empty($this->request->data['User']['email'])) {
            $email = trim($this->request->data['User']['email']);
            if($hash = $this->Ticket->__create($email, 'pwd')) {
                $this->Emailing->recovery($email, $hash);
                $this->Session->setFlash(__('An email has been sent to you with the recovery instructions.'), 'flash_success');
            }
            $this->redirect('/auth/login');
        }        
    }

    public function password($hash = null) {
        if(!$hash) {
            $this->redirect('/');
        }
        $this->pageTitle = __('Password Recovery').' - '.$this->pageTitle;

        if(!$ticket = $this->Ticket->__getByHash($hash, 'pwd')) {
            $this->Session->setFlash(__('This link is no longer available.'), 'flash_error');
            $this->redirect('/');
        }

        if(!empty($this->request->data['User']) && !empty($this->request->data['User']['password'])) {
            // Get the user
            $params = array();
            $params['fields'] = array('id');
            $params['recursive'] = -1;
            $params['conditions']['email'] = $ticket['Ticket']['email'];
            if($user = $this->User->find('first', $params)) {
                $toUpdate = array();
                $toUpdate['id'] = $user['User']['id'];
                $toUpdate['password'] = md5($this->request->data['User']['password']);
                $toUpdate['verify_password'] = md5($this->request->data['User']['verify_password']);
                if($this->User->save($toUpdate)) {
                    $this->Session->setFlash(__('Your password has been updated.'), 'flash_success');
                    $this->redirect('/auth/login');
                }
                $this->Session->setFlash(__('Something wrong happen, please fix the errors below'), 'flash_error');            
            }else {
                $this->Session->setFlash(__('This link is no longer available.'), 'flash_error');
                $this->redirect('/');
            }
        }
    }

    public function logout() {
        $this->Session->delete('User');
        $this->Session->destroy();
        $this->Cookie->delete('User');
        $this->redirect('/auth/login');
    }

    private function cookieLogin($user) {
        $params = array();
        $params['recursive'] = -1;
        $params['fields'] = array('id');
        $params['conditions']['username'] = $user['username'];
        $params['conditions']['password'] = $user['password'];
        if(!$user = $this->User->find('first', $params)) {
            $this->Cookie->delete('User');
        }else{
            $this->Session->write('User.id', $user['User']['id']);
            $this->redirect($this->request->here);
        }
    }
}