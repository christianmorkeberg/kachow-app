# Pure helpers for the Kachow home agent: no hardware, no network, so they also run (and are
# tested) on a normal computer with plain Python. See test_agent_lib.py.


def split_url(url):
    """'https://host:8443/a/b?x=1' -> (True, 'host', 8443, '/a/b?x=1')."""
    proto, rest = url.split("://", 1)
    https = proto == "https"
    hostport, slash, path = rest.partition("/")
    path = "/" + path if slash else "/"
    host, colon, port = hostport.partition(":")
    port = int(port) if colon else (443 if https else 80)
    return https, host, port, path


def build_request(method, host, path, headers=None, body=None):
    """The raw HTTP/1.1 request bytes. Always 'Connection: close' so the answer ends at EOF."""
    lines = ["%s %s HTTP/1.1" % (method, path), "Host: %s" % host, "Connection: close",
             "User-Agent: Kachow-Pico/1"]
    for k, v in (headers or {}).items():
        lines.append("%s: %s" % (k, v))
    data = b""
    if body is not None:
        data = body.encode() if isinstance(body, str) else body
        lines.append("Content-Type: application/json")
        lines.append("Content-Length: %d" % len(data))
    return ("\r\n".join(lines) + "\r\n\r\n").encode() + data


def dechunk(body):
    """Decode a 'Transfer-Encoding: chunked' body."""
    out = b""
    i = 0
    while True:
        j = body.find(b"\r\n", i)
        if j < 0:
            break
        size_text = body[i:j].split(b";")[0].decode().strip()
        size = int(size_text, 16) if size_text else 0
        if size == 0:
            break
        out += body[j + 2:j + 2 + size]
        i = j + 2 + size + 2
    return out


def parse_response(raw):
    """Raw HTTP response bytes -> (status:int, headers:dict, body:bytes)."""
    head, _sep, body = raw.partition(b"\r\n\r\n")
    lines = head.split(b"\r\n")
    status = int(lines[0].split(b" ", 2)[1])
    headers = {}
    for line in lines[1:]:
        k, _colon, v = line.partition(b":")
        headers[k.strip().lower().decode()] = v.strip().decode()
    if headers.get("transfer-encoding", "").lower() == "chunked":
        body = dechunk(body)
    return status, headers, body


def response_complete(buf):
    """True once buf holds a whole response (so we needn't wait for the peer to close)."""
    head, sep, body = buf.partition(b"\r\n\r\n")
    if not sep:
        return False
    lower = head.lower()
    marker = b"content-length:"
    k = lower.find(marker)
    if k >= 0:
        end = lower.find(b"\r\n", k)
        n = int(lower[k + len(marker):end if end >= 0 else len(lower)].strip())
        return len(body) >= n
    if b"transfer-encoding: chunked" in lower:
        return body.endswith(b"0\r\n\r\n")
    return False


def path_allowed(prefixes, path):
    """A request path is allowed only if it IS one of the prefixes or sits under one
    ('/clip/v2/resource/light' allows '/clip/v2/resource/light/abc' but not '/clip/v2/resource/lightx')."""
    if ".." in path or "//" in path:
        return False
    bare = path.split("?")[0]
    for p in prefixes:
        p = p.rstrip("/")
        if bare == p or bare.startswith(p + "/"):
            return True
    return False
