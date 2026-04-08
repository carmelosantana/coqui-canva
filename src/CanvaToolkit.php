<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitCanva;

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\CoquiToolkitCanva\Client\CanvaClient;
use CarmeloSantana\CoquiToolkitCanva\Tool\AssetTool;
use CarmeloSantana\CoquiToolkitCanva\Tool\AuthTool;
use CarmeloSantana\CoquiToolkitCanva\Tool\AutofillTool;
use CarmeloSantana\CoquiToolkitCanva\Tool\BrandTemplateTool;
use CarmeloSantana\CoquiToolkitCanva\Tool\CommentTool;
use CarmeloSantana\CoquiToolkitCanva\Tool\DesignTool;
use CarmeloSantana\CoquiToolkitCanva\Tool\ExportTool;
use CarmeloSantana\CoquiToolkitCanva\Tool\FolderTool;
use CarmeloSantana\CoquiToolkitCanva\Tool\UserTool;

/**
 * Canva Connect API toolkit for Coqui.
 *
 * Provides comprehensive Canva API access: OAuth authentication, design CRUD,
 * export to multiple formats, asset management, folders, comments, brand
 * templates, and autofill.
 */
final class CanvaToolkit implements ToolkitInterface
{
    private readonly CanvaClient $client;

    public function __construct(
        ?CanvaClient $client = null,
        string $workspacePath = '',
    ) {
        $this->client = $client ?? CanvaClient::fromEnv(
            $workspacePath !== '' ? $workspacePath : (string) (getenv('COQUI_WORKSPACE') ?: getcwd()),
        );
    }

    /**
     * @return array<\CarmeloSantana\PHPAgents\Contract\ToolInterface>
     */
    public function tools(): array
    {
        return [
            (new AuthTool($this->client))->build(),
            (new DesignTool($this->client))->build(),
            (new ExportTool($this->client))->build(),
            (new AssetTool($this->client))->build(),
            (new FolderTool($this->client))->build(),
            (new CommentTool($this->client))->build(),
            (new UserTool($this->client))->build(),
            (new BrandTemplateTool($this->client))->build(),
            (new AutofillTool($this->client))->build(),
        ];
    }

    public function guidelines(): string
    {
        return <<<'GUIDELINES'
        <CANVA-GUIDELINES>
        ## Canva Toolkit

        You have full access to the Canva Connect API through the following tools:

        ### Tool Overview
        - **canva_auth** — OAuth login, status check, logout
        - **canva_design** — Create (preset or custom dimensions), list, get, delete designs
        - **canva_export** — Export designs to PDF, PNG, JPG, PPTX, GIF, MP4 (async with auto-polling)
        - **canva_asset** — Upload files, list, get, update metadata, delete assets
        - **canva_folder** — Create, list items, get, update, delete folders, move items
        - **canva_comment** — Create comment threads, list, reply, get thread details
        - **canva_user** — Get authenticated user profile
        - **canva_brand_template** — List, get, inspect brand template datasets (Enterprise)
        - **canva_autofill** — Create designs from brand templates with data (Enterprise)

        ### Authentication Flow
        1. First, check auth status: `canva_auth(action: "status")`
        2. If not authenticated: `canva_auth(action: "login")` — opens browser for OAuth
        3. After login, all other tools work automatically with token auto-refresh

        ### Common Workflows

        **Create and export a design:**
        1. `canva_design(action: "create", design_type: "presentation", title: "My Deck")`
        2. Note the design ID from the response
        3. `canva_export(action: "create", design_id: "...", format: "pdf")`
        4. The export auto-polls and returns download URLs

        **Upload an asset and use it in a design:**
        1. `canva_asset(action: "upload", file_path: "/path/to/image.png")`
        2. Note the asset ID from the response
        3. `canva_design(action: "create", design_type: "custom", width: 1920, height: 1080, asset_id: "...")`

        **Autofill a brand template (Enterprise):**
        1. `canva_brand_template(action: "list")` — find the template
        2. `canva_brand_template(action: "dataset", template_id: "...")` — discover fillable fields
        3. `canva_autofill(action: "create", template_id: "...", data: '{"headline": "New Title", "body": "Content..."}')`

        **Organize designs into folders:**
        1. `canva_folder(action: "create", name: "Project Assets")`
        2. `canva_folder(action: "move_item", folder_id: "...", item_id: "...")`

        ### Important Notes
        - **Rate limits**: Canva enforces 20-100 requests/minute per user depending on the endpoint. The client retries once on rate limit (429).
        - **Async jobs**: Export, upload, and autofill operations are asynchronous. The tools auto-poll for completion and return the final result.
        - **Enterprise features**: Brand templates and autofill require Canva Enterprise. Non-Enterprise users will see a clear error message.
        - **Design types**: Use `doc`, `whiteboard`, `presentation` presets, or `custom` with width/height in pixels.
        - **Export formats**: PDF (multi-page), PNG/JPG (raster), PPTX (PowerPoint), GIF (animated), MP4 (video).
        - **Destructive operations**: delete actions for designs, assets, and folders require user confirmation.
        </CANVA-GUIDELINES>
        GUIDELINES;
    }
}
