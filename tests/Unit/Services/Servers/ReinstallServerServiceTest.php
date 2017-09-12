<?php
/**
 * Pterodactyl - Panel
 * Copyright (c) 2015 - 2017 Dane Everitt <dane@daneeveritt.com>.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

namespace Tests\Unit\Services\Servers;

use Exception;
use Mockery as m;
use Tests\TestCase;
use Illuminate\Log\Writer;
use Pterodactyl\Models\Server;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Database\ConnectionInterface;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Services\Servers\ReinstallServerService;
use Pterodactyl\Contracts\Repository\ServerRepositoryInterface;
use Pterodactyl\Contracts\Repository\Daemon\ServerRepositoryInterface as DaemonServerRepositoryInterface;

class ReinstallServerServiceTest extends TestCase
{
    /**
     * @var \Pterodactyl\Contracts\Repository\Daemon\ServerRepositoryInterface
     */
    protected $daemonServerRepository;

    /**
     * @var \Illuminate\Database\ConnectionInterface
     */
    protected $database;

    /**
     * @var \GuzzleHttp\Exception\RequestException
     */
    protected $exception;

    /**
     * @var \Pterodactyl\Contracts\Repository\ServerRepositoryInterface
     */
    protected $repository;

    /**
     * @var \Pterodactyl\Models\Server
     */
    protected $server;

    /**
     * @var \Pterodactyl\Services\Servers\ReinstallServerService
     */
    protected $service;

    /**
     * @var \Illuminate\Log\Writer
     */
    protected $writer;

    /**
     * Setup tests.
     */
    public function setUp()
    {
        parent::setUp();

        $this->daemonServerRepository = m::mock(DaemonServerRepositoryInterface::class);
        $this->database = m::mock(ConnectionInterface::class);
        $this->exception = m::mock(RequestException::class)->makePartial();
        $this->repository = m::mock(ServerRepositoryInterface::class);
        $this->writer = m::mock(Writer::class);

        $this->server = factory(Server::class)->make(['node_id' => 1]);

        $this->service = new ReinstallServerService(
            $this->database,
            $this->daemonServerRepository,
            $this->repository,
            $this->writer
        );
    }

    /**
     * Test that a server is reinstalled when it's model is passed to the function.
     */
    public function testServerShouldBeReinstalledWhenModelIsPassed()
    {
        $this->repository->shouldNotReceive('find');

        $this->database->shouldReceive('beginTransaction')->withNoArgs()->once()->andReturnNull();
        $this->repository->shouldReceive('withoutFresh')->withNoArgs()->once()->andReturnSelf()
            ->shouldReceive('update')->with($this->server->id, [
                'installed' => 0,
            ])->once()->andReturnNull();

        $this->daemonServerRepository->shouldReceive('setNode')->with($this->server->node_id)->once()->andReturnSelf()
            ->shouldReceive('setAccessServer')->with($this->server->uuid)->once()->andReturnSelf()
            ->shouldReceive('reinstall')->withNoArgs()->once()->andReturnNull();
        $this->database->shouldReceive('commit')->withNoArgs()->once()->andReturnNull();

        $this->service->reinstall($this->server);
    }

    /**
     * Test that a server is reinstalled when the ID of the server is passed to the function.
     */
    public function testServerShouldBeReinstalledWhenServerIdIsPassed()
    {
        $this->repository->shouldReceive('find')->with($this->server->id)->once()->andReturn($this->server);

        $this->database->shouldReceive('beginTransaction')->withNoArgs()->once()->andReturnNull();
        $this->repository->shouldReceive('withoutFresh')->withNoArgs()->once()->andReturnSelf()
            ->shouldReceive('update')->with($this->server->id, [
                'installed' => 0,
            ])->once()->andReturnNull();

        $this->daemonServerRepository->shouldReceive('setNode')->with($this->server->node_id)->once()->andReturnSelf()
            ->shouldReceive('setAccessServer')->with($this->server->uuid)->once()->andReturnSelf()
            ->shouldReceive('reinstall')->withNoArgs()->once()->andReturnNull();
        $this->database->shouldReceive('commit')->withNoArgs()->once()->andReturnNull();

        $this->service->reinstall($this->server->id);
    }

    /**
     * Test that an exception thrown by guzzle is rendered as a displayable exception.
     */
    public function testExceptionThrownByGuzzleShouldBeReRenderedAsDisplayable()
    {
        $this->database->shouldReceive('beginTransaction')->withNoArgs()->once()->andReturnNull();
        $this->repository->shouldReceive('withoutFresh')->withNoArgs()->once()->andReturnSelf()
            ->shouldReceive('update')->with($this->server->id, [
                'installed' => 0,
            ])->once()->andReturnNull();

        $this->daemonServerRepository->shouldReceive('setNode')->with($this->server->node_id)
            ->once()->andThrow($this->exception);

        $this->exception->shouldReceive('getResponse')->withNoArgs()->once()->andReturnSelf()
            ->shouldReceive('getStatusCode')->withNoArgs()->once()->andReturn(400);

        $this->writer->shouldReceive('warning')->with($this->exception)->once()->andReturnNull();

        try {
            $this->service->reinstall($this->server);
        } catch (Exception $exception) {
            $this->assertInstanceOf(DisplayException::class, $exception);
            $this->assertEquals(
                trans('admin/server.exceptions.daemon_exception', ['code' => 400]),
                $exception->getMessage()
            );
        }
    }

    /**
     * Test that an exception thrown by something other than guzzle is not transformed to a displayable.
     *
     * @expectedException \Exception
     */
    public function testExceptionNotThrownByGuzzleShouldNotBeTransformedToDisplayable()
    {
        $this->database->shouldReceive('beginTransaction')->withNoArgs()->once()->andReturnNull();
        $this->repository->shouldReceive('withoutFresh')->withNoArgs()->once()->andReturnSelf()
            ->shouldReceive('update')->with($this->server->id, [
                'installed' => 0,
            ])->once()->andReturnNull();

        $this->daemonServerRepository->shouldReceive('setNode')->with($this->server->node_id)
            ->once()->andThrow(new Exception());

        $this->service->reinstall($this->server);
    }
}
