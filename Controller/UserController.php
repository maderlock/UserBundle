<?php

/**
 * (c) Thibault Duplessis <thibault.duplessis@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace FOS\UserBundle\Controller;

use Symfony\Component\Security\Acl\Permission\MaskBuilder;
use Symfony\Component\Security\Acl\Domain\UserSecurityIdentity;
use Symfony\Component\Security\Acl\Domain\ObjectIdentity;
use Symfony\Component\DependencyInjection\ContainerAware;
use Symfony\Component\HttpFoundation\RedirectResponse;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use FOS\UserBundle\Model\User;
use FOS\UserBundle\Form\ChangePassword;
use FOS\UserBundle\Form\ResetPassword;

/**
 * RESTful controller managing user CRUD
 */
class UserController extends ContainerAware
{
    /**
     * Show all users
     */
    public function listAction()
    {
        $users = $this->container->get('fos_user.user_manager')->findUsers();

        return $this->container->get('templating')->renderResponse('FOSUserBundle:User:list.html.'.$this->getEngine(), array('users' => $users));
    }

    /**
     * Show one user
     */
    public function showAction($username)
    {
        $user = $this->findUserBy('username', $username);
        return $this->container->get('templating')->renderResponse('FOSUserBundle:User:show.html.'.$this->getEngine(), array('user' => $user));
    }

    /**
     * Edit one user, show the edit form
     */
    public function editAction($username)
    {
        $user = $this->findUserBy('username', $username);
        $form = $this->container->get('fos_user.form.user');
        $form->setData($user);

        return $this->container->get('templating')->renderResponse('FOSUserBundle:User:edit.html.'.$this->getEngine(), array(
            'form'      => $form,
            'username'  => $user->getUsername()
        ));
    }

    /**
     * Update a user
     */
    public function updateAction($username)
    {
        $user = $this->findUserBy('username', $username);
        $form = $this->container->get('fos_user.form.user');
        $form->bind($this->container->get('request'), $user);

        if ($form->isValid()) {
            $this->container->get('fos_user.user_manager')->updateUser($user);
            $this->setFlash('fos_user_user_update', 'success');
            $userUrl =  $this->container->get('router')->generate('fos_user_user_show', array('username' => $user->getUsername()));
            return new RedirectResponse($userUrl);
        }

        return $this->container->get('templating')->renderResponse('FOSUserBundle:User:edit.html.'.$this->getEngine(), array(
            'form'      => $form,
            'username'  => $user->getUsername()
        ));
    }

    /**
     * Show the new form
     */
    public function newAction()
    {
        $user = $this->container->get('fos_user.user_manager')->createUser();
        $form = $this->container->get('fos_user.form.user');
        $form->setData($user);

        return $this->container->get('templating')->renderResponse('FOSUserBundle:User:new.html.'.$this->getEngine(), array(
            'form' => $form
        ));
    }

    /**
     * Create a user and send a confirmation email
     */
    public function createAction()
    {
        $manager = $this->container->get('fos_user.user_manager');
        $user = $manager->createUser();
        $form = $this->container->get('fos_user.form.user');
        $form->setData($user);

        $request = $this->container->get('request');

        if ('POST' == $request->getMethod()) {
            $values = $request->request->get($form->getName(), array());
            $files = $request->files->get($form->getName(), array());

            $form->submit(array_replace_recursive($values, $files));

            $manager->updateCanonicalFields($user);
            $form->validate();
        }

        if ($form->isValid()) {
            if ($this->container->getParameter('fos_user.email.confirmation.enabled')) {
                $user->setEnabled(false);
                $manager->updateUser($user);
                $this->sendConfirmationEmailMessage($user);
                $this->container->get('session')->set('fos_user_send_confirmation_email/email', $user->getEmail());
                $url =  $this->container->get('router')->generate('fos_user_user_check_confirmation_email');
            } else {
                $user->setConfirmationToken(null);
                $user->setEnabled(true);
                $manager->updateUser($user);
                $this->authenticateUser($user);
                $url =  $this->container->get('router')->generate('fos_user_user_confirmed');
            }

            if ($this->container->has('security.acl.provider')) {
                $provider = $this->container->get('security.acl.provider');
                $acl = $provider->createAcl(ObjectIdentity::fromDomainObject($user));
                $acl->insertObjectAce(UserSecurityIdentity::fromAccount($user), MaskBuilder::MASK_OWNER);
                $provider->updateAcl($acl);
            }

            $this->setFlash('fos_user_user_create', 'success');
            return new RedirectResponse($url);
        }

        return $this->container->get('templating')->renderResponse('FOSUserBundle:User:new.html.'.$this->getEngine(), array(
            'form' => $form
        ));
    }

