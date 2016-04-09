<?php
namespace Topxia\WebBundle\Controller;

use Topxia\Service\Common\Mail;
use Topxia\Service\User\CurrentUser;
use Topxia\Service\Common\ServiceKernel;
use Topxia\Service\Common\AccessDeniedException;
use Topxia\Service\CloudPlatform\CloudAPIFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Http\SecurityEvents;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

abstract class BaseController extends Controller
{
    /**
     * 获得当前用户
     *
     * 如果当前用户为游客，那么返回id为0, nickanme为"游客", currentIp为当前IP的CurrentUser对象。
     * 不能通过empty($this->getCurrentUser())的方式来判断用户是否登录。
     */
    protected function getCurrentUser()
    {
        return $this->getUserService()->getCurrentUser();
    }

    protected function isAdminOnline()
    {
        return $this->get('security.context')->isGranted('ROLE_ADMIN');
    }

    public function getUser()
    {
        throw new \RuntimeException('获得当前登录用户的API变更为：getCurrentUser()。');
    }

    /**
     * 创建消息提示响应
     *
     * @param  string     $type     消息类型：info, warning, error
     * @param  string     $message  消息内容
     * @param  string     $title    消息抬头
     * @param  integer    $duration 消息显示持续的时间
     * @param  string     $goto     消息跳转的页面
     * @return Response
     */
    protected function createMessageResponse($type, $message, $title = '', $duration = 0, $goto = null)
    {
        if (!in_array($type, array('info', 'warning', 'error'))) {
            throw new \RuntimeException('type不正确');
        }

        return $this->render('TopxiaWebBundle:Default:message.html.twig', array(
            'type'     => $type,
            'message'  => $message,
            'title'    => $title,
            'duration' => $duration,
            'goto'     => $goto
        ));
    }

    protected function createMessageModalResponse($type, $message, $title = '', $duration = 0, $goto = null)
    {
        return $this->render('TopxiaWebBundle:Default:message-modal.html.twig', array(
            'type'     => $type,
            'message'  => $message,
            'title'    => $title,
            'duration' => $duration,
            'goto'     => $goto
        ));
    }

    //TODO 即将删除
    protected function authenticateUser($user)
    {
        $user['currentIp'] = $this->container->get('request')->getClientIp();
        $currentUser       = new CurrentUser();
        $currentUser->fromArray($user);

        ServiceKernel::instance()->setCurrentUser($currentUser);

        $token = new UsernamePasswordToken($currentUser, null, 'main', $currentUser['roles']);
        $this->container->get('security.context')->setToken($token);

        $loginEvent = new InteractiveLoginEvent($this->getRequest(), $token);
        $this->get('event_dispatcher')->dispatch(SecurityEvents::INTERACTIVE_LOGIN, $loginEvent);

        ServiceKernel::instance()->createService("System.LogService")->info('user', 'login_success', '登录成功');

        $loginBind = $this->setting('login_bind', array());

        if (empty($loginBind['login_limit'])) {
            return;
        }

        $sessionId = $this->container->get('request')->getSession()->getId();
        $this->getUserService()->rememberLoginSessionId($user['id'], $sessionId);
    }

    protected function setFlashMessage($level, $message)
    {
        $this->get('session')->getFlashBag()->add($level, $message);
    }

    protected function setting($name, $default = null)
    {
        return $this->get('topxia.twig.web_extension')->getSetting($name, $default);
    }

    protected function isPluginInstalled($name)
    {
        return $this->get('topxia.twig.web_extension')->isPluginInstalled($name);
    }

    protected function createNamedFormBuilder($name, $data = null, array $options = array())
    {
        return $this->container->get('form.factory')->createNamedBuilder($name, 'form', $data, $options);
    }

    protected function sendEmail(Mail $mail)
    {
        $config      = $this->setting('mailer', array());
        $cloudConfig = $this->setting('cloud_email', array());

        if (isset($cloudConfig['status']) && $cloudConfig['status'] == 'enable') {
            return $this->sendCloudEmail($mail->getCloudMail());
        } elseif (isset($config['enabled']) && $config['enabled'] == 1) {
            return $this->sendNormalEmail($mail->getMail());
        }

        return false;
    }

