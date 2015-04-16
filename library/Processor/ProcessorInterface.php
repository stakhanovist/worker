<?php
/**
 * Stakhanovist
 *
 * @link        https://github.com/stakhanovist/worker
 * @copyright   Copyright (c) 2015, Stakhanovist <stakhanovist@leonardograsso.com>
 * @license     http://opensource.org/licenses/BSD-2-Clause Simplified BSD License
 */

namespace Stakhanovist\Worker\Processor;

use Zend\Mvc\Controller\AbstractController;
use Zend\Stdlib\MessageInterface;

interface ProcessorInterface
{
    /**
     * Processes a message and returns the result.
     *
     * @param  MessageInterface   $message The message to process
     * @return mixed The result.
     */
    public function process(MessageInterface $message);
}
