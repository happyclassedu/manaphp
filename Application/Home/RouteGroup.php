<?php
namespace Application\Home;

use Application\Home\Controllers\IndexController;
use ManaPHP\Mvc\Router\Group;

class RouteGroup extends Group
{
    public function __construct()
    {
        parent::__construct(true);
        $this->add('/about', 'Index::about');
        $this->addGet('/about1', 'Index::about');
        $this->addGet('/about2', [IndexController::class, 'about']);
        $this->addGet('/about3', ['controller' => IndexController::class, 'action' => 'about']);
    }
}