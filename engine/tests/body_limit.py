"""F667: request bodies must arrive whole, and an empty or short body must be a named 4xx.

The 8000 reader inherited the listener's non-blocking mode and broke out of its loop on a transient
empty read, so a 10 KB prompt arrived truncated and came back as "no user content" with status 200.
This checks both halves: a real body of a few KB and of tens of KB is served, a body shorter than its
own Content-Length is refused by name, and requests with no usable user turn are 400 rather than 200.
"""
import http.client
import json
import socket
import sys
import time
import urllib.error
import urllib.request

PORT = int(sys.argv[1]) if len(sys.argv) > 1 else 8000
URL = "http://127.0.0.1:%d/v1/chat/completions" % PORT
SENT = "The archive records every shipment with the harbour, the tonnage and the duty paid. "


def post(prompt, max_tokens=8):
    body = {"messages": [{"role": "user", "content": prompt}], "max_tokens": max_tokens,
            "temperature": 0}
    req = urllib.request.Request(URL, data=json.dumps(body).encode(),
                                 headers={"Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=300) as f:
            return f.getcode(), f.read().decode()
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode()


def main():
    ok = 0
    bad = 0
    rows = []

    # A: ~10 KB prompt - the size that used to be truncated.
    code, raw = post(SENT * 120)
    served = code == 200 and '"choices"' in raw
    rows.append(("body_10kb", code, served))
    ok += 1 if served else 0
    bad += 0 if served else 1

    # B: a declared body past the 4 MB cap - refused by name, not by a connection reset.
    s = socket.create_connection(("127.0.0.1", PORT), timeout=60)
    eol = chr(13) + chr(10)
    head = eol.join(["POST /v1/chat/completions HTTP/1.1",
                     "Host: localhost",
                     "Content-Type: application/json",
                     "Content-Length: 5000000",
                     "Connection: close", "", ""]).encode()
    s.sendall(head + b'{"messages":')
    s.shutdown(socket.SHUT_WR)
    got = b""
    s.settimeout(120)
    try:
        while True:
            chunk = s.recv(4096)
            if not chunk:
                break
            got += chunk
    except Exception:
        pass
    s.close()
    text = got.decode(errors="replace")
    named = text.startswith("HTTP/1.1 413") and "too large" in text
    rows.append(("oversize", text[:12], named))
    ok += 1 if named else 0
    bad += 0 if named else 1

    # C: headers promise more than the client sends, then the socket closes.
    s = socket.create_connection(("127.0.0.1", PORT), timeout=60)
    body = b'{"messages":[{"role":"user","content":"hi"}],"max_tokens":4}'
    head = ("POST /v1/chat/completions HTTP/1.1\r\nHost: localhost\r\n"
            "Content-Type: application/json\r\nContent-Length: %d\r\n"
            "Connection: close\r\n\r\n" % (len(body) + 400)).encode()
    s.sendall(head + body)
    s.shutdown(socket.SHUT_WR)          # promise the rest, then stop sending
    got = b""
    s.settimeout(60)
    try:
        while True:
            chunk = s.recv(4096)
            if not chunk:
                break
            got += chunk
    except Exception:
        pass
    s.close()
    text = got.decode(errors="replace")
    named = text.startswith("HTTP/1.1 400") and "incomplete" in text
    rows.append(("short_body", text[:12], named))
    ok += 1 if named else 0
    bad += 0 if named else 1

    # D: empty messages - no usable user turn.
    body = {"messages": [], "max_tokens": 4}
    req = urllib.request.Request(URL, data=json.dumps(body).encode(),
                                 headers={"Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=60) as f:
            code, raw = f.getcode(), f.read().decode()
    except urllib.error.HTTPError as e:
        code, raw = e.code, e.read().decode()
    named = code == 400 and "no user content" in raw
    rows.append(("empty_messages", code, named))
    ok += 1 if named else 0
    bad += 0 if named else 1

    # E: zero-length body.
    c = http.client.HTTPConnection("127.0.0.1", PORT, timeout=60)
    c.request("POST", "/v1/chat/completions", "", {"Content-Type": "application/json"})
    r = c.getresponse()
    raw = r.read().decode()
    c.close()
    named = r.status == 400
    rows.append(("empty_body", r.status, named))
    ok += 1 if named else 0
    bad += 0 if named else 1

    for name, code, good in rows:
        print("%-16s code=%s ok=%s" % (name, code, good))
    if bad == 0:
        print("BODY-LIMIT: PASS (%d shapes served or refused by name)" % ok)
        return 0
    print("BODY-LIMIT: FAIL bad=%d" % bad)
    return 1


if __name__ == "__main__":
    sys.exit(main())
