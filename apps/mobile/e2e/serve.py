"""Static server for the Expo web preview with /v1 proxied to the local API (Host: api.platform.test)."""
import http.server, urllib.request, os, sys
ROOT='/tmp/expo-web'
class H(http.server.SimpleHTTPRequestHandler):
    def __init__(s,*a,**k): super().__init__(*a,directory=ROOT,**k)
    def log_message(s,*a): pass
    def proxy(s):
        body = s.rfile.read(int(s.headers.get('Content-Length') or 0)) if s.command in ('POST','PUT','PATCH','DELETE') else None
        req = urllib.request.Request('http://127.0.0.1:8000'+s.path, data=body, method=s.command)
        for k,v in s.headers.items():
            if k.lower() not in ('host','content-length','connection'): req.add_header(k,v)
        req.add_header('Host','api.platform.test')
        try: r = urllib.request.urlopen(req)
        except urllib.error.HTTPError as e: r = e
        data = r.read(); s.send_response(r.status)
        for k,v in r.headers.items():
            if k.lower() not in ('transfer-encoding','connection','content-length'): s.send_header(k,v)
        s.send_header('Content-Length',str(len(data))); s.end_headers(); s.wfile.write(data)
    def do_GET(s):
        if s.path.startswith('/v1/'): return s.proxy()
        if not os.path.exists(ROOT+s.path.split('?')[0]) or s.path=='/': s.path='/index.html'
        return super().do_GET()
    do_POST=do_PUT=do_PATCH=do_DELETE=proxy
http.server.ThreadingHTTPServer(('127.0.0.1',8090),H).serve_forever()
