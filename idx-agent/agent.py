#!/usr/bin/env python3
"""
AiDX Scanner Agent
──────────────────
Logs into WordPress, runs every AiDX Scanner tab (pages, posts, widgets,
nav menus, shortcodes), cross-references iHomeFinder/IMPress saved searches,
then uses Claude to compile a full IDX audit report and optionally make
targeted content changes via the WordPress REST API.

Usage
-----
  cp .env.example .env          # fill in your credentials
  pip install -r requirements.txt
  python agent.py               # audit only (read-only)
  python agent.py --fix         # audit + let Claude fix issues it finds
"""

import anyio
import httpx
import json
import re
import os
import sys
from typing import Any

from dotenv import load_dotenv
from claude_agent_sdk import (
    tool,
    create_sdk_mcp_server,
    ClaudeSDKClient,
    ClaudeAgentOptions,
    AssistantMessage,
    TextBlock,
    ResultMessage,
)

load_dotenv()

# ── Configuration ─────────────────────────────────────────────────────────────
WP_URL         = os.environ["WP_URL"].rstrip("/")
WP_USER        = os.environ["WP_USER"]
WP_APP_PASSWORD = os.environ["WP_APP_PASSWORD"]          # WordPress Application Password
HTTP_AUTH_USER  = os.getenv("HTTP_AUTH_USER", "")        # staging HTTP Basic Auth (optional)
HTTP_AUTH_PASS  = os.getenv("HTTP_AUTH_PASS", "")
IHF_API_KEY     = os.getenv("IHF_API_KEY", "")          # iHomeFinder API key (optional)
IHF_PARTNER_KEY = os.getenv("IHF_PARTNER_KEY", "")      # iHomeFinder partner key (optional)

FIX_MODE = "--fix" in sys.argv


# ── WordPress client ──────────────────────────────────────────────────────────
class WordPressClient:
    """
    Handles two auth layers:
      1. HTTP Basic Auth  — the password prompt some staging hosts put in front
                            of the entire site (SiteGround, WP Engine, etc.)
      2. WordPress session — cookie-based login used for AJAX calls that need
                            the idx_scanner_nonce
      3. Application Password — used for all WordPress REST API requests
    """

    def __init__(self):
        self._nonce: str | None = None
        self._cookies: dict     = {}
        # HTTP Basic Auth for the staging server layer
        self._http_auth = (HTTP_AUTH_USER, HTTP_AUTH_PASS) if HTTP_AUTH_USER else None
        # WordPress Application Password auth for REST API
        self._wp_auth   = (WP_USER, WP_APP_PASSWORD)

    def _http_client(self, **kwargs) -> httpx.AsyncClient:
        """Return an httpx client pre-configured with staging HTTP auth if set."""
        kw = {"follow_redirects": True, "timeout": 30.0, **kwargs}
        if self._http_auth:
            kw["auth"] = self._http_auth
        return httpx.AsyncClient(**kw)

    # ── Session login (for AJAX nonce) ────────────────────────────────────────
    async def _login(self) -> None:
        async with self._http_client() as c:
            # Seed the test cookie
            await c.get(f"{WP_URL}/wp-login.php")
            resp = await c.post(
                f"{WP_URL}/wp-login.php",
                data={
                    "log":         WP_USER,
                    "pwd":         WP_APP_PASSWORD,
                    "wp-submit":   "Log In",
                    "redirect_to": f"{WP_URL}/wp-admin/",
                    "testcookie":  "1",
                },
                headers={"Cookie": "wordpress_test_cookie=WP Cookie check"},
            )
            if "wp-admin" not in str(resp.url) and "wp-admin" not in resp.text:
                raise RuntimeError(
                    f"WordPress login failed — check WP_USER / WP_APP_PASSWORD.\n"
                    f"Final URL: {resp.url}"
                )
            self._cookies = dict(c.cookies)

    async def _nonce_fetch(self) -> str:
        if self._nonce:
            return self._nonce
        if not self._cookies:
            await self._login()
        async with self._http_client(cookies=self._cookies) as c:
            resp = await c.get(f"{WP_URL}/wp-admin/tools.php?page=idx-scanner")
            # The scanner page outputs: var idxScannerData = {"nonce":"<10-char hex>",...}
            m = re.search(r'"nonce"\s*:\s*"([a-f0-9]{10,})"', resp.text)
            if not m:
                raise RuntimeError(
                    "Could not find idx_scanner_nonce in scanner page — "
                    "is the AiDX Scanner plugin active and the user an admin?"
                )
            self._nonce   = m.group(1)
            self._cookies = dict(c.cookies)
        return self._nonce

    # ── AJAX ──────────────────────────────────────────────────────────────────
    async def ajax(self, action: str, extra: dict | None = None) -> dict:
        nonce = await self._nonce_fetch()
        payload = {"action": action, "nonce": nonce, **(extra or {})}
        async with self._http_client(cookies=self._cookies) as c:
            resp = await c.post(f"{WP_URL}/wp-admin/admin-ajax.php", data=payload)
            resp.raise_for_status()
            return resp.json()

    # ── REST API ──────────────────────────────────────────────────────────────
    async def rest(self, method: str, path: str, data: dict | None = None) -> Any:
        url = f"{WP_URL}/wp-json/wp/v2/{path}"
        async with self._http_client(auth=self._wp_auth) as c:
            if method.upper() == "GET":
                resp = await c.get(url)
            else:
                resp = await c.request(method.upper(), url, json=data)
            resp.raise_for_status()
            return resp.json()


