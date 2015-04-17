<?php
/**
 * Stakhanovist
 *
 * @link        https://github.com/stakhanovist/worker
 * @copyright   Copyright (c) 2015, Stakhanovist <stakhanovist@leonardograsso.com>
 * @license     http://opensource.org/licenses/BSD-2-Clause Simplified BSD License
 */

namespace StakhanovistTest\Worker;


use Stakhanovist\Worker\ProcessEvent;
use Zend\Stdlib\Message;
use Stakhanovist\Worker\Processor\ForwardProcessor;
use StakhanovistTest\Worker\TestAsset\Worker;
/**
 * Class ProcessEventTest
 */
class AbstractWorkerControllerTest extends \PHPUnit_Framework_TestCase
{
    /** @var ProcessEvent */
    protected $worker;

    public function setUp()
    {
        $this->worker = new Worker();
    }

    public function testGetSetProcessEvent()
    {
        // Default
        $event = $this->worker->getProcessEvent();
        $this->assertInstanceOf('\Stakhanovist\Worker\ProcessEvent', $event);
        $this->assertSame($this->worker, $event->getTarget());

        // Using setter
        $event = new ProcessEvent();
        $this->worker->setProcessEvent($event);

        $this->assertSame($event, $this->worker->getProcessEvent());
        $this->assertSame($this->worker, $event->getTarget());

    }

    public function testAwaitingStop()
    {
        // Default
        $this->assertTrue($this->worker->isAwaitingStopped());

        $this->worker->stopAwaiting();

        $this->assertTrue($this->worker->isAwaitingStopped());

    }
}
