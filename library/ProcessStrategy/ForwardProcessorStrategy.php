<?php
/**
 * Stakhanovist
 *
 * @link        https://github.com/stakhanovist/worker
 * @copyright   Copyright (c) 2015, Stakhanovist <stakhanovist@leonardograsso.com>
 * @license     http://opensource.org/licenses/BSD-2-Clause Simplified BSD License
 */

namespace Stakhanovist\Worker\ProcessStrategy;


use Zend\EventManager\AbstractListenerAggregate;
use Zend\EventManager\EventManagerInterface;
use Zend\Mvc\MvcEvent;
use Zend\Console\Console;
use Stakhanovist\Worker\Processor\ForwardProcessor;
use Stakhanovist\Worker\ProcessEvent;


/**
 * Select the ForwardProcessor as default and render its result
 *
 */
class ForwardProcessorStrategy extends AbstractListenerAggregate
{

    /**
     * @var ForwardProcessor
    */
    protected $processor;

    /**
     * Constructor
     *
     * @param  ForwardProcessor $processor
     */
    public function __construct(ForwardProcessor $processor = null)
    {
        $this->processor = $processor;
    }

    /**
     * Retrieve the composed processor
     *
     * @return ForwardProcessor
     */
    public function getProcessor()
    {
        if (!$this->processor) {
            $this->processor = new ForwardProcessor();
        }

        return $this->processor;
    }


    /**
     * {@inheritDoc}
     */
    public function attach(EventManagerInterface $events, $priority = 1)
    {
        $this->listeners[] = $events->attach(ProcessEvent::EVENT_PROCESSOR, array($this, 'selectProcessor'), $priority);
        $this->listeners[] = $events->attach(ProcessEvent::EVENT_PROCESS_POST, array($this, 'postProcess'), $priority);
    }

    /**
     * Select the ForwardProcessor; typically, this will be registered last or at
     * low priority.
     *
     * @param  ProcessEvent $e
     * @return ForwardProcessor
     */
    public function selectProcessor(ProcessEvent $e)
    {
        return $this->processor;
    }

    /**
     * Render the processor result.
     *
     * The result is rendered triggering a "render" event on a cloned MvcEvent,
     * simulating the usual MVC render flow.
     *
     * @todo investigate if simulation works in all cases
     * @param ProcessEvent $e
     */
    public function postProcess(ProcessEvent $e)
    {
        $appEvent       = clone $e->getWorker()->getEvent();
        $appEvent->setTarget($appEvent->getApplication());

        $appEvents      = $appEvent->getApplication()->getEventManager();

        $appResponse    = clone $appEvent->getResponse();
        $appEvent->setResponse($appResponse);
        $appEvent->setResult($e->getResult());

        $appEvents->trigger(MvcEvent::EVENT_RENDER, $appEvent);

        $e->setResult($appEvent->getResponse()->getContent());

        if (Console::isConsole() && is_string($e->getResult())) {
            echo $e->getResult();
        }
    }
}