wp = WordPressClient()


# ── iHomeFinder / IMPress client ──────────────────────────────────────────────
class IHFClient:
    """
    Calls the iHomeFinder REST API to fetch saved searches and listing pages.
    API docs: https://ihomefinder.com/resources/api/
    """
    BASE = "https://api.ihomefinder.com/v1"

    async def get(self, path: str) -> Any:
        if not IHF_API_KEY:
            return {"error": "IHF_API_KEY not configured — skipping iHomeFinder lookup"}
        async with httpx.AsyncClient(timeout=15.0) as c:
            resp = await c.get(
                f"{self.BASE}/{path.lstrip('/')}",
                headers={"Authorization": f"Bearer {IHF_API_KEY}"},
            )
            if resp.status_code == 404:
                return []
            resp.raise_for_status()
            return resp.json()


ihf = IHFClient()


# ── Scanner tools ─────────────────────────────────────────────────────────────

@tool("scan_pages",
      "Scan all published WordPress pages for IDX Broker shortcodes, Gutenberg blocks, "
      "and direct IDX URLs. Returns page title, URL, IDX elements found, and the page "
      "section each element lives in.",
      {})
async def scan_pages(args):
    data = await wp.ajax("idx_scan_pages_db")
    return {"content": [{"type": "text", "text": json.dumps(data, indent=2)}]}


@tool("scan_posts",
      "Scan all WordPress posts and custom post types (including IDX listing wrapper posts) "
      "for IDX Broker content. Also checks postmeta for stored IDX Broker detail URLs "
      "(e.g. search.sanbornteam.com/idx/details/listing/...).",
      {})
async def scan_posts(args):
    data = await wp.ajax("idx_scan_post_links")
    return {"content": [{"type": "text", "text": json.dumps(data, indent=2)}]}


@tool("scan_widgets",
      "Scan all WordPress widget instances for IDX Broker / iHomeFinder (IMPress) content. "
      "Returns widget type, sidebar name, position on page (Header/Footer/Sidebar/etc.), "
      "and which pages the widget appears on.",
      {})
async def scan_widgets(args):
    data = await wp.ajax("idx_scan_widgets")
    return {"content": [{"type": "text", "text": json.dumps(data, indent=2)}]}


@tool("scan_navmenus",
      "Scan all WordPress navigation menus for IDX Broker and iHomeFinder links.",
      {})