    /**
     * Tell the user to check his email provider
     */
    public function checkConfirmationEmailAction()
    {
        $email = $this->container->get('session')->get('fos_user_send_confirmation_email/email');
        $this->container->get('session')->remove('fos_user_send_confirmation_email/email');
        $user = $this->findUserBy('email', $email);

        $this->setFlash('fos_user_user_confirm', 'success');

        return $this->container->get('templating')->renderResponse('FOSUserBundle:User:checkConfirmationEmail.html.'.$this->getEngine(), array(
            'user' => $user,
        ));
    }

    /**
     * Receive the confirmation token from user email provider, login the user
     */
    public function confirmAction($token)
    {
        $user = $this->findUserBy('confirmationToken', $token);
        $user->setConfirmationToken(null);
        $user->setEnabled(true);

        $this->container->get('fos_user.user_manager')->updateUser($user);
        $this->authenticateUser($user);

        return new RedirectResponse( $this->container->get('router')->generate('fos_user_user_confirmed'));
    }

    /**
     * Tell the user his account is now confirmed
     */
    public function confirmedAction()
    {
        $user = $this->getUser();
        $this->setFlash('fos_user_user_confirmed', 'success');
        return $this->container->get('templating')->renderResponse('FOSUserBundle:User:confirmed.html.'.$this->getEngine(), array(
            'user' => $user,
        ));
    }

    /**
     * Delete one user
     */
    public function deleteAction($username)
    {
        $user = $this->findUserBy('username', $username);
        $this->container->get('fos_user.user_manager')->deleteUser($user);
        $this->setFlash('fos_user_user_delete', 'success');

        return new RedirectResponse( $this->container->get('router')->generate('fos_user_user_list'));
    }

    /**
     * Change user password: show form
     */
    public function changePasswordAction()
    {
        $user = $this->getUser();
        $form = $this->createChangePasswordForm($user);

        return $this->container->get('templating')->renderResponse('FOSUserBundle:User:changePassword.html.'.$this->getEngine(), array(
            'form' => $form
        ));
    }

    /**
     * Change user password: submit form
     */
    public function changePasswordUpdateAction()
    {
        $user = $this->getUser();
        $form = $this->createChangePasswordForm($user);
        $form->bind($this->container->get('request'));

        if ($form->isValid()) {
            $user->setPlainPassword($form->getNewPassword());
            $this->container->get('fos_user.user_manager')->updateUser($user);
            $this->setFlash('fos_user_user_password', 'success');

            $userUrl =  $this->container->get('router')->generate('fos_user_user_show', array('username' => $user->getUsername()));

            return new RedirectResponse($userUrl);
        }

        return $this->container->get('templating')->renderResponse('FOSUserBundle:User:changePassword.html.'.$this->getEngine(), array(
            'form' => $form
        ));
    }

    /**
     * Request reset user password: show form
     */
    public function requestResetPasswordAction()
    {
        return $this->container->get('templating')->renderResponse('FOSUserBundle:User:requestResetPassword.html.'.$this->getEngine());
    }

    /**
     * Request reset user password: submit form and send email
     */
    public function sendResettingEmailAction()
    {
        $user = $this->findUserBy('username', $this->container->get('request')->get('username'));

        if ($user->isPasswordRequestNonExpired($this->getPasswordRequestTtl())) {
            return $this->container->get('templating')->renderResponse('FOSUserBundle:User:passwordAlreadyRequested.html.'.$this->getEngine());
        }

        $user->generateConfirmationToken();
        $user->setPasswordRequestedAt(new \DateTime());
        $this->container->get('fos_user.user_manager')->updateUser($user);
        $this->container->get('session')->set('fos_user_send_resetting_email/email', $user->getEmail());
        $this->sendResettingEmailMessage($user);

        return new RedirectResponse( $this->container->get('router')->generate('fos_user_user_check_resetting_email'));
    }

    /**
     * Tell the user to check his email provider
     */
    public function checkResettingEmailAction()
    {
        $email = $this->container->get('session')->get('fos_user_send_resetting_email/email');
        $this->container->get('session')->remove('fos_user_send_resetting_email/email');
        $user = $this->findUserBy('email', $email);
        $this->setFlash('fos_user_user_reset', 'success');

        return $this->container->get('templating')->renderResponse('FOSUserBundle:User:checkResettingEmail.html.'.$this->getEngine(), array(
            'user' => $user,
        ));
    }

    /**
     * Reset user password: show form
     */
    public function resetPasswordAction($token)
    {
        $user = $this->findUserBy('confirmationToken', $token);

        if (!$user->isPasswordRequestNonExpired($this->getPasswordRequestTtl())) {
            new RedirectResponse( $this->container->get('router')->generate('fos_user_user_request_reset_password'));
        }

        $form = $this->createResetPasswordForm($user);

        return $this->container->get('templating')->renderResponse('FOSUserBundle:User:resetPassword.html.'.$this->getEngine(), array(
            'token' => $token,
            'form' => $form
        ));
    }

