"""F669: unknown routes and unparseable bodies must be refused by name.

Before this, POST /v1/unknown, a body of '{"messages": [' and {"messages": "hi"} all answered 400
with "no user content" - the status was right, the reason was not, and an unknown route is a 404 in
the first place. Each case states the status AND the phrase that has to appear.
"""
import json
import socket
import sys
import urllib.error
import urllib.request

PORT = int(sys.argv[1]) if len(sys.argv) > 1 else 8000
PAR = (PORT == 8001)


def raw(path, body, method="POST"):
    s = socket.create_connection(("127.0.0.1", PORT), timeout=120)
    b = body.encode()
    head = ("%s %s HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/json\r\n"
            "Content-Length: %d\r\nConnection: close\r\n\r\n" % (method, path, len(b))).encode()
    s.sendall(head + b)
    out = b""
    s.settimeout(120)
    try:
        while True:
            c = s.recv(8192)
            if not c:
                break
            out += c
    except Exception:
        pass
    s.close()
    text = out.decode(errors="replace")
    code = text.split(" ")[1] if text.startswith("HTTP/1.1 ") else "?"
    return code, text


def post_json(path, body, stream=False):
    data = json.dumps(body).encode()
    req = urllib.request.Request("http://127.0.0.1:%d%s" % (PORT, path), data=data,
                                 headers={"Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=180) as f:
            return str(f.getcode()), f.read().decode()
    except urllib.error.HTTPError as e:
        return str(e.code), e.read().decode()


def main():
    rows = []
    ok = 0
    bad = 0

    def case(name, code, text, want_code, phrase):
        nonlocal ok, bad
        good = (code == want_code) and (phrase == "" or phrase in text)
        rows.append((name, code, good))
        if good:
            ok += 1
        else:
            bad += 1

    code, text = raw("/v1/unknown", "{}")
    case("unknown_route", code, text, "404", "unknown endpoint")

    code, text = raw("/v1/chat/completions", '{"messages": [')
    case("malformed_json", code, text, "400", "not JSON")

    code, text = raw("/v1/chat/completions", '{"messages": "hi", "max_tokens": 4}')
    case("messages_string", code, text, "400", "messages must be an array")

    code, text = raw("/v1/chat/completions", '{"stream": true}' if PAR else "{}")
    case("empty_object", code, text, "400", "no user content")

    # F670: methods. A wrong method used to be answered as an empty body (GET on a chat route was
    # "no user content"; POST /health served the HTML page).
    code, text = raw("/v1/chat/completions", "", "GET")
    case("get_on_chat", code, text, "405", "method not allowed")
    if "Allow: POST" not in text:
        rows.append(("get_on_chat_allow", code, False))
        bad += 1
    code, text = raw("/health", "", "POST")
    case("post_on_health", code, text, "405", "method not allowed")
    if "Allow: GET" not in text:
        rows.append(("post_on_health_allow", code, False))
        bad += 1
    code, text = raw("/v1/models/x", "", "DELETE")
    case("delete_models", code, text, "405", "method not allowed")

    msg = [{"role": "user", "content": "Say one word."}]
    body = {"messages": msg, "max_tokens": 4, "stream": True} if PAR else {"messages": msg, "max_tokens": 4}
    code, text = raw("/v1/models", "", "GET")
    case("get_models", code, text, "200", "")

    code, text = post_json("/v1/chat/completions", body)
    served = code == "200" and ("data: " in text if PAR else '"choices"' in text)
    rows.append(("valid_chat", code, served))
    if served:
        ok += 1
    else:
        bad += 1

    for name, code, good in rows:
        print("%-16s code=%s ok=%s" % (name, code, good))
    if bad == 0:
        print("ENDPOINT-SHAPE: PASS (%d routes/bodies answered by name)" % ok)
        return 0
    print("ENDPOINT-SHAPE: FAIL bad=%d" % bad)
    return 1


if __name__ == "__main__":
    sys.exit(main())
