#!/usr/bin/env python3
from http.server import HTTPServer, BaseHTTPRequestHandler
import subprocess

CONNECTING_HTML = b"""<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>MARS APRS</title>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { background: #1a1a2e; color: #eee; font-family: Arial, sans-serif;
           display: flex; align-items: center; justify-content: center;
           height: 100vh; flex-direction: column; gap: 20px; }
    h1 { font-size: 3em; color: #4fc3f7; }
    p { font-size: 1.4em; color: #aaa; }
    .dots span { animation: blink 1.2s infinite; }
    .dots span:nth-child(2) { animation-delay: 0.4s; }
    .dots span:nth-child(3) { animation-delay: 0.8s; }
    @keyframes blink { 0%, 100% { opacity: 0.2; } 50% { opacity: 1; } }
  </style>
</head>
<body>
  <h1>MARS APRS</h1>
  <p>Connecting<span class="dots"><span>.</span><span>.</span><span>.</span></span></p>
  <script>
    const TARGET = 'https://mars-aprs.ddns.net/';
    function tryConnect() {
      fetch('https://mars-aprs.ddns.net/', { method: 'GET', mode: 'no-cors', cache: 'no-store' })
        .then(() => { window.location.href = TARGET; })
        .catch(() => { setTimeout(tryConnect, 5000); });
    }
    setTimeout(tryConnect, 1000);
  </script>
</body>
</html>"""

class Handler(BaseHTTPRequestHandler):
    def _pna_headers(self):
        self.send_header('Access-Control-Allow-Origin', '*')
        self.send_header('Access-Control-Allow-Private-Network', 'true')
        self.send_header('Access-Control-Allow-Methods', 'GET, OPTIONS')

    def do_OPTIONS(self):
        self.send_response(204)
        self._pna_headers()
        self.end_headers()

    def do_GET(self):
        if self.path == '/exit':
            self.send_response(200)
            self._pna_headers()
            self.end_headers()
            self.wfile.write(b'ok')
            subprocess.Popen(['pkill', 'chromium'])
        elif self.path == '/':
            self.send_response(200)
            self.send_header('Content-Type', 'text/html; charset=utf-8')
            self.send_header('Content-Length', str(len(CONNECTING_HTML)))
            self.end_headers()
            self.wfile.write(CONNECTING_HTML)
        else:
            self.send_response(404)
            self.end_headers()

    def log_message(self, *args):
        pass

HTTPServer(('127.0.0.1', 8080), Handler).serve_forever()
