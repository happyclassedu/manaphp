<?php
namespace ManaPHP\Cli;

/**
 * Class ManaPHP\Cli\Application
 *
 * @package application
 *
 * @property \ManaPHP\Cli\ConsoleInterface $console
 * @property \ManaPHP\Cli\RouterInterface  $cliRouter
 */
class Application extends \ManaPHP\Application
{
    /**
     * @var array
     */
    protected $_args;

    /**
     * @var array
     */
    protected $_controllerAliases = [];

    /**
     * Application constructor.
     *
     * @param \ManaPHP\Loader      $loader
     * @param \ManaPHP\DiInterface $dependencyInjector
     */
    public function __construct($loader, $dependencyInjector = null)
    {
        parent::__construct($loader, $dependencyInjector);

        foreach (['@app/Cli/Controllers', '@app/Controllers', '@app'] as $dir) {
            if ($this->filesystem->dirExists($dir)) {
                $this->alias->set('@cli', $this->alias->resolve($dir));
                $this->alias->set('@ns.cli', $this->alias->resolveNS(strtr($dir, ['@app' => '@ns.app', '/' => '\\'])));
                break;
            }
        }

        $this->_dependencyInjector->setShared('console', 'ManaPHP\Cli\Console');
        $this->_dependencyInjector->setShared('arguments', 'ManaPHP\Cli\Arguments');
        $this->_dependencyInjector->setShared('cliRouter', 'ManaPHP\Cli\Router');

        $this->_dependencyInjector->remove('url');
    }

    /**
     * @param array $args
     *
     * @return int
     * @throws \ManaPHP\Cli\Application\Exception
     */
    public function handle($args = null)
    {
        $this->_args = $args ?: $GLOBALS['argv'];

        if (!$this->cliRouter->route($this->_args)) {
            $this->console->writeLn('command name is invalid: ' . implode(' ', $this->_args));
            return 1;
        }

        $controllerName = $this->cliRouter->getControllerName();
        $actionName = lcfirst($this->cliRouter->getActionName());

        $controllerClassName = null;

        foreach (['@ns.cli', 'ManaPHP\\Cli\\Controllers'] as $prefix) {
            $class = $this->alias->resolveNS($prefix . '\\' . $controllerName . 'Controller');

            if (class_exists($class)) {
                $controllerClassName = $class;
                break;
            }
        }

        if (!$controllerClassName) {
            $this->console->writeLn('``:command` command is not exists'/**m0d7fa39c3a64b91e0*/, ['command' => lcfirst($controllerName) . ':' . $actionName]);
            return 1;
        }

        $controllerInstance = $this->_dependencyInjector->getShared($controllerClassName);

        $actionMethod = $actionName . 'Command';
        if (!method_exists($controllerInstance, $actionMethod)) {
            $this->console->writeLn('`:command` sub command is not exists'/**m061a35fc1c0cd0b6f*/, ['command' => lcfirst($controllerName) . ':' . $actionName]);
            return 1;
        }

        $r = $controllerInstance->$actionMethod();

        return is_int($r) ? $r : 0;
    }

    public function main()
    {
        $this->registerServices();

        exit($this->handle());
    }
}