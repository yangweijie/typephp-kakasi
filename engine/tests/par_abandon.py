"""F664: an abandoned 8001 stream must give its slot back promptly.

The cooperative server owns two slots, and a client closing mid-stream is the normal release path
(the request path records reason=client-gone). Detection happens when a write fails: a client that
stops reading leaves data unread, so closing sends RST and the next write fails. Nothing live tested
that release, and a leaked slot turns the next concurrent pair into a 503 for a caller nobody asked
to wait.

Two generations (a long one and a short one) are opened, read briefly and closed with SO_LINGER=0;
after each, two concurrent short streams must both be served within a few seconds. The server log
carries reason=client-gone for the abandoned request, so the release is visible, not inferred.
"""
import http.client
import json
import socket
import struct
import sys
import threading
import time

PORT = int(sys.argv[1]) if len(sys.argv) > 1 else 8001


def raw_abandon(prompt, max_tokens, hard):
    s = socket.create_connection(("127.0.0.1", PORT), timeout=60)
    body = json.dumps({"messages": [{"role": "user", "content": prompt}],
                       "max_tokens": max_tokens, "stream": True}).encode()
    head = ("POST /v1/chat/completions HTTP/1.1\r\nHost: localhost\r\n"
            "Content-Type: application/json\r\nContent-Length: %d\r\n"
            "Connection: close\r\n\r\n" % len(body)).encode()
    s.sendall(head + body)
    got = s.recv(400)
    if hard:
        # Linger 0 makes close() send RST instead of FIN: the next server write must fail.
        s.setsockopt(socket.SOL_SOCKET, socket.SO_LINGER, struct.pack("ii", 1, 0))
    s.close()
    return b"HTTP/1.1 200" in got


def short_pair():
    res = {}

    def one(i):
        try:
            c = http.client.HTTPConnection("127.0.0.1", PORT, timeout=120)
            c.request("POST", "/v1/chat/completions",
                      json.dumps({"messages": [{"role": "user", "content": "Say one word."}],
                                  "max_tokens": 8, "stream": True}),
                      {"Content-Type": "application/json"})
            r = c.getresponse()
            text = r.read().decode(errors="replace")
            c.close()
            res[i] = (r.status, "[DONE]" in text)
        except Exception as e:
            res[i] = (str(e), False)

    th = [threading.Thread(target=one, args=(i,)) for i in range(2)]
    for t in th:
        t.start()
    for t in th:
        t.join(timeout=120)
    return sum(1 for v in res.values() if v[0] == 200), res


def wait_pair(window):
    t0 = time.time()
    while time.time() - t0 < window:
        served, res = short_pair()
        if served == 2:
            return True, time.time() - t0, res
        time.sleep(1.0)
    return False, time.time() - t0, res


def main():
    c = http.client.HTTPConnection("127.0.0.1", PORT, timeout=10)
    c.request("GET", "/health")
    if "status" not in c.getresponse().read().decode():
        print("PAR-ABANDON: FAIL server not healthy")
        return 1
    c.close()

    a_ok = raw_abandon("Tell me a very long story about a lighthouse and the sea.", 400, True)
    a_free, a_wait, _ = wait_pair(6.0)
    b_ok = raw_abandon("Tell me a short story about the sea.", 48, True)
    b_free, b_wait, b_res = wait_pair(6.0)

    ok = a_ok and a_free and b_ok and b_free
    print("PAR-ABANDON long=%d freed_after=%.1fs short=%d freed_after=%.1fs"
          % (a_ok, a_wait, b_ok, b_wait))
    if ok:
        print("PAR-ABANDON: PASS (both abandoned streams released their slots; two new streams "
              "served each time)")
        return 0
    print("PAR-ABANDON: FAIL long=%d long_wait=%.1fs short=%d short_wait=%.1fs last=%r"
          % (a_ok, a_wait, b_ok, b_wait, b_res))
    return 1


if __name__ == "__main__":
    sys.exit(main())
