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
class ProcessEventTest extends \PHPUnit_Framework_TestCase
{
    /** @var ProcessEvent */
    protected $event;

    public function setUp()
    {
        $this->event = new ProcessEvent();
    }

    public function testSetGetMessage()
    {
        // Default
        $this->assertNull($this->event->getMessage());

        // Test
        $testValue = new Message();
        $testValue->setContent('bar');

        $this->assertSame($this->event, $this->event->setMessage($testValue));
        $this->assertSame($testValue, $this->event->getMessage());
    }

    public function testSetGetResult()
    {
        // Default
        $this->assertNull($this->event->getResult());

        // Test
        $testValue = 'foo';

        $this->assertSame($this->event, $this->event->setResult($testValue));
        $this->assertSame($testValue, $this->event->getResult());
    }

    public function testSetGetProcessor()
    {
        // Default
        $this->assertNull($this->event->getProcessor());

        // Test
        $testValue = new ForwardProcessor();

        $this->assertSame($this->event, $this->event->setProcessor($testValue));
        $this->assertSame($testValue, $this->event->getProcessor());
    }

    public function testSetGetWorker()
    {
        // Default
        $this->assertNull($this->event->getWorker());

        // Test
        $testValue = new Worker();

        $this->assertSame($this->event, $this->event->setWorker($testValue));
        $this->assertSame($testValue, $this->event->getWorker());
    }

    public function testSpecializedParametersMayBeSetViaSetParams()
    {
        $processor = new ForwardProcessor();
        $message   = new Message();

        $params = [
            'processor'  => $processor,
            'message'    => $message,
            'result'     => 'result',
            'foo'        => 'bar',
        ];

        $this->event->setParams($params);
        $this->assertEquals($params, $this->event->getParams());

        foreach ($params as $param => $expectedValue) {
            if (method_exists($this->event, 'get'.$param)) {
                $this->assertSame($params[$param], $this->event->{'get'.$param}());
            }
            $this->assertSame($params[$param], $this->event->getParam($param));
        }
    }
}
