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
use Zend\Stdlib\MessageInterface;
use Zend\View\Model\ConsoleModel;
use Stakhanovist\Queue\Exception;
use Stakhanovist\Queue\Queue;
use Stakhanovist\Queue\Parameter\ReceiveParameters;
use Zend\Mvc\InjectApplicationEventInterface;
use Zend\Mvc\Router\RouteMatch;
use Stakhanovist\Worker\ProcessStrategy\ForwardProcessorStrategy;
use Stakhanovist\Worker\Processor\ForwardProcessor;
use Stakhanovist\Worker\Processor\ProcessorInterface;
use Stakhanovist\Queue\QueueClientInterface;
use Stakhanovist\Queue\Parameter\ReceiveParametersInterface;


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
     *
     * @var bool
     */
    protected $await;

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

        $action = $routeMatch->getParam('action', null);

        if ($action) {
            $action = strtolower($action);
        }

        switch ($action) {
            case 'process':
                $message = $routeMatch->getParam('message', null);

                if($message instanceof MessageInterface) {
                    $result = $this->process($message);
                } else {
                    throw new \InvalidArgumentException(
                        'Missing or invalid message type: must be an instace of '. MessageInterface::class
                    );
                }
                break;

            case 'receive':
            case 'await':

                $queue = $routeMatch->getParam('queue', null);

                if (is_string($queue)) {
                    $queue = $this->getServiceLocator()->get($queue);
                }

                if (!$queue instanceof QueueClientInterface) {
                    throw new \InvalidArgumentException(
                        'Invalid queue param type: must be a string or an instace of '. QueueClientInterface::class
                    );
                }

                $recvParams = $routeMatch->getParam('receiveParameters', null);

                if ($recvParams === null) {
                    $params = null;
                } else if ($recvParams instanceof ReceiveParametersInterface) {
                    $params = $recvParams;
                } else if (is_string($recvParams)) {
                    $params = new ReceiveParameters();
                    $params->fromString($recvParams);
                } else if (is_array($recvParams)) {
                    $params = new ReceiveParameters();
                    $params->fromArray($recvParams);
                } else {
                    throw new \InvalidArgumentException(
                        'Invalid receiveParameters param type: must be null, an array, a string or an instace of '. QueueClientInterface::class
                    );
                }

                $result = $this->{$action}($queue, $params);
                break;

            default:
                throw new \InvalidArgumentException(
                    sprintf('Invalid action "%s". Only "process", "receive", "await" are allowed', $action)
                );
        }


        $e->setResult($result);

        return $result;
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
    public function receive(QueueClientInterface $queue, ReceiveParametersInterface $params = null)
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
    public function await(QueueClientInterface $queue, ReceiveParametersInterface $params = null)
    {
        $worker = $this;
        $this->await = true;
        $lastResult = array();

        $handler = function(MessageInterface $message) use($worker, $queue, &$await, &$lastResult) {

            $lastResult = $worker->process($message);

            if ($queue->canDeleteMessage()) {
                $queue->deleteMessage($message);
            }

            return !$worker->isAwaitingStopped();
        };


        $queue->await($params, $handler);

        return $lastResult;
    }

    /**
     * @return boolean
     */
    public function isAwaitingStopped()
    {
        return !$this->await;
    }

}