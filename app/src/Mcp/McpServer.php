<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Mcp;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * MCP server bez stavu: jedna JSON-RPC zpráva dovnitř, nejvýš jedna ven. Sezení
 * ani SSE stream nedrží — sdílený hosting nemá daemon a nástroje jsou jen
 * dotaz–odpověď. Umí handshake, ping a nástroje (seznam + volání).
 */
final class McpServer
{
    /** Podporované verze protokolu, nejnovější první. */
    public const PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26'];

    private const PARSE_ERROR = -32700;
    private const INVALID_REQUEST = -32600;
    private const METHOD_NOT_FOUND = -32601;
    private const INVALID_PARAMS = -32602;
    private const INTERNAL_ERROR = -32603;

    /** @var array<string, McpTool> */
    private array $tools = [];

    /**
     * @param iterable<McpTool> $tools
     */
    public function __construct(
        #[AutowireIterator(McpTool::TAG)]
        iterable $tools,
        private readonly LoggerInterface $logger,
    ) {
        foreach ($tools as $tool) {
            $this->tools[$tool->name()] = $tool;
        }
    }

    /**
     * Zpracuje tělo HTTP požadavku. Null = notifikace nebo odpověď klienta,
     * na které se neodpovídá (HTTP 202).
     *
     * @return array<string, mixed>|null
     */
    public function handleRaw(string $body): ?array
    {
        try {
            $message = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::error(null, self::PARSE_ERROR, 'Parse error');
        }

        if (!is_array($message) || array_is_list($message)) {
            return self::error(null, self::INVALID_REQUEST, 'Expected a single JSON-RPC message');
        }

        return $this->handle($message);
    }

    /**
     * @param array<mixed> $message
     *
     * @return array<string, mixed>|null
     */
    public function handle(array $message): ?array
    {
        $id = $message['id'] ?? null;
        $method = $message['method'] ?? null;
        if (!is_string($method)) {
            // Odpověď klienta na náš požadavek — server žádné neposílá, ignoruje se.
            return isset($message['result']) || isset($message['error'])
                ? null
                : self::error($id, self::INVALID_REQUEST, 'Missing method');
        }
        if (!array_key_exists('id', $message)) {
            return null;
        }

        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        return match ($method) {
            'initialize' => self::result($id, $this->initialize($params)),
            'ping' => self::result($id, new \stdClass()),
            'tools/list' => self::result($id, ['tools' => $this->listTools()]),
            'tools/call' => $this->callTool($id, $params),
            default => self::error($id, self::METHOD_NOT_FOUND, 'Method not found: ' . $method),
        };
    }

    /**
     * @param array<mixed> $params
     *
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        $requested = $params['protocolVersion'] ?? null;
        $version = in_array($requested, self::PROTOCOL_VERSIONS, true) ? $requested : self::PROTOCOL_VERSIONS[0];

        return [
            'protocolVersion' => $version,
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => 'ubytovadlo', 'version' => '0.1.0'],
            'instructions' => 'Data ubytování: hosté, rezervace, obsazenost a informace o objektu. '
                . 'Slouží k přípravě odpovědí hostům. Nástroje, které nejsou jen pro čtení, volej jen '
                . 'na výslovný pokyn majitele.',
        ];
    }

    /** @return list<array<string, mixed>> */
    private function listTools(): array
    {
        return array_values(array_map(static fn (McpTool $tool): array => [
            'name' => $tool->name(),
            'description' => $tool->description(),
            'inputSchema' => $tool->inputSchema(),
            'annotations' => $tool->effect()->annotations(),
        ], $this->tools));
    }

    /**
     * @param array<mixed> $params
     *
     * @return array<string, mixed>
     */
    private function callTool(mixed $id, array $params): array
    {
        $name = $params['name'] ?? null;
        $tool = is_string($name) ? ($this->tools[$name] ?? null) : null;
        if ($tool === null) {
            return self::error($id, self::INVALID_PARAMS, 'Unknown tool: ' . (is_string($name) ? $name : ''));
        }

        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        try {
            $data = $tool->call($arguments);
        } catch (McpToolException $e) {
            return self::result($id, ['content' => [self::text($e->getMessage())], 'isError' => true]);
        } catch (\Throwable $e) {
            $this->logger->error('MCP tool failed', ['tool' => $tool->name(), 'exception' => $e]);

            return self::error($id, self::INTERNAL_ERROR, 'Tool failed: ' . $tool->name());
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return self::result($id, ['content' => [self::text($json)], 'isError' => false]);
    }

    /** @return array{type: string, text: string} */
    private static function text(string $text): array
    {
        return ['type' => 'text', 'text' => $text];
    }

    /** @return array<string, mixed> */
    private static function result(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /** @return array<string, mixed> */
    private static function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
