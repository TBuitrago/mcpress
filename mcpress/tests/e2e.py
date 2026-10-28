#!/usr/bin/env python3
"""MCPress end-to-end smoke test against a running TEST site (it creates and deletes content).

    MCPRESS_URL=http://localhost:8088/wp-json/mcpress/v1/mcp MCPRESS_KEYS=tests/.keys.json python3 tests/e2e.py

MCPRESS_KEYS is a JSON file {"full": "mcpk_…", "approval": "…", "readonly": "…", "auto": "…"}
(bash tests/env.sh keys creates it). Python 3 standard library only.
"""
import json
import os
import sys
import urllib.error
import urllib.request

URL = os.environ.get("MCPRESS_URL", "http://localhost:8088/wp-json/mcpress/v1/mcp")
KEYS = json.load(open(os.environ.get("MCPRESS_KEYS", os.path.join(os.path.dirname(__file__), ".keys.json"))))
PASS, FAIL = 0, 0
_id = 0


def post(body, key=None, headers=None):
    h = {"Content-Type": "application/json", "Accept": "application/json, text/event-stream"}
    if key:
        h["Authorization"] = "Bearer " + key
    h.update(headers or {})
    data = body if isinstance(body, bytes) else json.dumps(body).encode()
    req = urllib.request.Request(URL, data=data, headers=h, method="POST")
    try:
        r = urllib.request.urlopen(req, timeout=120)
        return r.status, dict(r.headers), r.read().decode()
    except urllib.error.HTTPError as e:
        return e.code, dict(e.headers), e.read().decode()


def rpc(mode, method, params=None):
    global _id
    _id += 1
    body = {"jsonrpc": "2.0", "id": _id, "method": method}
    if params is not None:
        body["params"] = params
    return json.loads(post(body, KEYS[mode])[2])


def call(mode, name, args=None):
    res = rpc(mode, "tools/call", {"name": name, "arguments": args or {}})["result"]
    text = res["content"][0]["text"]
    try:
        data = json.loads(text)
    except ValueError:
        data = text
    return res["isError"], data


def check(label, cond):
    global PASS, FAIL
    if cond:
        PASS += 1
    else:
        FAIL += 1
        print("FAIL:", label)


# Protocol
s, h, b = post({"jsonrpc": "2.0", "id": 1, "method": "ping"})
check("401 without key", s == 401 and "resource_metadata" in h.get("WWW-Authenticate", ""))
s, h, b = post({"jsonrpc": "2.0", "method": "notifications/initialized"}, KEYS["full"])
check("notification → 202", s == 202 and b == "")
s, h, b = post(b"{bad", KEYS["full"])
check("parse error → -32700", json.loads(b)["error"]["code"] == -32700)
init = rpc("full", "initialize", {"protocolVersion": "2025-06-18", "capabilities": {}, "clientInfo": {"name": "e2e", "version": "1"}})
check("initialize negotiates version", init["result"]["protocolVersion"] == "2025-06-18")
tools = {t["name"] for t in rpc("full", "tools/list")["result"]["tools"]}
check("full mode lists write tools", {"create_post", "write_file", "rest_request"} <= tools)
ro = {t["name"] for t in rpc("readonly", "tools/list")["result"]["tools"]}
check("read-only hides write tools", "create_post" not in ro and "get_post" in ro)

# Content round trip (full)
err, d = call("full", "create_post", {"title": "E2E", "content": "<p>Uno \"dos\" \\tres</p>", "status": "draft"})
check("create_post", not err)
pid = d["created"]["id"]
err, d = call("full", "edit_post_content", {"id": pid, "old_text": "Uno", "new_text": "One"})
check("edit_post_content", not err and d["replacements"] == 1)
err, d = call("full", "get_post", {"id": pid})
check("content intact (quotes, backslash)", d["content"] == "<p>One \"dos\" \\tres</p>")

# Modes
err, d = call("readonly", "create_post", {"title": "x"})
check("read-only refuses writes", err and d["error"] == "permission_denied")
err, d = call("approval", "update_post", {"id": pid, "title": "Needs approval"})
check("approval mode queues", not err and d["status"] == "approval_required")
err, d2 = call("approval", "check_approval", {"approval_id": d["approval_id"]})
check("check_approval pending", d2["status"] == "pending")
err, d = call("auto", "run_php", {"code": "return 1;"})
check("auto asks for critical", d.get("status") == "approval_required")
err, d = call("auto", "update_post", {"id": pid, "title": "Auto title"})
check("auto runs writes", not err and d["updated"]["title"] == "Auto title")

# Guards
err, d = call("full", "update_option", {"name": "mcpress_settings", "value": {}})
check("self-protection on options", err and d["error"] == "self_protection")
err, d = call("full", "db_query", {"sql": "SELECT * FROM {prefix}mcpress_tokens"})
check("self-protection on SQL", err and d["error"] == "self_protection")
err, d = call("full", "deactivate_plugin", {"plugin": "mcpress"})
check("cannot deactivate MCPress", err)
err, d = call("full", "db_query", {"sql": "SELECT user_pass FROM {prefix}users LIMIT 1"})
check("password hashes masked", not err and d["rows"][0]["user_pass"] == "[redacted]")
err, d = call("full", "write_file", {"path": "wp-content/mcpress-e2e/bad.php", "content": "<?php function ( {"})
check("PHP syntax checked", err and d["error"] == "php_syntax")

# REST bridge
err, d = call("full", "rest_request", {"route": "/wp/v2/posts/%d" % pid, "params": {"context": "edit"}})
check("rest_request GET", not err and d["status"] == 200)

# Cleanup
err, d = call("full", "delete_post", {"id": pid, "force": True})
check("delete_post force", not err)

print("%d passed, %d failed" % (PASS, FAIL))
sys.exit(1 if FAIL else 0)
