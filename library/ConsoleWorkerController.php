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
use Zend\Stdlib\MessageInterface;


class ConsoleWorkerController extends AbstractWorkerController
{

    /**
     * @var SerializerAdapterInterface|null
     */
    protected $serializer;

    protected $cliPassthru;

    /**
     * @var bool
     */
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

            $cliPassthru = $routeMatch->getParam('cli-passthru', null);
            if ($cliPassthru) {
                $this->cliPassthru = sprintf(
                    '%s -f %s -- %s',
                    PHP_BINARY,
                    realpath($_SERVER['PHP_SELF']),
                    $cliPassthru
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
     * {@inheritdoc}
     */
    public function process(MessageInterface $message)
    {
        if ($this->cliPassthru) {
            return $this->cliPassthru(
                $this->cliPassthru,
                $this->getSerializer()->serialize($message)
            );
        }
        return parent::process($message);
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

    /**
     * Execute a shell $command sending $stdin to the input pipe, then echo the passthru output
     *
     * @param string $command
     * @param string $stdin
     * @return int
     */
    protected function cliPassthru($command, $stdin)
    {
        $descriptorspec = array(
            0 => array("pipe", "r"),  // stdin is a pipe that the child will read from
            1 => array("pipe", "wb"),  // stdout is a pipe that the child will write to
        );

        $process = proc_open($command, $descriptorspec, $pipes);

        if (is_resource($process)) {
            // $pipes now looks like this:
            // 0 => writeable handle connected to child stdin
            // 1 => readable handle connected to child stdout

            fwrite($pipes[0], $stdin);
            fclose($pipes[0]);

            // realtime output all remaining data
            fpassthru($pipes[1]);

            fclose($pipes[1]);

            // It is important that you close any pipes before calling
            // proc_close in order to avoid a deadlock
            $returnValue = proc_close($process);

            return $returnValue;
        }
    }

}