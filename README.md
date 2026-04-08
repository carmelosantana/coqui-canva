# Canva Toolkit for Coqui

Comprehensive Canva Connect API toolkit for [Coqui](https://github.com/coquibot/coqui). Provides structured access to the Canva REST API v1 for design management, asset uploads, exports, folders, comments, brand templates, and autofill.

## Installation

```bash
composer require coquibot/coqui-toolkit-canva
```

The toolkit is auto-discovered by Coqui on startup. No additional configuration required.

## Authentication Setup

### 1. Create a Canva Integration

1. Go to the [Canva Developer Portal](https://www.canva.com/developers/)
2. Create a new integration (or use an existing one)
3. Set the redirect URL to `http://127.0.0.1` (the toolkit uses a random high port for the callback)
4. Note your **Client ID** and **Client Secret**

### 2. Configure Credentials

In Coqui, set your credentials:

```
credentials(action: "set", key: "CANVA_CLIENT_ID", value: "your-client-id")
credentials(action: "set", key: "CANVA_CLIENT_SECRET", value: "your-client-secret")
```

### 3. Authenticate

```
canva_auth(action: "login")
```

This opens your browser for OAuth authorization. After granting access, the toolkit stores tokens securely and auto-refreshes them.

### Required Scopes

The toolkit requests these scopes during OAuth:

| Scope | Tools |
|-------|-------|
| `design:content:read` | canva_export |
| `design:content:write` | canva_design (create), canva_autofill |
| `design:meta:read` | canva_design (list, get) |
| `asset:read` | canva_asset (get, list) |
| `asset:write` | canva_asset (upload, update, delete) |
| `folder:read` | canva_folder (list_items, get) |
| `folder:write` | canva_folder (create, update, delete, move_item) |
| `brandtemplate:meta:read` | canva_brand_template (list, get) |
| `brandtemplate:content:read` | canva_brand_template (dataset), canva_autofill |
| `comment:read` | canva_comment (list_threads, get_thread) |
| `comment:write` | canva_comment (create_thread, create_reply) |
| `profile:read` | canva_user |

## Tools Reference

### canva_auth

Manage OAuth authentication.

| Action | Description |
|--------|-------------|
| `login` | Opens browser for OAuth authorization |
| `status` | Shows current authentication status and token expiry |
| `logout` | Revokes tokens and clears stored credentials |

### canva_design

Create and manage Canva designs.

| Action | Parameters | Description |
|--------|------------|-------------|
| `create` | `design_type` (doc/whiteboard/presentation/custom), `title`, `width`, `height`, `asset_id` | Create a new design |
| `list` | `query`, `sort_by`, `continuation` | List designs with search and pagination |
| `get` | `design_id` | Get design details by ID |
| `delete` | `design_id` | Delete a design (gated) |

### canva_export

Export designs to various formats. Jobs are polled automatically.

| Action | Parameters | Description |
|--------|------------|-------------|
| `create` | `design_id`, `format` (pdf/jpg/png/pptx/gif/mp4), `pages`, `quality`, `width`, `height` | Create export job and poll for completion |
| `status` | `job_id` | Check export job status |

### canva_asset

Upload and manage media assets.

| Action | Parameters | Description |
|--------|------------|-------------|
| `upload` | `file_path`, `name` | Upload a file as a Canva asset |
| `get` | `asset_id` | Get asset details |
| `list` | `continuation` | List assets with pagination |
| `update` | `asset_id`, `name`, `tags` | Update asset metadata |
| `delete` | `asset_id` | Delete an asset (gated) |
| `upload_status` | `job_id` | Check upload job status |

### canva_folder

Organize designs and assets into folders.

| Action | Parameters | Description |
|--------|------------|-------------|
| `create` | `name`, `parent_folder_id` | Create a new folder |
| `list_items` | `folder_id`, `item_type`, `sort_by`, `continuation` | List items in a folder |
| `get` | `folder_id` | Get folder details |
| `update` | `folder_id`, `name` | Rename a folder |
| `delete` | `folder_id` | Delete a folder (gated) |
| `move_item` | `folder_id`, `item_id`, `item_type` | Move an item to a folder |

### canva_comment

Manage design comments and threads.

| Action | Parameters | Description |
|--------|------------|-------------|
| `create_thread` | `design_id`, `message` | Create a new comment thread |
| `list_threads` | `design_id`, `continuation` | List comment threads on a design |
| `create_reply` | `thread_id`, `message` | Reply to a comment thread |
| `get_thread` | `thread_id` | Get full thread with replies |

### canva_user

Get authenticated user information.

| Action | Description |
|--------|-------------|
| `profile` | Get the current user's profile |

### canva_brand_template (Enterprise)

Manage brand templates. Requires Canva Enterprise subscription.

| Action | Parameters | Description |
|--------|------------|-------------|
| `list` | `query`, `continuation` | List available brand templates |
| `get` | `template_id` | Get template details |
| `dataset` | `template_id` | Get autofillable fields and their types |

### canva_autofill (Enterprise)

Create designs from brand templates with data. Requires Canva Enterprise.

| Action | Parameters | Description |
|--------|------------|-------------|
| `create` | `template_id`, `data` (JSON), `title` | Autofill a template and poll for completion |
| `status` | `job_id` | Check autofill job status |

## Workflow Examples

### Create and Export a Presentation

```
canva_auth(action: "status")
canva_design(action: "create", design_type: "presentation", title: "Q4 Review")
canva_export(action: "create", design_id: "DAFxyz123", format: "pdf")
```

### Upload Assets and Organize

```
canva_asset(action: "upload", file_path: "/path/to/logo.png", name: "Company Logo")
canva_folder(action: "create", name: "Brand Assets")
canva_folder(action: "move_item", folder_id: "FAFabc", item_id: "AAFxyz")
```

### Autofill a Brand Template (Enterprise)

```
canva_brand_template(action: "list", query: "social")
canva_brand_template(action: "dataset", template_id: "BTFxyz")
canva_autofill(action: "create", template_id: "BTFxyz", data: '{"headline": "Summer Sale", "discount": "30% off"}')
canva_export(action: "create", design_id: "...", format: "png")
```

### Add Design Feedback

```
canva_comment(action: "create_thread", design_id: "DAFxyz", message: "The logo needs more padding on the left")
canva_comment(action: "list_threads", design_id: "DAFxyz")
```

## MCP Integration (Canva AI Connector)

In addition to this REST API toolkit, Canva offers an MCP server (Canva AI Connector) for design operations. You can use both simultaneously — the REST toolkit for structured programmatic access and the MCP connector for interactive design operations.

### Setting Up the Canva AI Connector

Add to your `.workspace/mcp.json`:

```json
{
    "servers": {
        "canva": {
            "type": "stdio",
            "command": "npx",
            "args": ["-y", "@anthropic-ai/canva-mcp-server"],
            "env": {
                "CANVA_CLIENT_ID": "${CANVA_CLIENT_ID}",
                "CANVA_CLIENT_SECRET": "${CANVA_CLIENT_SECRET}"
            }
        }
    }
}
```

The MCP connector shares the same `CANVA_CLIENT_ID` and `CANVA_CLIENT_SECRET` credentials.

## Designer Role

The toolkit includes a `designer` agent role optimized for creative work. Spawn it as a child agent:

```
spawn_agent(role: "designer", task: "Create a presentation deck for our Q4 review")
```

The designer role brings expertise in visual design principles, brand consistency, and Canva workflow patterns.

## Rate Limits

Canva enforces per-user rate limits:

| Endpoint Category | Limit |
|-------------------|-------|
| General | 100 requests/minute |
| Design creation | 20 requests/minute |
| Exports | 20 requests/minute |
| Asset uploads | 20 requests/minute |

The toolkit automatically retries once on rate limit (429) responses using the `Retry-After` header.

## Enterprise Features

Brand templates and autofill require a Canva Enterprise subscription. Non-Enterprise users will see a clear error message when attempting to use these features. All other tools work with any Canva plan.

## Development

```bash
# Install dependencies
composer install

# Run tests
vendor/bin/pest

# Static analysis
vendor/bin/phpstan analyse
```

## License

MIT