    private function sendCloudEmail($cloudMail)
    {
        $cloudConfig = $this->setting('cloud_email', array());

        if (isset($cloudConfig['status']) && $cloudConfig['status'] == 'enable') {
            $api    = CloudAPIFactory::create('leaf');
            $site   = $this->setting('site', array());
            $params = array(
                'to'       => $cloudMail['to'],
                'template' => $cloudMail['template'],
                'params'   => array(
                    'sitename'  => $site['name'],
                    'nickname'  => $cloudMail['nickname'],
                    'verifyurl' => $cloudMail['verifyurl'],
                    'siteurl'   => $site['url']
                )
            );
            $result = $api->post("/emails", $params);

            return true;
        }

        return false;
    }

    private function sendNormalEmail($params)
    {
        $format = isset($params['format']) && $params['format'] == 'html' ? 'text/html' : 'text/plain';

        $config = $this->setting('mailer', array());

        if (isset($config['enabled']) && $config['enabled'] == 1) {
            $transport = \Swift_SmtpTransport::newInstance($config['host'], $config['port'])
                ->setUsername($config['username'])
                ->setPassword($config['password']);

            $mailer = \Swift_Mailer::newInstance($transport);

            $email = \Swift_Message::newInstance();
            $email->setSubject($params['title']);
            $email->setFrom(array($config['from'] => $config['name']));
            $email->setTo($params['to']);

            if ($format == 'text/html') {
                $email->setBody($params['body'], 'text/html');
            } else {
                $email->setBody($params['body']);
            }

            $mailer->send($email);
            return true;
        }

        return false;
    }

    protected function createJsonResponse($data)
    {
        return new JsonResponse($data);
    }

    protected function getTargetPath($request)
    {
        if ($request->query->get('goto')) {
            $targetPath = $request->query->get('goto');
        } elseif ($request->getSession()->has('_target_path')) {
            $targetPath = $request->getSession()->get('_target_path');
        } else {
            $targetPath = $request->headers->get('Referer');
        }

        if ($targetPath == $this->generateUrl('login', array(), true)) {
            return $this->generateUrl('homepage');
        }

        $url = explode('?', $targetPath);

        if ($url[0] == $this->generateUrl('partner_logout', array(), true)) {
            return $this->generateUrl('homepage');
        }

        if ($url[0] == $this->generateUrl('password_reset_update', array(), true)) {
            $targetPath = $this->generateUrl('homepage', array(), true);
        }

        if (strpos($url[0], $request->getPathInfo()) > -1) {
            $targetPath = $this->generateUrl('homepage', array(), true);
        }

        if ($url[0] == $this->generateUrl('login_bind_callback', array('type' => 'weixinmob'))
            || $url[0] == $this->generateUrl('login_bind_callback', array('type' => 'weixinweb'))
            || $url[0] == $this->generateUrl('login_bind_callback', array('type' => 'qq'))
            || $url[0] == $this->generateUrl('login_bind_callback', array('type' => 'weibo'))
            || $url[0] == $this->generateUrl('login_bind_callback', array('type' => 'renren'))
            || $url[0] == $this->generateUrl('login_bind_choose', array('type' => 'qq'))
            || $url[0] == $this->generateUrl('login_bind_choose', array('type' => 'weibo'))
            || $url[0] == $this->generateUrl('login_bind_choose', array('type' => 'renren'))
            || $url[0] == $this->generateUrl('common_crontab')

        ) {
            $targetPath = $this->generateUrl('homepage');
        }

        return $targetPath;
    }

    /**
     * JSONM
     * https://github.com/lifesinger/lifesinger.github.com/issues/118
     */
    protected function createJsonmResponse($data)
    {
        $response = new JsonResponse($data);
        $response->setCallback('define');
        return $response;
    }

    protected function createAccessDeniedException($message = null)
    {
        if ($message) {
            return new AccessDeniedException($message);
        } else {
            return new AccessDeniedException();
        }
    }

    protected function agentInWhiteList($userAgent)
    {
        $whiteList = array("iPhone", "iPad", "Android", "HTC");

        foreach ($whiteList as $value) {
            if (strpos($userAgent, $value) > -1) {
                return true;
            }
        }

        return false;
    }

    protected function getServiceKernel()
    {
        return ServiceKernel::instance();
    }

    protected function getUserService()
    {
        return $this->getServiceKernel()->createService('User.UserService');
    }

    protected function getLogService()
    {
        return $this->getServiceKernel()->createService('System.LogService');
    }
}
