<?php
/**
 * Stakhanovist
 *
 * @link        https://github.com/stakhanovist/worker
 * @copyright   Copyright (c) 2015, Stakhanovist <stakhanovist@leonardograsso.com>
 * @license     http://opensource.org/licenses/BSD-2-Clause Simplified BSD License
 */

namespace Stakhanovist\Worker\Processor;

use Zend\Stdlib\MessageInterface;
use Zend\Mvc\Controller\AbstractController;

/**
 * Default processor that dispatches another controller
 *
 * It expects the controller name (either a class name
 * or an alias used in the controller manager) within
 * the message's content and parameters within message's
 * metadata (with which to seed a custom RouteMatch
 * object for the new controller).
 *
 * Internally the "forward" controller plugin will be used
 * to dispatch the request, so this processor works in
 * the same way "forward" does.
 *
 */
class ForwardProcessor implements ProcessorInterface
{
    /**
     * @var AbstractController
     */
    protected $worker;

    /**
     * Set the worker
     *
     * @param  AbstractController $worker
     * @return ProcessorInterface
     */
    public function setWorker(AbstractController $worker)
    {
        $this->worker = $worker;
        return $this;
    }

    /**
     * Processes a message and returns the result.
     *
     * @param  MessageInterface   $message The message to process
     * @return mixed The result.
     */
    public function process(MessageInterface $message)
    {
        return $this->worker->forward()->dispatch($message->getContent(), $message->getMetadata());
    }
}