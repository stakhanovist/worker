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
use Stakhanovist\Queue\Parameter\SendParametersInterface;
use Stakhanovist\Queue\Parameter\SendParameters;
use Stakhanovist\Queue\QueueEvent;


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

            case 'send':
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

                if ($action === 'send') {

                    $message = $routeMatch->getParam('message', null);
                    $parameters = $routeMatch->getParam('sendParameters', null);

                    if ($parameters === null) {
                        $params = null;
                    } else if ($parameters instanceof SendParametersInterface) {
                        $params = $parameters;
                    } else if (is_string($parameters)) {
                        $params = new SendParameters();
                        $params->fromString($parameters);
                    } else if (is_array($parameters)) {
                        $params = new SendParameters();
                        $params->fromArray($parameters);
                    } else {
                        throw new \InvalidArgumentException(
                            'Invalid sendParameters param type: must be null, an array, a string or an instace of '. SendParametersInterface::class
                        );
                    }

                    $result = $this->send($queue, $message, $params);
                } else {

                    $parameters = $routeMatch->getParam('receiveParameters', null);

                    if ($parameters === null) {
                        $params = null;
                    } else if ($parameters instanceof ReceiveParametersInterface) {
                        $params = $parameters;
                    } else if (is_string($parameters)) {
                        $params = new ReceiveParameters();
                        $params->fromString($parameters);
                    } else if (is_array($parameters)) {
                        $params = new ReceiveParameters();
                        $params->fromArray($parameters);
                    } else {
                        throw new \InvalidArgumentException(
                            'Invalid receiveParameters param type: must be null, an array, a string or an instace of '. QueueClientInterface::class
                        );
                    }

                    if ($action === 'receive') {
                        $maxMessages = (int) $routeMatch->getParam('maxMessages', 1);
                        $result = $this->receive($queue, $maxMessages, $params);
                    } else {
                        $result = $this->await($queue, $params);
                    }
                }
                break;

            default:
                throw new \InvalidArgumentException(
                    sprintf('Invalid action "%s". Only "process", "send", "receive", "await" are allowed', $action)
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
        $e->setTarget($this);
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
     * Send a message to the queue
     *
     * @param QueueClientInterface $queue
     * @param mixed $message
     * @param SendParametersInterface $params
     * @return MessageInterface
     */
    public function send(QueueClientInterface $queue, $message, SendParametersInterface $params = null)
    {
        $queue->send($message, $params);
    }


    /**
     * Receive and process one or more incoming messages
     *
     * @param QueueClientInterface $queue
     * @param int $maxMessages
     * @param ReceiveParametersInterface $params
     * @return mixed
     */
    public function receive(QueueClientInterface $queue, $maxMessages = 1, ReceiveParametersInterface $params = null)
    {
        $messages = $queue->receive($maxMessages, $params);
        $lastResult = [];

        foreach ($messages as $message) {
            if ($message instanceof MessageInterface) {

                $lastResult = $this->process($message);

                if ($queue->canDeleteMessage()) {
                    $queue->delete($message);
                }
            }
            // TODO: else?
        }

        return $lastResult;
    }

    /**
     * Wait for and process incoming messages
     *
     * TODO: handle idle
     * @param QueueClientInterface $queue
     * @param ReceiveParametersInterface $params
     * @return boolean|\Stakhanovist\Worker\mixed
     */
    public function await(QueueClientInterface $queue, ReceiveParametersInterface $params = null)
    {
        $worker = $this;
        $this->await = true;
        $lastResult = [];

        $callback = function(QueueEvent $event) use($worker, $queue, &$lastResult) {

            $messages = $event->getMessages();

            foreach ($messages as $message) {
                $lastResult = $worker->process($message);

                if ($queue->canDeleteMessage()) {
                    $queue->delete($message);
                }
            }

            if ($worker->isAwaitingStopped()) {
                $event->stopAwait(true);
            }

            return !$worker->isAwaitingStopped();
        };


        $callbackHandler = $queue->getEventManager()->attach(QueueEvent::EVENT_RECEIVE, $callback);

        $queue->await($params);

        $queue->getEventManager()->detach($callbackHandler);


        return $lastResult;
    }

    /**
     * @return boolean
     */
    public function isAwaitingStopped()
    {
        return !$this->await;
    }

    /**
     *
     */
    public function stopAwaiting()
    {
        $this->await = false;
    }

}