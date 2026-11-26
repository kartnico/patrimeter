# API and assistants (MCP)

Patrimeter offers a **REST API** and an **MCP service** (for an assistant such as Claude) with the same
authentication as the site:

- **personal token**, created in My account, "AI agents" tab (read only, or read and write,
  limited lifetime);
- or **access granted to an application**, after signing in and consenting in the browser (OAuth 2.1).

Each access can be revoked from the same tab, which also gives the addresses of the MCP service and of the API
to give to the AI agent (account menu, **AI agents**), each with a **Copy** button that puts it in the clipboard. An
assistant that calls these tools sends your data to its
provider: the consent covers this too.

The rate is limited per token: 120 requests in a row, then one per second.

The API and the MCP service are **disabled by default**: the instance administrator enables each of them with the
`API_ENABLED` and `MCP_ENABLED` variables ([API and MCP](../exploitation/api.md)). As long as neither is enabled,
the "AI agents" tab does not appear in My account, nor in the account menu.

Complete reference (addresses, MCP tools, examples): [API and MCP](../exploitation/api.md). Each API operation,
its parameters and its responses: [API reference](../exploitation/api-reference.md), generated from the OpenAPI
description ([openapi.yaml](../exploitation/openapi.yaml)).
