<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Controller;

use App\Mcp\McpAccessToken;
use App\Mcp\McpServer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * MCP endpoint pro AI asistenta (Streamable HTTP bez sezení). Volá ho stroj
 * klienta, ne prohlížeč — chrání ho jen Bearer token z nastavení AI asistenta.
 * GET (SSE stream) server nenabízí, jen POST s jednou JSON-RPC zprávou.
 */
#[AsController]
class McpController
{
    public function __construct(
        private readonly McpServer $server,
        private readonly McpAccessToken $token,
    ) {
    }

    #[Route('/mcp', name: 'mcp_endpoint', methods: ['GET', 'POST', 'DELETE'])]
    public function __invoke(Request $request): Response
    {
        if (!$this->token->matches($this->bearer($request))) {
            return new JsonResponse(
                ['error' => 'unauthorized'],
                Response::HTTP_UNAUTHORIZED,
                ['WWW-Authenticate' => 'Bearer realm="ubytovadlo"'],
            );
        }
        if (!$request->isMethod('POST')) {
            return new Response('', Response::HTTP_METHOD_NOT_ALLOWED, ['Allow' => 'POST']);
        }

        $reply = $this->server->handleRaw($request->getContent());

        if ($reply === null) {
            return new Response('', Response::HTTP_ACCEPTED);
        }

        $response = new JsonResponse($reply);
        $response->setEncodingOptions(JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $response;
    }

    private function bearer(Request $request): string
    {
        $header = (string) $request->headers->get('Authorization', '');

        return str_starts_with($header, 'Bearer ') ? trim(substr($header, 7)) : '';
    }
}