async def scan_navmenus(args):
    data = await wp.ajax("idx_scan_navmenus")
    return {"content": [{"type": "text", "text": json.dumps(data, indent=2)}]}


@tool("scan_shortcodes",
      "Scan all posts, pages, and CPTs for IDX and iHomeFinder shortcodes "
      "([idx-platinum-*], [ihf-*], [impress-*], etc.).",
      {})
async def scan_shortcodes(args):
    data = await wp.ajax("idx_scan_shortcodes")
    return {"content": [{"type": "text", "text": json.dumps(data, indent=2)}]}


# ── iHomeFinder tools ─────────────────────────────────────────────────────────

@tool("ihf_get_saved_searches",
      "Fetch all saved searches configured in iHomeFinder / IMPress. "
      "Returns search ID, name, criteria, and the URL iHomeFinder generates for it.",
      {})
async def ihf_get_saved_searches(args):
    data = await ihf.get("saved-searches")
    return {"content": [{"type": "text", "text": json.dumps(data, indent=2)}]}


@tool("ihf_get_listing_pages",
      "Fetch the iHomeFinder listing page configuration — featured listings, "
      "search results, and detail page settings.",
      {})
async def ihf_get_listing_pages(args):
    data = await ihf.get("listing-pages")
    return {"content": [{"type": "text", "text": json.dumps(data, indent=2)}]}


# ── WordPress content tools ───────────────────────────────────────────────────

@tool("list_pages",
      "List all published WordPress pages with their IDs, slugs, and front-end URLs.",
      {})
async def list_pages(args):
    pages = await wp.rest("GET", "pages?per_page=100&status=publish&_fields=id,title,slug,link")
    out   = [{"id": p["id"], "title": p["title"]["rendered"], "slug": p["slug"], "url": p["link"]}
             for p in pages]
    return {"content": [{"type": "text", "text": json.dumps(out, indent=2)}]}


@tool("get_page",
      "Get the raw editable content of a WordPress page by its ID. "
      "Use this before editing so you can see the current shortcodes and blocks.",
      {"page_id": int})
async def get_page(args):
    p = await wp.rest("GET", f"pages/{args['page_id']}?context=edit")
    return {"content": [{"type": "text", "text": json.dumps({
        "id":      p["id"],
        "title":   p["title"]["raw"],
        "content": p["content"]["raw"],
        "url":     p["link"],
    }, indent=2)}]}


@tool("update_page",
      "Replace the content of a WordPress page. "
      "Always call get_page first, make the minimum necessary change, and explain the reason.",
      {"page_id": int, "content": str, "reason": str})
async def update_page(args):
    if not FIX_MODE:
        return {"content": [{"type": "text", "text":
            f"[DRY RUN] Would update page {args['page_id']}.\n"
            f"Reason: {args['reason']}\n"
            f"Run with --fix to apply changes."}]}
    result = await wp.rest("POST", f"pages/{args['page_id']}", {"content": args["content"]})
    return {"content": [{"type": "text", "text":
        f"✓ Updated page {args['page_id']} — {result.get('link', '')}\n"
        f"Reason: {args['reason']}"}]}


@tool("get_post",
      "Get the raw content of a WordPress post or custom post type item by ID.",
      {"post_id": int, "post_type": str})
async def get_post(args):
    pt = args.get("post_type", "posts")
    p  = await wp.rest("GET", f"{pt}/{args['post_id']}?context=edit")
    return {"content": [{"type": "text", "text": json.dumps({
        "id":      p["id"],
        "title":   p["title"]["raw"],
        "content": p["content"]["raw"],
        "url":     p["link"],
        "type":    p.get("type"),
    }, indent=2)}]}


@tool("update_post",
      "Replace the content of a WordPress post or custom post type item. "
      "Always call get_post first. Explain the reason for every change.",
      {"post_id": int, "post_type": str, "content": str, "reason": str})
