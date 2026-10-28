<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Mcp;

use App\Mcp\McpServer;
use App\Mcp\McpTool;
use App\Mcp\McpToolEffect;
use App\Mcp\McpToolException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class McpServerTest extends TestCase
{
    private McpServer $server;

    protected function setUp(): void
    {
        $this->server = new McpServer([new EchoTool()], new NullLogger());
    }

    public function testInitializeEchoesSupportedProtocolVersion(): void
    {
        $reply = $this->server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26']]);

        self::assertSame(1, $reply['id'] ?? null);
        self::assertSame('2025-03-26', $reply['result']['protocolVersion']);
        self::assertArrayHasKey('tools', $reply['result']['capabilities']);
    }

    public function testInitializeFallsBackToLatestForUnknownVersion(): void
    {
        $reply = $this->server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '1999-01-01']]);

        self::assertSame(McpServer::PROTOCOL_VERSIONS[0], $reply['result']['protocolVersion'] ?? null);
    }

    public function testNotificationAndClientResponseGetNoReply(): void
    {
        self::assertNull($this->server->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));
        self::assertNull($this->server->handle(['jsonrpc' => '2.0', 'id' => 7, 'result' => []]));
    }

    public function testToolsListIsReadOnly(): void
    {
        $reply = $this->server->handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);

        $tools = $reply['result']['tools'] ?? [];
        self::assertCount(1, $tools);
        self::assertSame('echo', $tools[0]['name']);
        self::assertTrue($tools[0]['annotations']['readOnlyHint']);
    }

    public function testSendingToolIsAnnotatedAsOpenWorld(): void
    {
        $server = new McpServer([new class extends EchoTool {
            public function effect(): McpToolEffect
            {
                return McpToolEffect::SEND;
            }
        }], new NullLogger());

        $annotations = $server->handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'])['result']['tools'][0]['annotations'] ?? [];

        self::assertFalse($annotations['readOnlyHint']);
        self::assertFalse($annotations['destructiveHint']);
        self::assertTrue($annotations['openWorldHint']);
    }

    public function testToolCallReturnsJsonText(): void
    {
        $reply = $this->server->handle(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'echo', 'arguments' => ['say' => 'ahoj']]]);

        self::assertFalse($reply['result']['isError'] ?? null);
        self::assertSame(['said' => 'ahoj'], json_decode($reply['result']['content'][0]['text'], true));
    }

    public function testToolExceptionBecomesErrorResult(): void
    {
        $reply = $this->server->handle(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'echo', 'arguments' => []]]);

        self::assertTrue($reply['result']['isError'] ?? null);
        self::assertSame('say chybí', $reply['result']['content'][0]['text']);
    }

    public function testUnexpectedToolFailureIsInternalError(): void
    {
        $reply = $this->server->handle(['jsonrpc' => '2.0', 'id' => 8, 'method' => 'tools/call', 'params' => ['name' => 'echo', 'arguments' => ['say' => 'boom']]]);

        self::assertSame(-32603, $reply['error']['code'] ?? null);
    }

    public function testUnknownToolAndMethodAreProtocolErrors(): void
    {
        $tool = $this->server->handle(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => ['name' => 'nope']]);
        $method = $this->server->handle(['jsonrpc' => '2.0', 'id' => 6, 'method' => 'resources/list']);

        self::assertSame(-32602, $tool['error']['code'] ?? null);
        self::assertSame(-32601, $method['error']['code'] ?? null);
    }

    public function testRawBodyValidation(): void
    {
        self::assertSame(-32700, $this->server->handleRaw('{nope')['error']['code'] ?? null);
        self::assertSame(-32600, $this->server->handleRaw('[{"jsonrpc":"2.0","id":1,"method":"ping"}]')['error']['code'] ?? null);
        self::assertSame(-32600, $this->server->handleRaw('{"jsonrpc":"2.0","id":1}')['error']['code'] ?? null);
    }
}

class EchoTool implements McpTool
{
    public function name(): string
    {
        return 'echo';
    }

    public function effect(): McpToolEffect
    {
        return McpToolEffect::READ;
    }

    public function description(): string
    {
        return 'Vrátí, co dostane.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object'];
    }

    public function call(array $arguments): array
    {
        if (!isset($arguments['say'])) {
            throw new McpToolException('say chybí');
        }
        if ($arguments['say'] === 'boom') {
            throw new \LogicException('boom');
        }

        return ['said' => $arguments['say']];
    }
}
