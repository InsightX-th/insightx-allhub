"""Threaded local S3 fixture: signed streaming, bounded concurrency and retry."""
from http.server import ThreadingHTTPServer, BaseHTTPRequestHandler
import hashlib, hmac, json, re, sys, threading, time
from urllib.parse import urlsplit
lock = threading.Lock()
state = dict(active=0, peak=0, calls={}, invalid=0)
SECRET = b'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY'
def signature(method, path, headers):
    m = re.search(r'Credential=[^/]+/(\d{8})/([^/]+)/s3/aws4_request, SignedHeaders=([^,]+), Signature=([0-9a-f]+)', headers.get('Authorization', ''))
    if not m: return False
    date, region, signed, sig = m.groups()
    uri = urlsplit(path)
    canonical = '\n'.join([method, uri.path, uri.query, ''.join(k+':'+headers.get(k, '').strip()+'\n' for k in signed.split(';')), signed, headers.get('x-amz-content-sha256', '')])
    scope = f'{date}/{region}/s3/aws4_request'
    text = '\n'.join(['AWS4-HMAC-SHA256', headers.get('x-amz-date', ''), scope, hashlib.sha256(canonical.encode()).hexdigest()])
    key = b'AWS4'+SECRET
    for v in [date, region, 's3', 'aws4_request']: key = hmac.new(key, v.encode(), hashlib.sha256).digest()
    return hmac.new(key, text.encode(), hashlib.sha256).hexdigest() == sig
class Handler(BaseHTTPRequestHandler):
    def log_message(self, *args): pass
    def send(self, code, data):
        self.send_response(code); self.send_header('Content-Length', str(len(data))); self.end_headers(); self.wfile.write(data)
    def do_GET(self):
        if self.path == '/__stats':
            with lock: data = json.dumps(state).encode()
            return self.send(200, data)
        self.transfer()
    def do_PUT(self): self.transfer()
    def transfer(self):
        with lock:
            state['active'] += 1; state['peak'] = max(state['peak'], state['active'])
            key = self.command+' '+self.path
            state['calls'][key] = state['calls'].get(key,0)+1
            attempt = state['calls'][key]
        try:
            valid = signature(self.command, self.path, self.headers)
            if self.command == 'PUT':
                remaining = int(self.headers.get('Content-Length', 0)); sha = hashlib.sha256()
                while remaining:
                    chunk = self.rfile.read(min(65536,remaining)); sha.update(chunk); remaining -= len(chunk)
                valid = valid and sha.hexdigest() == self.headers.get('x-amz-content-sha256')
            if not valid:
                with lock: state['invalid'] += 1
                return self.send(403,b'bad signature')
            time.sleep(.12)
            if 'retry' in self.path and attempt == 1: return self.send(503,b'retry')
            if 'fail' in self.path: return self.send(403,b'denied')
            if 'truncated' in self.path:
                self.send_response(200); self.send_header('Content-Length','100'); self.end_headers(); self.wfile.write(b'partial'); return
            self.send(200,b'' if self.command == 'PUT' else ('object:'+self.path).encode())
        finally:
            with lock: state['active'] -= 1
# Python's default listen backlog is 5: with 16 parallel transfers Linux drops
# the overflow SYNs and each retries after ~1 s, which made the parallel path
# look slower than the serial one on CI. Real S3/R2 have no such limit.
ThreadingHTTPServer.request_queue_size = 128
ThreadingHTTPServer(('127.0.0.1', int(sys.argv[1])), Handler).serve_forever()
