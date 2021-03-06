<?php
/**
 * @link      http://fraym.org
 * @author    Dominik Weber <info@fraym.org>
 * @copyright Dominik Weber <info@fraym.org>
 * @license   http://www.opensource.org/licenses/gpl-license.php GNU General Public License, version 2 or later (see the LICENSE file)
 */
namespace Fraym;

/**
 * Class Core
 * @package Fraym
 * @Injectable(lazy=true)
 * @property $view
 */
class Core
{
    const VERSION = '0.9.1';
    const NAME = 'Fraym';
    const AUTHOR = 'Dominik Weber';
    const PUBLISHED = '2014';
    const WEBSITE = 'http://fraym.org';

    const ENV_PRODUCTION = 'production';
    const ENV_STAGING = 'staging';
    const ENV_TESTING = 'testing';
    const ENV_DEVELOPMENT = 'development';

    const ROUTE_NORMAL = 0;
    const ROUTE_CUSTOM = 1;

    /**
     * Eval code
     */
    private $evalCode = null;

    /**
     * @var array
     */
    private $timerStartTime = array();

    /**
     * @var array
     */
    private $timerStartTimeByKey = array();

    /**
     * @var bool
     */
    private $applicationDir = false;

    /**
     * @var int
     */
    private $mode = self::ROUTE_NORMAL;

    /**
     * @Inject
     * @var \Fraym\Route\Route
     */
    public $route;

    /**
     * @Inject
     * @var \Fraym\Template\Template
     */
    public $template;

    /**
     * @Inject
     * @var \Fraym\Response\Response
     */
    public $response;

    /**
     * @Inject
     * @var \Fraym\Session\Session
     */
    public $session;

    /**
     * @Inject
     * @var \Fraym\Request\Request
     */
    public $request;

    /**
     * @Inject
     * @var \Fraym\Registry\RegistryManager
     */
    public $registryManager;

    /**
     * @Inject
     * @var \Fraym\Cache\Cache
     */
    protected $cache;

    /**
     * @param $code
     * @param bool $withPhpTags
     * @param null $errorHanlder
     * @return string
     */
    public function evalString($code, $withPhpTags = true, $errorHanlder = null)
    {
        ob_start();

        if ($errorHanlder) {
            set_error_handler($errorHanlder);
        }

        $this->evalCode = $code;

        if ($withPhpTags === true) {
            $data = eval('?>' . $this->evalCode . '<?php ');
        } else {
            $data = eval($this->evalCode);
        }

        if ($errorHanlder) {
            restore_error_handler();
        }

        $this->evalCode = null;

        if ($data !== null) {
            echo $data;
        }
        return ob_get_clean();
    }

    /**
     * @return mixed
     */
    public function getEvalCode()
    {
        return $this->evalCode;
    }

    /**
     * @param $method
     * @return mixed
     */
    public function __get($method)
    {
        $method = 'get' . ucfirst($method);
        if (method_exists($this, $method)) {
            return $this->$method();
        }
    }

    /**
     * @return \Fraym\Template\Template
     */
    public function getView()
    {
        $parent_class = get_called_class();
        $tpl_instance = $this->template;
        $tpl_instance->setView($parent_class);
        return $tpl_instance;
    }

    /**
     * @static
     * @return void
     */
    public function getPageLoadTime()
    {
        return $this->stopTimer(__CLASS__);
    }

    /**
     * @param bool $key
     * @return $this
     */
    public function startTimer($key = false)
    {
        $gentime = microtime(true);

        if ($key) {
            $this->timerStartTimeByKey[$key] = $gentime;
        } else {
            array_push($this->timerStartTime, $gentime);
        }
        return $this;
    }

    /**
     * @param bool $key
     * @return string
     */
    public function stopTimer($key = false)
    {
        $totaltime = 0;
        $pg_end = microtime(true);
        if (isset($this->timerStartTimeByKey[$key])) {
            $totaltime = (floatval($pg_end) - floatval($this->timerStartTimeByKey[$key]));
            unset($this->timerStartTimeByKey[$key]);
        } elseif ($key === false) {
            $totaltime = (floatval($pg_end) - floatval(array_pop($this->timerStartTime)));
        }

        return number_format($totaltime, 4, '.', '');
    }

    /**
     * @return bool
     */
    public function isCLI()
    {
        if (php_sapi_name() == 'cli' && empty($_SERVER['REMOTE_ADDR'])) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * @return bool
     */
    public function getApplicationDir()
    {
        return $this->applicationDir ? : getcwd();
    }

    /**
     * @param int $mode
     */
    public function init($mode = Core::ROUTE_NORMAL)
    {

        $globalConfigs = array(
            'ENV' => self::ENV_DEVELOPMENT,
            'JS_FOLDER' => '/js',
            'CSS_FOLDER' => '/css',
            'CONSOLIDATE_FOLDER' => '/consolidated',
            'PROFILER_ENABLED' => false,
        );

        foreach ($globalConfigs as $config => $val) {
            if (!defined($config)) {
                define($config, $val);
            }
        }

        if (ENV == self::ENV_DEVELOPMENT) {
            error_reporting(E_ALL | E_STRICT);
            ini_set("display_errors", 1);
        }

        if (is_object($this->session) && $this->isCLI() === false) {
            $this->session->start();
        }

        $this->mode = $mode;

        $this->startTimer(__CLASS__);

        // Assign our output handler to output clean errors
        ob_start();

        // ingore user abort, is recommed for ajax request and db changes
        if ($this->request->isXmlHttpRequest()) {
            ignore_user_abort(true);
        }
        $this->registryManager->init();

        if ($this->isCLI() === false) {
            // Sanitize all request variables
            $_GET = $this->sanitize($_GET);
            $_POST = $this->sanitize($_POST);
            $_COOKIE = $this->sanitize($_COOKIE);

            if ($mode === Core::ROUTE_NORMAL) {

                $this->cache->load();

                // Try to load a site by the requested URI
                $this->route->loadSite();

                if (!$this->request->isXmlHttpRequest() && !$this->request->isPost()) {
                    echo '<!-- ' . $this->getPageLoadTime() . 'sec -->';
                }

                $this->response->finish(false, true);
            }
        } else {
            $params = getopt('p:');

            if ($params['p']) {
                $_SERVER['REQUEST_URI'] = $params['p'];
                $this->route->loadVirtualRoutes();
                echo $this->route->checkVirtualRoute();
            }
        }
    }

    /**
     * @param $className
     * @return mixed
     */
    public function createDirNameFromClassName($className)
    {
        $className = trim($className, '\\');
        return str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, substr($className, 0, strrpos($className, '\\')));
    }

    /**
     * @param $module_name
     * @return bool|string
     */
    public function getModulePathByNamespace($module_name)
    {
        $dir = realpath(__DIR__ . DIRECTORY_SEPARATOR . '..') .
            DIRECTORY_SEPARATOR . $module_name . DIRECTORY_SEPARATOR;

        return is_dir($dir) ? $dir : false;
    }

    /**
     * @static
     * @param  $value
     * @return mixed|string
     */
    public function sanitize($value)
    {
        if (is_array($value) || is_object($value)) {
            foreach ($value as $key => $val) {
                $value[$key] = $this->sanitize($val);
            }
        } elseif (is_string($value)) {
            if ((bool)get_magic_quotes_gpc() === true) {
                $value = stripslashes($value);
            }

            if (strpos($value, "\r") !== false) {
                $value = str_replace(array("\r\n", "\r"), "\n", $value);
            }
        }

        return $value;
    }
}
