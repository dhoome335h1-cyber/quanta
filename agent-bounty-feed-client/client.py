#!/usr/bin/env python3
"""Zero-dependency client for Agent Bounty Feed (Python 3.9+)."""
import argparse
import json
import sys
import urllib.error
import urllib.request

BASE = "https://agent-bounty-feed.dailydollar.workers.dev"
MCP = BASE + "/mcp"
FEED = "https://dailydollar-bot.github.io/agent-bounty-feed/feed.json"


def request(url, payload=None, timeout=20):
    data = None if payload is None else json.dumps(payload).encode()
    headers = {"Accept": "application/json, text/event-stream"}
    if data is not None:
        headers["Content-Type"] = "application/json"
    req = urllib.request.Request(url, data=data, headers=headers)
    with urllib.request.urlopen(req, timeout=timeout) as response:
        raw = response.read().decode("utf-8", errors="replace")
        ctype = response.headers.get("content-type", "")
    if "text/event-stream" in ctype or raw.startswith("event:"):
        chunks = []
        for line in raw.splitlines():
            if line.startswith("data:"):
                value = line[5:].strip()
                if value and value != "[DONE]":
                    try:
                        chunks.append(json.loads(value))
                    except json.JSONDecodeError:
                        pass
        if chunks:
            return chunks[-1]
    try:
        return json.loads(raw)
    except json.JSONDecodeError:
        return {"raw": raw, "content_type": ctype}


def mcp_call(method, params=None, request_id=1):
    return request(MCP, {"jsonrpc": "2.0", "id": request_id,
                         "method": method, "params": params or {}})


def mcp_tool(name, arguments=None):
    try:
        return mcp_call("tools/call", {"name": name, "arguments": arguments or {}}, 2)
    except (urllib.error.HTTPError, urllib.error.URLError):
        mcp_call("initialize", {
            "protocolVersion": "2024-11-05", "capabilities": {},
            "clientInfo": {"name": "abf-lite", "version": "1.0.0"}
        }, 1)
        return mcp_call("tools/call", {"name": name, "arguments": arguments or {}}, 2)


def fallback_feed():
    return request(FEED)


def unwrap(value):
    if isinstance(value, dict) and "result" in value:
        result = value["result"]
        if isinstance(result, dict) and "content" in result:
            texts = [x.get("text", "") for x in result["content"] if x.get("type") == "text"]
            if texts:
                try:
                    return json.loads(texts[0])
                except json.JSONDecodeError:
                    return texts[0] if len(texts) == 1 else texts
        return result
    return value


def main():
    parser = argparse.ArgumentParser(description="Agent Bounty Feed read-only client")
    sub = parser.add_subparsers(dest="command", required=True)
    sub.add_parser("stats", help="show feed statistics")
    ls = sub.add_parser("list", help="list bounties from the public feed")
    ls.add_argument("--limit", type=int, default=10)
    ls.add_argument("--json", action="store_true", help="print JSON")
    ls.add_argument("--no-friction", action="store_true",
                    help="hide tasks declaring KYC/stake/bond/purchase/human-only requirements")
    sub.add_parser("earn-now", help="show the live earn-now endpoint")
    args = parser.parse_args()
    try:
        if args.command == "stats":
            try:
                out = unwrap(mcp_tool("get_feed_stats"))
            except Exception:
                out = {"note": "MCP stats unavailable; feed fallback below", "feed": fallback_feed()}
        elif args.command == "earn-now":
            out = request(BASE + "/earn-now")
        else:
            try:
                out = unwrap(mcp_tool("list_bounties", {"limit": max(1, min(args.limit, 100))}))
            except Exception:
                out = fallback_feed()
            if args.no_friction:
                forbidden = {"kyc", "stake", "bond", "purchase", "human_only",
                             "funding-needed", "verification-unavailable"}
                def keep(item):
                    if not isinstance(item, dict):
                        return True
                    reqs = item.get("requires", [])
                    status = str(item.get("status", "")).lower()
                    return not (forbidden & {str(x).lower() for x in reqs}) and status not in forbidden
                if isinstance(out, dict):
                    for key in ("bounties", "items", "results", "data"):
                        if isinstance(out.get(key), list):
                            out[key] = [x for x in out[key] if keep(x)][:args.limit]
                            break
                elif isinstance(out, list):
                    out = [x for x in out if keep(x)][:args.limit]
        print(json.dumps(out, ensure_ascii=False, indent=2))
    except (urllib.error.URLError, urllib.error.HTTPError, TimeoutError) as exc:
        print(f"Network/API error: {exc}", file=sys.stderr)
        sys.exit(2)


if __name__ == "__main__":
    main()
