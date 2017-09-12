<?php
/*
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

namespace Tests\Unit\Http\Controllers\Server\Files;

use Mockery as m;
use Tests\TestCase;
use Illuminate\Log\Writer;
use Illuminate\Http\Request;
use Pterodactyl\Models\Server;
use Illuminate\Contracts\Session\Session;
use GuzzleHttp\Exception\RequestException;
use Pterodactyl\Exceptions\DisplayException;
use Tests\Assertions\ControllerAssertionsTrait;
use Pterodactyl\Http\Requests\Server\UpdateFileContentsFormRequest;
use Pterodactyl\Contracts\Repository\Daemon\FileRepositoryInterface;
use Pterodactyl\Http\Controllers\Server\Files\FileActionsController;

class FileActionsControllerTest extends TestCase
{
    use ControllerAssertionsTrait;

    /**
     * @var \Pterodactyl\Http\Controllers\Server\Files\FileActionsController
     */
    protected $controller;

    /**
     * @var \Pterodactyl\Http\Requests\Server\UpdateFileContentsFormRequest
     */
    protected $fileContentsFormRequest;

    /**
     * @var \Pterodactyl\Contracts\Repository\Daemon\FileRepositoryInterface
     */
    protected $fileRepository;

    /**
     * @var \Illuminate\Http\Request
     */
    protected $request;

    /**
     * @var \Illuminate\Contracts\Session\Session
     */
    protected $session;

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

        $this->fileContentsFormRequest = m::mock(UpdateFileContentsFormRequest::class);
        $this->fileRepository = m::mock(FileRepositoryInterface::class);
        $this->request = m::mock(Request::class);
        $this->session = m::mock(Session::class);
        $this->writer = m::mock(Writer::class);

        $this->controller = m::mock(FileActionsController::class, [
            $this->fileRepository, $this->session, $this->writer,
        ])->makePartial();
    }

    /**
     * Test the index view controller.
     */
    public function testIndexController()
    {
        $server = factory(Server::class)->make();

        $this->session->shouldReceive('get')->with('server_data.model')->once()->andReturn($server);
        $this->controller->shouldReceive('authorize')->with('list-files', $server)->once()->andReturnNull();
        $this->request->shouldReceive('user->can')->andReturn(true);
        $this->controller->shouldReceive('injectJavascript')->once()->andReturnNull();

        $response = $this->controller->index($this->request);
        $this->assertIsViewResponse($response);
        $this->assertViewNameEquals('server.files.index', $response);
    }

    /**
     * Test the file creation view controller.
     *
     * @dataProvider directoryNameProvider
     */
    public function testCreateController($directory, $expected)
    {
        $server = factory(Server::class)->make();

        $this->session->shouldReceive('get')->with('server_data.model')->once()->andReturn($server);
        $this->controller->shouldReceive('authorize')->with('create-files', $server)->once()->andReturnNull();
        $this->controller->shouldReceive('injectJavascript')->once()->andReturnNull();
        $this->request->shouldReceive('get')->with('dir')->andReturn($directory);

        $response = $this->controller->create($this->request);
        $this->assertIsViewResponse($response);
        $this->assertViewNameEquals('server.files.add', $response);
        $this->assertViewHasKey('directory', $response);
        $this->assertViewKeyEquals('directory', $expected, $response);
    }

    /**
     * Test the update controller.
     *
     * @dataProvider fileNameProvider
     */
    public function testUpdateController($file, $expected)
    {
        $server = factory(Server::class)->make();

        $this->session->shouldReceive('get')->with('server_data.model')->once()->andReturn($server);
        $this->controller->shouldReceive('authorize')->with('edit-files', $server)->once()->andReturnNull();
        $this->session->shouldReceive('get')->with('server_data.token')->once()->andReturn($server->daemonSecret);
        $this->fileRepository->shouldReceive('setNode')->with($server->node_id)->once()->andReturnSelf()
            ->shouldReceive('setAccessServer')->with($server->uuid)->once()->andReturnSelf()
            ->shouldReceive('setAccessToken')->with($server->daemonSecret)->once()->andReturnSelf()
            ->shouldReceive('getContent')->with($file)->once()->andReturn('file contents');

        $this->fileContentsFormRequest->shouldReceive('getStats')->withNoArgs()->twice()->andReturn(['stats']);
        $this->controller->shouldReceive('injectJavascript')->with(['stat' => ['stats']])->once()->andReturnNull();

        $response = $this->controller->update($this->fileContentsFormRequest, '1234', $file);
        $this->assertIsViewResponse($response);
        $this->assertViewNameEquals('server.files.edit', $response);
        $this->assertViewHasKey('file', $response);
        $this->assertViewHasKey('stat', $response);
        $this->assertViewHasKey('contents', $response);
        $this->assertViewHasKey('directory', $response);
        $this->assertViewKeyEquals('file', $file, $response);
        $this->assertViewKeyEquals('stat', ['stats'], $response);
        $this->assertViewKeyEquals('contents', 'file contents', $response);
        $this->assertViewKeyEquals('directory', $expected, $response);
    }

    /**
     * Test that an exception is handled correctly in the controller.
     */
    public function testExceptionRenderedByUpdateController()
    {
        $server = factory(Server::class)->make();
        $exception = m::mock(RequestException::class);

        $this->session->shouldReceive('get')->with('server_data.model')->once()->andReturn($server);
        $this->controller->shouldReceive('authorize')->with('edit-files', $server)->once()->andReturnNull();
        $this->fileRepository->shouldReceive('setNode')->with($server->node_id)->once()->andThrow($exception);

        $exception->shouldReceive('getResponse')->withNoArgs()->once()->andReturnNull();
        $this->writer->shouldReceive('warning')->with($exception)->once()->andReturnNull();

        try {
            $this->controller->update($this->fileContentsFormRequest, '1234', 'file.txt');
        } catch (DisplayException $exception) {
            $this->assertEquals(trans('exceptions.daemon_connection_failed', ['code' => 'E_CONN_REFUSED']), $exception->getMessage());
        }
    }

    /**
     * Provides a list of directory names and the expected output from formatting.
     *
     * @return array
     */
    public function directoryNameProvider()
    {
        return [
            [null, ''],
            ['/', ''],
            ['', ''],
            ['my/directory', 'my/directory/'],
            ['/my/directory/', 'my/directory/'],
            ['/////my/directory////', 'my/directory/'],
        ];
    }

    /**
     * Provides a list of file names and the expected output from formatting.
     *
     * @return array
     */
    public function fileNameProvider()
    {
        return [
            ['/my/file.txt', 'my/'],
            ['my/file.txt', 'my/'],
            ['file.txt', '/'],
            ['/file.txt', '/'],
            ['./file.txt', '/'],
        ];
    }
}
