<?php
/**
 * Stakhanovist
 *
 * @link        https://github.com/stakhanovist/worker
 * @copyright   Copyright (c) 2015, Stakhanovist <stakhanovist@leonardograsso.com>
 * @license     http://opensource.org/licenses/BSD-2-Clause Simplified BSD License
 */

namespace Stakhanovist\Worker;

use Zend\Mvc\Controller\AbstractController;
use Zend\Mvc\MvcEvent;
use Zend\Http\Request;
use Zend\Http\Client;
use Zend\Stdlib\MessageInterface;
use Zend\Serializer\Adapter\AdapterInterface as SerializerAdapter;
use Zend\Serializer\Serializer;
use Zend\View\Model\ConsoleModel;
use ZendQueue\Exception;
use ZendQueue\Queue;
use ZendQueue\Parameter\ReceiveParameters;
use ZendQueue\Controller\Message\Forward;
use ZendQueue\Controller\Message\WorkerMessageInterface;
use ZendQueue\Controller\Message\WorkerExit;
use Zend\Mvc\InjectApplicationEventInterface;
use Zend\Mvc\Router\RouteMatch;
use Stakhanovist\Worker\ProcessStrategy\ForwardProcessorStrategy;
use Stakhanovist\Worker\Processor\ForwardProcessor;
use Stakhanovist\Worker\Processor\ProcessorInterface;


/**
 * Base worker class
 *
 */
abstract class AbstractWorkerController extends AbstractController
{

    /**
     * @var ProcessEvent
     */
    protected $processEvent;


    /**
     * @var SerializerAdapter
     */
    protected $serializer;


    /**
     * @param SerializerAdapter $adapter
     * @return AbstractWorkerController
     */
    public function setSerializer(SerializerAdapter $adapter)
    {
        $this->serializer = $adapter;
        return $this;
    }

    /**
     * @return SerializerAdapter
     */
    public function getSerializer()
    {
        if (null === $this->serializer) {
            $this->serializer = Serializer::factory('PhpSerialize');
        }
        return $this->serializer;
    }

    /**
     * Execute the request
     *
     * @param  MvcEvent $e
     * @return mixed
     * @throws Exception\DomainException
     */
    public function onDispatch(MvcEvent $e)
    {
        $routeMatch = $e->getRouteMatch();
        if (!$routeMatch) {
            /**
             * @todo Determine requirements for when route match is missing.
             *       Potentially allow pulling directly from request metadata?
             */
            throw new \Zend\Mvc\Exception\DomainException('Missing route matches; unsure how to retrieve action');
        }


        $this->registerDefaultProcessStrategy(); //TEMP


        $action = $routeMatch->getParam('action', null);

        if ($action) {
            $action = strtolower($action);
        }

        switch ($action) {
            case 'process':
                $message = $routeMatch->getParam('message', null);

                if(is_string($message)) {
                    $message = $this->getSerializer()->unserialize(base64_decode($message));
                }

                if($message instanceof MessageInterface) {
                    $result = $this->process($message);
                } else {
                    $result = $this->createConsoleErrorModel('Missing or invalid message');
                }
                break;

            case 'receive':
            case 'await':

                $queue = $routeMatch->getParam('queue', null);

                if (is_string($queue)) {
                    $queue = $this->getServiceLocator()->get($queue);
                }

                if (!$queue instanceof \ZendQueue\Queue) {
                    throw new \InvalidArgumentException('Invalid queue param type: must be a string or an instace of \ZendQueue\Queue');
                }

                $recvParams = $routeMatch->getParam('receiveParameters', null);

                if ($recvParams === null) {
                    $params = null;
                } else if ($recvParams instanceof ReceiveParameters) {
                    $params = $recvParams;
                } else if (is_string($recvParams)) {
                    $params = new ReceiveParameters();
                    $params->fromString($recvParams);
                } else if (is_array($recvParams)) {
                    $params = new ReceiveParameters();
                    $params->fromArray($recvParams);
                } else {
                    throw new \InvalidArgumentException('Invalid receiveParameters param type: must be null, an array, a string or an instace of \ZendQueue\Queue');
                }

                $result = $this->{$action}($queue, $params);
                break;

            default:
                $result = $this->createConsoleErrorModel('Invalid action');
        }


        $e->setResult($result);

        return $result;
    }


    protected function registerDefaultProcessStrategy()
    {
        $this->getEventManager()->attach(
            new ForwardProcessorStrategy(new ForwardProcessor()),
            100
        );
    }


    /**
     * @param ProcessEvent $e
     * @return AbstractWorkerController
     */
    public function setProcessEvent(ProcessEvent $e)
    {
        $this->processEvent = $e;
        return $this;
    }

    /**
     * @return ProcessEvent
     */
    public function getProcessEvent()
    {
        if (null === $this->processEvent) {
            $this->setProcessEvent(new ProcessEvent());
            $this->processEvent->setTarget($this);
        }
        return $this->processEvent;
    }

    /**
     * Process a message
     *
     * @param MessageInterface $message
     * @return mixed
     * @throws Exception\InvalidMessageException
     */
    public function process(MessageInterface $message)
    {

        $event   = $this->getProcessEvent();
        $event->setWorker($this);
        $event->setMessage($message);

        $events  = $this->getEventManager();
        $results = $events->trigger(ProcessEvent::EVENT_PROCESSOR, $event, function ($result) {
            return ($result instanceof ProcessorInterface);
        });
        $processor = $results->last();
        if (!$processor instanceof ProcessorInterface) {
            throw new \RuntimeException(sprintf(
                '%s: no processor selected!',
                __METHOD__
            ));
        }

        $processor->setWorker($this);
        $event->setProcessor($processor);

        $result = $processor->process($message);
        $event->setResult($result);

        $events->trigger(ProcessEvent::EVENT_PROCESS_POST, $event);


        return $result;
    }


    /**
     * Receive and process just one incoming message
     *
     * @return mixed
     */
    public function receive(Queue $queue, ReceiveParameters $params = null)
    {
        $message = $queue->receive($this->params('maxMessages', 1), $params)->current();

        if ($message instanceof MessageInterface) {

            $response = $this->process($message);

            if ($queue->canDeleteMessage()) {
                $queue->deleteMessage($message);
            }

            return $response;
        }
    }

    /**
     * Wait for and process incoming messages
     *
     * @return mixed
     */
    public function await(Queue $queue, ReceiveParameters $params = null)
    {
        $worker = $this;
        $await = true;
        $lastResult = array();

        $handler = function(MessageInterface $message) use($worker, $queue, &$await, &$lastResult) {

            $lastResult = $worker->process($message);

            if ($queue->canDeleteMessage()) {
                $queue->deleteMessage($message);
            }

            return $await;
        };


        $queue->await($params, $handler);

        return $lastResult;
    }


    /**
     * Create a console view model representing an error
     *
     * @return ConsoleModel
     */
    protected function createConsoleErrorModel($errorMsg)
    {
        $viewModel = new ConsoleModel();
        $viewModel->setErrorLevel(1);
        $viewModel->setResult($errorMsg);
        return $viewModel;
    }

}