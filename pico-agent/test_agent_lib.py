# Run on a normal computer (plain Python, no Pico needed):  python pico-agent/test_agent_lib.py
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from agent_lib import (build_request, dechunk, parse_response, path_allowed, response_complete,
                       split_url)

passed = failed = 0


def check(label, ok):
    global passed, failed
    if ok:
        passed += 1
        print("  ok   " + label)
    else:
        failed += 1
        print("  FAIL " + label)


print("split_url")
check("https default port", split_url("https://192.168.1.50") == (True, "192.168.1.50", 443, "/"))
check("http with port + path", split_url("http://h:8080/a/b?x=1") == (False, "h", 8080, "/a/b?x=1"))

print("build_request")
r = build_request("PUT", "bridge", "/clip/v2/resource/light/1", {"hue-application-key": "K"}, '{"on":{"on":true}}')
check("request line + Host + key header", r.startswith(b"PUT /clip/v2/resource/light/1 HTTP/1.1\r\nHost: bridge\r\n") and b"hue-application-key: K\r\n" in r)
check("Content-Length matches body", b"Content-Length: 18\r\n" in r and r.endswith(b'{"on":{"on":true}}'))
check("GET has no body headers", b"Content-Length" not in build_request("GET", "h", "/"))

print("parse_response / dechunk")
plain = b"HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: 11\r\n\r\n{\"ok\":true}"
check("plain response", parse_response(plain) == (200, {"content-type": "application/json", "content-length": "11"}, b'{"ok":true}'))
chunked = b"HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n5\r\nhello\r\n6\r\n world\r\n0\r\n\r\n"
check("chunked response is decoded", parse_response(chunked)[2] == b"hello world")
check("dechunk with chunk extension", dechunk(b"5;ext=1\r\nhello\r\n0\r\n\r\n") == b"hello")
check("404 status parsed", parse_response(b"HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\n\r\n")[0] == 404)

print("response_complete")
check("headers only is not complete", not response_complete(b"HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\n"))
check("full content-length body is complete", response_complete(b"HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nhello"))
check("partial body is not complete", not response_complete(b"HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nhel"))
check("chunked is complete at the 0 chunk", response_complete(chunked))
check("chunked mid-stream is not", not response_complete(b"HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n5\r\nhello\r\n"))

print("path_allowed")
allow = ["/clip/v2/resource/light", "/clip/v2/resource/scene/"]
check("exact prefix", path_allowed(allow, "/clip/v2/resource/light"))
check("under a prefix", path_allowed(allow, "/clip/v2/resource/light/abc-123"))
check("trailing-slash prefix still works", path_allowed(allow, "/clip/v2/resource/scene/9"))
check("query string ignored for matching", path_allowed(allow, "/clip/v2/resource/light?x=1"))
check("look-alike sibling is refused", not path_allowed(allow, "/clip/v2/resource/lightx"))
check("a different resource is refused", not path_allowed(allow, "/clip/v2/resource/bridge"))
check("'..' traversal is refused", not path_allowed(allow, "/clip/v2/resource/light/../bridge"))
check("'//' is refused", not path_allowed(allow, "//clip/v2/resource/light"))
check("empty allow-list refuses everything", not path_allowed([], "/clip/v2/resource/light"))

print("\n%d passed, %d failed" % (passed, failed))
sys.exit(1 if failed else 0)
