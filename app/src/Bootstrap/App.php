<?php

declare(strict_types=1);

namespace App\Bootstrap;

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\SettingsController;
use App\Database\ConnectionFactory;
use App\Database\V1Migration;
use App\Processors\QueueProcessor;
use App\Processors\UserProcessor;
use App\Repositories\UserRepository;
use App\Services\CryptoService;
use App\Services\I18nService;
use App\Services\LastFmService;
use App\Services\LoggerFactory;
use App\Services\MontageService;
use App\Services\Social\BlueskyClient;
use App\Services\Social\MastodonClient;
use Dotenv\Dotenv;
use GuzzleHttp\Client as GuzzleClient;
use League\Container\Container;
use League\Plates\Engine;
use League\Route\Router;
use Psr\Log\LoggerInterface;
use Nyholm\Psr7\Response;

final class App
{
    public static function createContainer(string $basePath): Container
    {
        if (file_exists($basePath . '/.env')) {
            Dotenv::createImmutable($basePath)->safeLoad();
        }

        $container = new Container();

        $container->add('basePath', $basePath);
        $container->add('dataPath', $basePath . '/data');

        $container->add(LoggerFactory::class)->addArgument('dataPath');
        $container->add(LoggerInterface::class, function () use ($container): LoggerInterface {
            /** @var LoggerFactory $factory */
            $factory = $container->get(LoggerFactory::class);
            return $factory->make('app');
        });

        $container->add(ConnectionFactory::class)
            ->addArgument('basePath')
            ->addArgument(LoggerInterface::class);

        $container->add(UserRepository::class)
            ->addArgument(ConnectionFactory::class)
            ->addArgument(LoggerInterface::class);

        $container->add(CryptoService::class)
            ->addArgument(LoggerInterface::class);

        $container->add(GuzzleClient::class, function (): GuzzleClient {
            return new GuzzleClient([
                'timeout' => 25,
                'connect_timeout' => 15,
                'http_errors' => false,
                'verify' => false,
                'headers' => [
                    'User-Agent' => 'LastFM.blue/1.0',
                ],
            ]);
        });

        $container->add(LastFmService::class)
            ->addArgument(GuzzleClient::class)
            ->addArgument(LoggerInterface::class)
            ->addArgument('basePath');

        $container->add(MontageService::class)
            ->addArgument(LoggerInterface::class)
            ->addArgument('basePath');

        $container->add(BlueskyClient::class)
            ->addArgument(GuzzleClient::class)
            ->addArgument(LoggerInterface::class);

        $container->add(MastodonClient::class)
            ->addArgument(GuzzleClient::class)
            ->addArgument(LoggerInterface::class);

        $container->add(Engine::class, function () use ($container): Engine {
            $engine = new Engine($container->get('basePath') . '/templates');

            /** @var UserRepository $userRepo */
            $userRepo = $container->get(UserRepository::class);
            $totalUsers = $userRepo->countTotalUsers();

            $engine->addData([
                'appUrl' => ($_ENV['APP_URL'] ?? ''),
                'totalUsers' => $totalUsers,
            ]);
            return $engine;
        });

        $container->add(I18nService::class)
            ->addArgument('basePath')
            ->addArgument(UserRepository::class)
            ->addArgument(LoggerInterface::class);

        $container->add(AuthController::class)
            ->addArgument(UserRepository::class)
            ->addArgument(CryptoService::class)
            ->addArgument(BlueskyClient::class)
            ->addArgument(MastodonClient::class)
            ->addArgument(I18nService::class)
            ->addArgument(Engine::class)
            ->addArgument(LoggerInterface::class);

        $container->add(SettingsController::class)
            ->addArgument(UserRepository::class)
            ->addArgument(LastFmService::class)
            ->addArgument(I18nService::class)
            ->addArgument(Engine::class)
            ->addArgument(LoggerInterface::class);

        $container->add(UserProcessor::class)
            ->addArgument(UserRepository::class)
            ->addArgument(LastFmService::class)
            ->addArgument(MontageService::class)
            ->addArgument(LoggerFactory::class);

        $container->add(QueueProcessor::class)
            ->addArgument(UserRepository::class)
            ->addArgument(CryptoService::class)
            ->addArgument(LastFmService::class)
            ->addArgument(BlueskyClient::class)
            ->addArgument(MastodonClient::class)
            ->addArgument(LoggerFactory::class);

        $container->add(AdminController::class)
            ->addArgument(ConnectionFactory::class)
            ->addArgument(Engine::class);

        $container->add(V1Migration::class)
            ->addArgument(ConnectionFactory::class)
            ->addArgument(LoggerInterface::class);

        return $container;
    }

    public static function createRouter(Container $container): Router
    {
        $router = new Router();

        $router->map('GET', '/favicon.ico', static fn () => new Response(204));

        $router->map('POST', '/locale', [AuthController::class, 'setLocale']);

        $router->map('GET', '/', [AuthController::class, 'index']);
        $router->map('GET', '/settings', [SettingsController::class, 'index']);
        $router->map('POST', '/settings', [SettingsController::class, 'save']);

        $router->map('POST', '/auth/bluesky', [AuthController::class, 'loginBluesky']);
        $router->map('GET', '/auth/mastodon', [AuthController::class, 'startMastodon']);
        $router->map('GET', '/auth/mastodon/callback', [AuthController::class, 'callbackMastodon']);
        $router->map('POST', '/logout', [AuthController::class, 'logout']);
        $router->map('POST', '/account/delete', [AuthController::class, 'deleteAccount']);

        $router->map('GET', '/admin', [AdminController::class, 'index']);
        $router->map('GET', '/admin/login', [AdminController::class, 'loginForm']);
        $router->map('POST', '/admin/login', [AdminController::class, 'login']);
        $router->map('POST', '/admin/logout', [AdminController::class, 'logout']);

        $router->setStrategy((new \League\Route\Strategy\ApplicationStrategy())->setContainer($container));

        return $router;
    }
}

