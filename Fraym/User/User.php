<?php
/**
 * @link      http://fraym.org
 * @author    Dominik Weber <info@fraym.org>
 * @copyright Dominik Weber <info@fraym.org>
 * @license   http://www.opensource.org/licenses/gpl-license.php GNU General Public License, version 2 or later (see the LICENSE file)
 */
namespace Fraym\User;

/**
 * Class User
 * @package Fraym\User
 * @Injectable(lazy=true)
 */
class User
{
    /**
     * @var bool
     */
    private $isLoggedIn = false;

    /**
     * @var bool
     */
    private $userId = false;

    /**
     * @var bool
     */
    private $user = false;

    /**
     * @var null|bool
     */
    private $isAdmin = null;

    /**
     * @var \Fraym\Database\Database
     */
    protected $db;

    /**
     * @var \Fraym\Session\Session
     */
    protected $session;

    /**
     * @Inject
     * @var \Fraym\Request\Request
     */
    protected $request;

    /**
     * @Inject
     * @var \Fraym\Template\Template
     */
    protected $template;

    /**
     * @Inject
     * @var \Fraym\Block\BlockParser
     */
    protected $blockParser;

    /**
     * @Inject
     * @var \Fraym\User\UserController
     */
    protected $userController;

    /**
     * @param $methodName
     * @param $parameters
     * @return mixed
     */
    public function __call($methodName, $parameters)
    {
        $userEntity = $this->getUserEntity();
        return call_user_func_array(array($userEntity, $methodName), $parameters);
    }

    /**
     * @param $prop
     * @return null
     */
    public function __get($prop)
    {
        $userEntity = $this->getUserEntity();
        return $userEntity ? $userEntity->$prop : null;
    }

    /**
     * @return mixed
     */
    public function getUserEntity()
    {
        if ($this->db) {
            return $this->user = $this->db->getRepository('\Fraym\User\Entity\User')->findOneById($this->userId);
        }
        return null;
    }

    /**
     * Checks the session if the user is logged in.
     *
     * @Inject
     * @param \Fraym\Database\Database $db
     * @param \Fraym\Session\Session $session
     */
    public function __construct(\Fraym\Database\Database $db, \Fraym\Session\Session $session)
    {
        // call connect on caching
        $this->db = $db->connect();
        $this->session = $session;
        $userId = $this->session->get('userId', false);

        if ($this->user === false && $userId !== false) {
            $this->setUserId($userId);
            $this->session->addOnDestroyCallback(array(&$this, 'setUserAsOffline'));
        }
    }

    /**
     * @return $this
     */
    public function logout()
    {
        $user = $this->db->getRepository('\Fraym\User\Entity\User')->findOneById($this->userId);
        if ($user) {
            $this->isLoggedIn = true; // set logged in to true to render the logout
            $this->user = false;
            $this->userId = false;

            $user->isOnline = false;
            $this->db->persist($user);
            $this->db->flush();
            $this->session->destroy();
        }
        return $this;
    }

    /**
     * Return true if a user is logged in.
     *
     * @return bool
     */
    public function isLoggedIn()
    {
        return $this->isLoggedIn;
    }

    /**
     * @param $email
     * @param $password
     * @param bool $staySignedIn
     * @return bool
     */
    public function login($email, $password, $staySignedIn = false) {

        $user = $this->db->getRepository('\Fraym\User\Entity\User')->findOneBy(array('email' => $email));
        if ($user && $user->verifyPassword($password)) {
            $this->setUserId($user->id);

            if ($staySignedIn) {
                $this->session->setCookieParams(strtotime('+30 days'), '/');
            }
            return $user;
        }
        return false;
    }

    /**
     * @param $userId
     * @return $this
     */
    public function setUserId($userId)
    {
        $this->user = $this->db->getRepository('\Fraym\User\Entity\User')->findOneById($userId);

        if (!$this->user) {
            $this->session->destroy();
            return $this;
        }

        $this->userId = $userId;
        $this->session->set('userId', $this->userId);

        if ($this->user) {
            $this->isLoggedIn = true;
            $this->user->isOnline = true;
            $this->user->lastLogin = new \DateTime();
            $this->db->persist($this->user);
            $this->db->flush();
        }

        return $this;
    }

    /**
     * @return bool
     */
    public function isAdmin()
    {
        if($this->isAdmin === null && $this->userId) {
            $adminGroupIdentifier = $this->db->getRepository('\Fraym\Registry\Entity\Config')->findOneByName(
                'ADMIN_GROUP_IDENTIFIER'
            );
            if(!$adminGroupIdentifier) {
                throw new \Exception('Registry config entry "ADMIN_GROUP_IDENTIFIER" not found!');
            }
            $adminGroup = $this->db->getRepository('\Fraym\User\Entity\Group')->findOneByIdentifier(
                $adminGroupIdentifier->value
            );

            if ($adminGroup) {
                $this->isAdmin = $this->getUserEntity()->groups->contains($adminGroup);
            }
        }
        return $this->isAdmin ? true : false;
    }

    /**
     * @param $blockId
     * @param \Fraym\Block\BlockXML $blockXML
     * @return \Fraym\Block\BlockXML
     */
    public function saveBlockConfig($blockId, \Fraym\Block\BlockXML $blockXML)
    {
        $blockConfig = $this->request->getGPAsObject();
        $customProperties = new \Fraym\Block\BlockXMLDom();
        $element = $customProperties->createElement('view');
        $element->appendChild($customProperties->createCDATASection($blockConfig->view));
        $customProperties->appendChild($element);

        $blockXML->setCustomProperty($customProperties);
        return $blockXML;
    }

    /**
     * @param $xml
     * @return string
     */
    public function execBlock($xml)
    {
        if ((string)$xml->view === 'login-logout') {
            return $this->userController->renderForm($xml);
        }
        $this->template->setTemplate("string:");
    }

    /**
     * @param null $blockId
     */
    public function getBlockConfig($blockId = null)
    {
        $configXml = null;
        if ($blockId) {
            $block = $this->db->getRepository('\Fraym\Block\Entity\Block')->findOneById($blockId);
            $configXml = $this->blockParser->getXMLObjectFromString($this->blockParser->wrapBlockConfig($block));
        }
        $this->userController->getBlockConfig($configXml);
    }
}