    /**
     * Reset user password: submit form
     */
    public function resetPasswordUpdateAction($token)
    {
        $user = $this->findUserBy('confirmationToken', $token);

        if (!$user->isPasswordRequestNonExpired($this->getPasswordRequestTtl())) {
            new RedirectResponse( $this->container->get('router')->generate('fos_user_user_request_reset_password'));
        }

        $form = $this->createResetPasswordForm($user);
        $form->bind($this->container->get('request'));

        if ($form->isValid()) {
            $user->setPlainPassword($form->getNewPassword());
            $user->setConfirmationToken(null);
            $user->setEnabled(true);
            $this->container->get('fos_user.user_manager')->updateUser($user);
            $this->authenticateUser($user);
            $userUrl =  $this->container->get('router')->generate('fos_user_user_show', array('username' => $user->getUsername()));
            $this->setFlash('fos_user_user_resetted', 'success');

            return new RedirectResponse($userUrl);
        }

        return $this->container->get('templating')->renderResponse('FOSUserBundle:User:resetPassword.html.'.$this->getEngine(), array(
            'token' => $token,
            'form' => $form
        ));
    }

    /**
     * Get a user from the security context
     *
     * @throws AccessDeniedException if no user is authenticated
     * @return User
     */
    protected function getUser()
    {
        $user = $this->container->get('security.context')->getToken()->getUser();
        if (!$user) {
            throw new AccessDeniedException('A logged in user is required.');
        }

        return $user;
    }

    /**
     * Find a user by a specific property
     *
     * @param string $key property name
     * @param mixed $value property value
     * @throws NotFoundException if user does not exist
     * @return User
     */
    protected function findUserBy($key, $value)
    {
        if (!empty($value)) {
            $user = $this->container->get('fos_user.user_manager')->{'findUserBy'.ucfirst($key)}($value);
        }

        if (empty($user)) {
            throw new NotFoundHttpException(sprintf('The user with "%s" does not exist for value "%s"', $key, $value));
        }

        return $user;
    }

    /**
     * Authenticate a user with Symfony Security
     *
     * @param Boolean $reAuthenticate
     * @return null
     */
    protected function authenticateUser(User $user, $reAuthenticate = false)
    {
        $providerKey = $this->container->getParameter('fos_user.provider_key');
        $token = new UsernamePasswordToken($user, null, $providerKey, $user->getRoles());

        if (true === $reAuthenticate) {
            $token->setAuthenticated(false);
        }

        $this->container->get('security.context')->setToken($token);
    }

    protected function createChangePasswordForm(User $user)
    {
        $form = $this->container->get('fos_user.form.change_password');
        $form->setData(new ChangePassword($user));

        return $form;
    }

    protected function createResetPasswordForm(User $user)
    {
        $form = $this->container->get('fos_user.form.reset_password');
        $form->setData(new ResetPassword($user));

        return $form;
    }

    protected function sendConfirmationEmailMessage(User $user)
    {
        $template = $this->container->getParameter('fos_user.email.confirmation.template');
        $rendered = $this->renderView($template.'.txt.'.$this->getEngine(), array(
            'user' => $user,
            'confirmationUrl' =>  $this->container->get('router')->generate('fos_user_user_confirm', array('token' => $user->getConfirmationToken()), true)
        ));
        $this->sendEmailMessage($rendered, $this->getSenderEmail('confirmation'), $user->getEmail());
    }

    protected function sendResettingEmailMessage(User $user)
    {
        $template = $this->container->getParameter('fos_user.email.resetting_password.template');
        $rendered = $this->renderView($template.'.txt.'.$this->getEngine(), array(
            'user' => $user,
            'confirmationUrl' =>  $this->container->get('router')->generate('fos_user_user_reset_password', array('token' => $user->getConfirmationToken()), true)
        ));
        $this->sendEmailMessage($rendered, $this->getSenderEmail('resetting_password'), $user->getEmail());
    }

    protected function sendEmailMessage($renderedTemplate, $fromEmail, $toEmail)
    {
        // Render the email, use the first line as the subject, and the rest as the body
        $renderedLines = explode("\n", trim($renderedTemplate));
        $subject = $renderedLines[0];
        $body = implode("\n", array_slice($renderedLines, 1));

        $mailer = $this->container->get('mailer');

        $message = \Swift_Message::newInstance()
            ->setSubject($subject)
            ->setFrom($fromEmail)
            ->setTo($toEmail)
            ->setBody($body);

        $mailer->send($message);
    }

    protected function setFlash($action, $value)
    {
        $this->container->get('session')->setFlash($action, $value);
    }

    protected function getPasswordRequestTtl()
    {
        return $this->container->getParameter('fos_user.email.resetting_password.token_ttl');
    }

    protected function getSenderEmail($type)
    {
        return $this->container->getParameter('fos_user.email.from_email');
    }

    protected function getEngine()
    {
        return $this->container->getParameter('fos_user.template.engine');
    }
}
