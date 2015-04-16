<?php
/**
 * Stakhanovist
 *
 * @link        https://github.com/stakhanovist/worker
 * @copyright   Copyright (c) 2015, Stakhanovist <stakhanovist@leonardograsso.com>
 * @license     http://opensource.org/licenses/BSD-2-Clause Simplified BSD License
 */

namespace Stakhanovist\Worker;

use Zend\Mvc\Service\SerializerAdapter;
use Zend\Serializer\Adapter\AdapterInterface as SerializerAdapterInterface;
use Zend\Serializer\Serializer;
use Zend\Mvc\Router\RouteMatch;
use Zend\Stdlib\RequestInterface;
use Zend\Stdlib\ResponseInterface;
use Zend\Console\Request as ConsoleRequest;


class ConsoleWorkerController extends AbstractWorkerController
{

    /**
     * @var SerializerAdapterInterface|null
     */
    protected $serializer;

    /**
     *
     */
    public function __construct()
    {
        pcntl_signal(SIGTERM, [$this, 'pcntlSignalHandler']);
    }

    /**
     * @param unknown $signo
     */
    public function pcntlSignalHandler($signo)
    {
        switch ($signo) {
            case SIGTERM:
                $this->await = false;
                break;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isAwaitingStopped()
    {
        pcntl_signal_dispatch();
        return parent::isAwaitingStopped();
    }

    /**
     * @param SerializerAdapterInterface $adapter
     * @return $this
     */
    public function setSerializer(SerializerAdapterInterface $adapter)
    {
        $this->serializer = $adapter;
        return $this;
    }

    /**
     * @return SerializerAdapterInterface
     */
    public function getSerializer()
    {
        if (null === $this->serializer) {
            $this->serializer = Serializer::factory('PhpSerialize');
        }
        return $this->serializer;
    }


    /**
     * {@inheritdoc}
     */
    public function onDispatch(MvcEvent $e)
    {
        $this->registerDefaultProcessStrategy(); //TEMP

        $routeMatch = $e->getRouteMatch();
        if ($routeMatch instanceof RouteMatch) {
            $message = $routeMatch->getParam('message', null);
            if ($message && is_string($message)) {
                $routeMatch->setParam(
                    'message',
                    $this->getSerializer()->unserialize(base64_decode($message))
                );
            }
        }

        return parent::onDispatch($e);
    }

    /**
     * {@inheritdoc}
     */
    public function dispatch(RequestInterface $request, ResponseInterface $response = null)
    {
        if (! $request instanceof ConsoleRequest) {
            throw new InvalidArgumentException(sprintf(
                '%s can only dispatch requests in a console environment',
                get_called_class()
            ));
        }
        return parent::dispatch($request, $response);
    }

    /**
     *
     */
    protected function registerDefaultProcessStrategy()
    {
        $this->getEventManager()->attach(
            new ForwardProcessorStrategy(new ForwardProcessor()),
            100
        );
    }

}