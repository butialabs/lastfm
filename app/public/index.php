<?php

declare(strict_types=1);

use App\Bootstrap\App;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Nyholm\Psr7Server\ServerRequestCreator;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use League\Route\Http\Exception\NotFoundException;

require __DIR__ . '/../vendor/autoload.php';

$container = App::createContainer(basePath: dirname(__DIR__));
$router = App::createRouter($container);

$psr17Factory = new Psr17Factory();
$creator = new ServerRequestCreator(
    serverRequestFactory: $psr17Factory,
    uriFactory: $psr17Factory,
    uploadedFileFactory: $psr17Factory,
    streamFactory: $psr17Factory
);

$request = $creator->fromGlobals();
try {
    $response = $router->dispatch($request);
} catch (NotFoundException) {
    $response = new Response(404, ['Content-Type' => 'text/plain; charset=utf-8'], 'Not Found');
} catch (Throwable $e) {
    $response = new Response(500, ['Content-Type' => 'text/plain; charset=utf-8'], 'Internal Server Error');
}

(new SapiEmitter())->emit($response);