async def update_post(args):
    if not FIX_MODE:
        return {"content": [{"type": "text", "text":
            f"[DRY RUN] Would update {args.get('post_type','posts')}/{args['post_id']}.\n"
            f"Reason: {args['reason']}\n"
            f"Run with --fix to apply changes."}]}
    pt     = args.get("post_type", "posts")
    result = await wp.rest("POST", f"{pt}/{args['post_id']}", {"content": args["content"]})
    return {"content": [{"type": "text", "text":
        f"✓ Updated {pt}/{args['post_id']} — {result.get('link', '')}\n"
        f"Reason: {args['reason']}"}]}


# ── Agent ─────────────────────────────────────────────────────────────────────

SYSTEM_PROMPT = f"""You are an IDX Broker + iHomeFinder audit agent for a WordPress real estate website.

Site: {WP_URL}
Mode: {"FIX — you may call update_page / update_post to apply changes" if FIX_MODE else "AUDIT ONLY — read-only, use update_page / update_post to describe what you would change but they will not apply"}

Your job:
1. Run all five scanner tools in parallel where possible:
   scan_pages, scan_posts, scan_widgets, scan_navmenus, scan_shortcodes
2. Optionally call ihf_get_saved_searches and ihf_get_listing_pages if an iHomeFinder
   API key is configured, to cross-reference saved searches with WordPress pages.
3. Compile a structured audit report with these sections:
   A. PAGES — every IDX/iHF element, which section of the page it lives in
   B. POSTS / CPTs — listing wrapper posts, IDX detail URLs from postmeta
   C. WIDGETS — widget type, sidebar name, position (Header/Footer/etc.), pages shown on
   D. NAV MENUS — menu name, item label, IDX/iHF destination URL
   E. SHORTCODES — every shortcode, which page/post it is in
   F. ISSUES — broken patterns, duplicate shortcodes, widgets in inactive sidebars,
      nav menu items pointing to pages that don't exist, missing iHF saved searches
4. If in FIX mode and issues are found, propose and apply minimal targeted fixes.
   Always call get_page / get_post first, change only the specific element that needs
   fixing, and document the reason for every update_page / update_post call.

Format the report clearly with headers. Be specific: include page IDs, URLs,
shortcode attribute values, widget keys, and sidebar IDs.
"""

AUDIT_PROMPT = (
    "Run a complete IDX Broker and iHomeFinder audit of this WordPress site. "
    "Run all scanner tools, compile the full report, and list every issue found. "
    + ("Then fix each issue you identified." if FIX_MODE else
       "Do not make any changes — audit only.")
)


async def main():
    server = create_sdk_mcp_server(
        "wp-idx-tools",
        tools=[
            # Scanner
            scan_pages, scan_posts, scan_widgets, scan_navmenus, scan_shortcodes,
            # iHomeFinder
            ihf_get_saved_searches, ihf_get_listing_pages,
            # WordPress content read/write
            list_pages, get_page, update_page, get_post, update_post,
        ],
    )

    options = ClaudeAgentOptions(
        model          = "claude-opus-4-6",
        system_prompt  = SYSTEM_PROMPT,
        mcp_servers    = {"wordpress": server},
        max_turns      = 40,
        permission_mode= "acceptEdits",
    )

    mode_label = "AUDIT + FIX" if FIX_MODE else "AUDIT ONLY"
    print(f"\n{'═'*60}")
    print(f"  AiDX Scanner Agent  —  {mode_label}")
    print(f"  Site: {WP_URL}")
    print(f"{'═'*60}\n")

    async with ClaudeSDKClient(options=options) as client:
        await client.query(AUDIT_PROMPT)
        async for message in client.receive_response():
            if isinstance(message, AssistantMessage):
                for block in message.content:
                    if isinstance(block, TextBlock) and block.text.strip():
                        print(block.text)
            elif isinstance(message, ResultMessage):
                print(f"\n{'─'*60}")
                print(f"Agent finished. Stop reason: {message.stop_reason}")


if __name__ == "__main__":
    anyio.run(main)
