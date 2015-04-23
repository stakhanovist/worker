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
use Zend\Mvc\MvcEvent;
use Stakhanovist\Worker\ProcessStrategy\ForwardProcessorStrategy;
use Stakhanovist\Worker\Processor\ForwardProcessor;
use Zend\Stdlib\Parameters;
use Zend\Stdlib\Message;


class ConsoleWorkerController extends AbstractWorkerController
{

    /**
     * @var SerializerAdapterInterface|null
     */
    protected $serializer;

    protected static $hasPcntl = false;

    /**
     *
     */
    public function __construct()
    {
        if (extension_loaded('pcntl')) {
            static::$hasPcntl = true;
            pcntl_signal(SIGTERM, [$this, 'pcntlSignalHandler']);
        }
    }

    /**
     * @param unknown $signo
     */
    public function pcntlSignalHandler($signo)
    {
        switch ($signo) {
            case SIGTERM:
                $this->stopAwaiting();
                break;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isAwaitingStopped()
    {
        if (static::$hasPcntl) {
            // TODO: could be handled with QueueEvent::EVENT_IDLE
            pcntl_signal_dispatch();
        }
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
            if ($message) {
                if (is_string($message)) {
                    $parameter = new Parameters();
                    $parameter->fromString($message);
                    $message = new Message();
                    $message->setContent($parameter->get('content'));
                    $message->setMetadata($parameter->get('metadata', []));
                    $routeMatch->setParam('message', $message);
                }
            } else {
                stream_set_blocking(STDIN, 0);
                $stdin = file_get_contents('php://stdin');
                $message = $this->getSerializer()->unserialize($stdin);
                $routeMatch->setParam('message', $message);
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