<?php

/*
 * Copyright (C) 2026 Jacobi Carter and Chris Breneman
 *
 * This file is part of ClueBot NG.
 *
 * ClueBot NG is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * ClueBot NG is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with ClueBot NG.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace CluebotNG;

use Prometheus\RenderTextFormat;
use Amp\Http\HttpStatus;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Psr\Log\NullLogger;

use function Amp\async;
use function Amp\delay;

class MetricServer
{
    private static $text_renderer = null;

    public static function generateResponse()
    {
        global $logger;

        if (self::$text_renderer === null) {
            self::$text_renderer = new RenderTextFormat();
        }

        try {
            $content = self::$text_renderer->render(Metrics::getMetricFamilySamples());
            return new Response(
                status: HttpStatus::OK,
                headers: ['Content-Type' => RenderTextFormat::MIME_TYPE],
                body: $content,
            );
        } catch (\Throwable $e) {
            $logger->error("Failed to render metrics: " . $e->getMessage());
            return new Response(status: HttpStatus::INTERNAL_SERVER_ERROR);
        }
    }

    public static function run()
    {
        global $logger;

        $pid = pcntl_fork();
        if ($pid === -1) {
            $logger->error('Failed to fork Prometheus metrics server');
            die();
        }
        if ($pid !== 0) {
            $logger->debug("Prometheus metrics server forked as " . $pid);
            return;
        }

        // Child process
        $requestHandler = new class () implements RequestHandler {
            public function handleRequest(Request $request): Response
            {
                if ($request->getMethod() !== 'GET' || $request->getUri()->getPath() !== '/metrics') {
                    return new Response(status: HttpStatus::NOT_FOUND);
                }
                return MetricServer::generateResponse();
            }
        };

        $server = SocketHttpServer::createForDirectAccess($logger);
        $server->expose("0.0.0.0:" . Config::$metrics_port);
        $server->start($requestHandler, new DefaultErrorHandler());

        $logger->info("Prometheus metrics server listening on " . Config::$metrics_port);
        $parent_pid = posix_getppid();
        async(function () use ($server, $parent_pid) {
            while (posix_kill($parent_pid, 0)) {
                delay(1);
            }
            $server->stop();
        })->await();
    }
}
