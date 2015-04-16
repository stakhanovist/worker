<?php
/**
 * Stakhanovist
 *
 * @link        https://github.com/stakhanovist/worker
 * @copyright   Copyright (c) 2015, Stakhanovist <stakhanovist@leonardograsso.com>
 * @license     http://opensource.org/licenses/BSD-2-Clause Simplified BSD License
 */

namespace Stakhanovist\Worker;

use ArrayAccess;
use Zend\EventManager\Event;
use Zend\Stdlib\MessageInterface;
use Zend\Mvc\Controller\AbstractController;
use Stakhanovist\Worker\Exception;
use Stakhanovist\Worker\Processor\ProcessorInterface;


class ProcessEvent extends Event
{
    /**#@+
     * Process events triggered by eventmanager
     */
    const EVENT_PROCESSOR       = 'processor';
    const EVENT_PROCESS_POST    = 'process.post';
    /**#@-*/


    /**
     * @var null|MessageInterface
     */
    protected $message;

    /**
     * @var mixed
     */
    protected $result;

    /**
     * @var ProcessorInterface
     */
    protected $processor;

    /**
     * @var AbstractController
     */
    protected $worker;


    /**
     * Retrieve message object
     *
     * @return null|MessageInterface
     */
    public function getMessage()
    {
        return $this->message;
    }

    /**
     * Set message
     *
     * @param MessageInterface $message
     * @return $this
     */
    public function setMessage(MessageInterface $message)
    {
        $this->message = $message;
        return $this;
    }

    /**
     * Retrieve the result of processing
     *
     * @return mixed
     */
    public function getResult()
    {
        return $this->result;
    }

    /**
     * Set result of rendering
     *
     * @param  mixed $result
     * @return $this
     */
    public function setResult($result)
    {
        $this->result = $result;
        return $this;
    }

    /**
     * Get value for processor
     *
     * @return null|ProcessorInterface
     */
    public function getProcessor()
    {
        return $this->processor;
    }

    /**
     * Set value for processor
     *
     * @param  ProcessorInterface $processor
     * @return $this
     */
    public function setProcessor(ProcessorInterface $processor)
    {
        $this->processor = $processor;
        return $this;
    }

    /**
     * @return null|AbstractController
     */
    public function getWorker()
    {
        return $this->worker;
    }

    /**
     * Set value for worker
     *
     * @param  AbstractController $worker
     * @return $this
     */
    public function setWorker(AbstractController $worker)
    {
        $this->worker = $worker;
        return $this;
    }

    /**
     * Get event parameter
     *
     * @param  string $name
     * @param  mixed $default
     * @return mixed
     */
    public function getParam($name, $default = null)
    {
        switch ($name) {
            case 'processor':
                return $this->getProcessor();
            case 'message':
                return $this->getMessage();
            case 'result':
                return $this->getResult();
            default:
                return parent::getParam($name, $default);
        }
    }

    /**
     * Get all event parameters
     *
     * @return array|\ArrayAccess
     */
    public function getParams()
    {
        $params              = parent::getParams();
        $params['processor'] = $this->getProcessor();
        $params['message']   = $this->getMessage();
        $params['result']    = $this->getResult();
        return $params;
    }

    /**
     * Set event parameters
     *
     * @param  array|object|ArrayAccess $params
     * @return $this
     */
    public function setParams($params)
    {
        if (!is_array($params) && !is_object($params)) {
            throw new Exception\InvalidArgumentException(
                sprintf('Event parameters must be an array or object; received "%s"', gettype($params))
            );
        }

        foreach ($params as $name => $value) {
            $this->setParam($name, $value);
        }

        return $this;
    }

    /**
     * Set an individual event parameter
     *
     * @param  string $name
     * @param  mixed $value
     * @return $this
     */
    public function setParam($name, $value)
    {
        switch ($name) {
            case 'processor':
                $this->setProcessor($value);
                break;
            case 'message':
                $this->setMessage($value);
                break;
            case 'result':
                $this->setResult($value);
                break;
            default:
                parent::setParam($name, $value);
                break;
        }
        return $this;
    }
}
