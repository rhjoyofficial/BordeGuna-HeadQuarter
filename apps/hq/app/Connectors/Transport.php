<?php

namespace App\Connectors;

/**
 * How an adapter reaches its provider (docs/96 §5, docs/97 §6 D2).
 *
 * The transport is a property of the connector, never of the capability: the same capability may be
 * served over a direct API today and over MCP or n8n tomorrow, and nothing that asks for the
 * capability changes. Whatever the transport, the call still passes the Tool Gateway first; MCP and
 * n8n never grant authority (docs/97 §6, the MCP and n8n contracts).
 */
enum Transport: string
{
    /** HQ calls the provider's own HTTP API. */
    case DirectApi = 'direct_api';

    /** HQ calls an allowlisted MCP server, behind the gateway; write actions denied until governed. */
    case Mcp = 'mcp';

    /** HQ sends a signed request (run_id + idempotency key) to an n8n flow and records the callback. */
    case N8n = 'n8n';

    /** The work is done inside HQ or on infrastructure HQ owns (local disk, an internal service). */
    case Internal = 'internal';
}
