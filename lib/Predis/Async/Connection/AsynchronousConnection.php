<?php

/*
 * This file is part of the Predis\Async package.
 *
 * (c) Daniele Alessandri <suppakilla@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Predis\Async\Connection;

use SplQueue;
use Predis\Command\CommandInterface;
use Predis\ConnectionParametersInterface;
use Predis\ResponseObjectInterface;
use Predis\ResponseErrorInterface;
use Predis\ResponseError;
use Predis\ResponseQueued;
use Predis\ClientException;
use Predis\Async\Buffer\StringBuffer;
use React\EventLoop\LoopInterface;

class AsynchronousConnection implements AsynchronousConnectionInterface
{
    protected $parameters;
    protected $eventloop;
    protected $socket;
    protected $reader;
    protected $buffer;
    protected $cmdqueue;
    protected $state;
    protected $timeout = null;
    protected $stateCbk = null;
    protected $onError = null;
    protected $onConnect = null;
    protected $cbkStreamReadable = null;
    protected $cbkStreamWritable = null;

    /**
     * @param ConnectionParametersInterface $parameters
     * @param LoopInterface $eventloop
     */
    public function __construct(ConnectionParametersInterface $parameters, LoopInterface $eventloop)
    {
        $this->parameters = $parameters;
        $this->eventloop = $eventloop;

        $this->state = 'DISCONNECTED';
        $this->buffer = new StringBuffer();
        $this->cmdqueue = new SplQueue();

        $this->cbkStreamReadable = array($this, 'read');
        $this->cbkStreamWritable = array($this, 'write');

        $this->initializeReader();
    }

    /**
     * Disconnects from the server and destroys the underlying resource when
     * PHP's garbage collector kicks in.
     */
    public function __destruct()
    {
        phpiredis_reader_destroy($this->reader);
        $this->disconnect();
    }

    /**
     * Initializes the protocol reader resource.
     */
    protected function initializeReader()    {

        $reader = phpiredis_reader_create();

        phpiredis_reader_set_status_handler($reader, $this->getStatusHandler());
        phpiredis_reader_set_error_handler($reader, $this->getErrorHandler());

        $this->reader = $reader;
    }

    /**
     * Creates the underlying resource used to communicate with Redis.
     *
     * @return mixed
     */
    protected function createResource()
    {
        $uri = "tcp://{$this->parameters->host}:{$this->parameters->port}/";
        $flags = STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT;

        if (!$socket = @stream_socket_client($uri, $errno, $errstr, 0, $flags)) {
            // TODO: this is actually broken.
            $this->onError(new ConnectionException($this, trim($errstr), $errno));
            return false;
        }

        stream_set_blocking($socket, 0);
        $this->setState('CONNECTING');

        $timeout = $this->parameters->timeout;
        $callbackArgs = array($this, $this->onError);

        $this->eventloop->addWriteStream($socket, array($this, 'onConnect'));
        $this->timeout = $this->eventloop->addTimer($timeout, function ($timer, $loop) use ($callbackArgs) {
            list($connection, $onError) = $callbackArgs;

            $connection->disconnect();

            if (isset($onError)) {
                call_user_func($onError, $connection, new ConnectionException($connection, 'Connection timed out'));
            }
        });

        return $socket;
    }

    /**
     * {@inheritdoc}
     */
    public function isConnected()
    {
        return isset($this->socket);
    }

    /**
     * {@inheritdoc}
     */
    public function connect()
    {
        if ($this->isConnected()) {
            return false;
        }

        $this->socket = $this->createResource();

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function disconnect()
    {
        $this->eventloop->removeStream($this->getResource());
        $this->setState('DISCONNECTED');
        $this->buffer->reset();
        unset($this->socket);
    }

    /**
     * {@inheritdoc}
     */
    public function getResource()
    {
        if (isset($this->socket)) {
            return $this->socket;
        }

        $this->connect();

        return $this->socket;
    }

    /**
     * {@inheritdoc}
     */
    public function setConnectCallback($callback) {
        if (!is_callable($callback)) {
            throw new \InvalidArgumentException('The specified callback must be a callable object');
        }

        $this->onConnect = $callback;
    }

    /**
     * {@inheritdoc}
     */
    public function onConnect()
    {
        $socket = $this->getResource();
        $this->setState('READY');

        $this->eventloop->cancelTimer($this->timeout);
        $this->timeout = null;

        $this->eventloop->removeWriteStream($socket);
        $this->eventloop->addReadStream($socket, $this->cbkStreamReadable);

        if (isset($this->onConnect)) {
            call_user_func($this->onConnect, $this);
        }

        if (!$this->buffer->isEmpty()) {
            $this->write($socket);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function setErrorCallback($callback) {
        if (!is_callable($callback)) {
            throw new \InvalidArgumentException('The specified callback must be a callable object');
        }

        $this->onError = $callback;
    }

    /**
     * {@inheritdoc}
     */
    protected function onError(\Exception $exception)
    {
        $this->disconnect();

        if (isset($this->onError)) {
            call_user_func($this->onError, $this, $exception);
        }
    }

    /**
     * Gets the handler used by the protocol reader to handle status replies.
     *
     * @return \Closure
     */
    protected function getStatusHandler()
    {
        return function($payload) {
            switch ($payload) {
                case 'OK':
                    return true;

                case 'QUEUED':
                    return new ResponseQueued();

                default:
                    return $payload;
            }
        };
    }

    /**
     * Gets the handler used by the protocol reader to handle Redis errors.
     *
     * @param Boolean $throw_errors Specify if Redis errors throw exceptions.
     * @return \Closure
     */
    protected function getErrorHandler()
    {
        return function($errorMessage) {
            return new ResponseError($errorMessage);
        };
    }

    /**
     * {@inheritdoc}
     */
    public function write()
    {
        if ($this->buffer->isEmpty()) {
            return false;
        }

        $socket = $this->getResource();
        $buffer = $this->buffer->read(4096);

        if (-1 === $ret = @stream_socket_sendto($socket, $buffer)) {
            $this->onError(new ConnectionException($this, 'Error while writing bytes to the server'));
            return;
        }

        $this->buffer->discard(min($ret, strlen($buffer)));
    }

    /**
     * {@inheritdoc}
     */
    public function read()
    {
        $socket = $this->getResource();
        $reader = $this->reader;

        $buffer = stream_socket_recvfrom($socket, 4096);

        if ($buffer === false || $buffer === '') {
            $this->onError(new ConnectionException($this, 'Error while reading bytes from the server'));
            return;
        }

        phpiredis_reader_feed($reader, $buffer);

        while (phpiredis_reader_get_state($reader) === PHPIREDIS_READER_STATE_COMPLETE) {
            $response = phpiredis_reader_get_reply($reader);

            switch ($this->state) {
                case 'READY':
                    list($command, $callback) = $this->cmdqueue->dequeue();

                    switch ($command->getId()) {
                        case 'SUBSCRIBE':
                        case 'PSUBSCRIBE':
                            $this->setState('PUBSUB');
                            $this->stateCbk = $callback;
                            break;

                        case 'MONITOR':
                            $this->setState('MONITOR');
                            $this->stateCbk = $callback;

                        default:
                            if (isset($callback)) {
                                if (!$response instanceof ResponseObjectInterface) {
                                    $response = $command->parseResponse($response);
                                }
                                call_user_func($callback, $response, $response instanceof ResponseErrorInterface);
                            }
                            break;
                    }

                    break;

                case 'MONITOR':
                    if (isset($this->stateCbk)) {
                        call_user_func($this->stateCbk, $response);
                    }
                    break;

                case 'PUBSUB':
                    if (isset($this->stateCbk)) {
                        call_user_func($this->stateCbk, $response);
                    }
                    break;

                case 'CONNECTING':
                    // TODO: sup?
                    break;

                case 'DISCONNECTED':
                    // TODO: sup?
                    break;

                default:
                    // We can get there only if we have a bug somewhere...
                    $this->onError(new ConnectionException($this, 'Unknown connection state: {$this->state} [BUG]'));
                    return;
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function executeCommand(CommandInterface $command, $callback)
    {
        if ($this->buffer->isEmpty()) {
            $this->eventloop->addWriteStream($this->getResource(), $this->cbkStreamWritable);
        }

        $cmdargs = $command->getArguments();
        array_unshift($cmdargs, $command->getId());

        $this->buffer->append(phpiredis_format_command($cmdargs));
        $this->cmdqueue->enqueue(array($command, $callback));
    }

    /**
     * {@inheritdoc}
     */
    public function getParameters()
    {
        return $this->parameters;
    }

    /**
     * {@inheritdoc}
     */
    protected function setState($state)
    {
        $this->state = $state;
    }

    /**
     * {@inheritdoc}
     */
    public function getState()
    {
        return $this->state;
    }

    /**
     * Gets an identifier for the connection.
     *
     * @return string
     */
    protected function getIdentifier()
    {
        if ($this->parameters->scheme === 'unix') {
            return $this->parameters->path;
        }

        return "{$this->parameters->host}:{$this->parameters->port}";
    }

    /**
     * {@inheritdoc}
     */
    public function __toString()
    {
        return $this->getIdentifier();
    }
}