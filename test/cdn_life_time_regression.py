#!/usr/bin/env python3
"""Check that a reload applies a new life_time to an http:// CDN origin.

CDN slaves synced by EasyPanel bind domains as http://ip:port/?life_time=60.
KCdnContainer caches origin pools, so a busy pool created with the old
life_time must not be reused after the value changes.

1. http://origin/ (no life_time): every request opens a new connection.
2. Reload with ?life_time=60: the requests share one connection.
3. Reload back without life_time: every request opens a new connection again.

Usage: python3 test/cdn_life_time_regression.py /absolute/path/to/kangle
Uses an isolated prefix and does not change the installed configuration.
"""
import http.client
import http.server
import os
import shutil
import signal
import socket
import socketserver
import subprocess
import sys
import tempfile
import threading
import time
from runtime_fixture import copy_server, stop_server

REQUESTS = 5


class Origin(http.server.BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'

    def setup(self):
        super().setup()
        self.server.connections += 1

    def do_GET(self):
        body = b'origin'
        self.send_response(200)
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, *args):
        pass


def free_port():
    s = socket.socket()
    s.bind(('127.0.0.1', 0))
    port = s.getsockname()[1]
    s.close()
    return port


def write_config(root, port, origin_dir):
    cfg = "<config><worker_thread>2</worker_thread><timeout rw='5' connect='2'/>"
    cfg += "<listen ip='127.0.0.1' port='%d' type='http'/>" % port
    cfg += "<request action='allow'/><response action='allow'/>"
    cfg += "<cache default='0' memory='0' disk='0'/>"
    cfg += "<vh name='cdn' doc_root='www' inherit='on'>"
    cfg += "<host dir='%s'>cdn.test</host></vh></config>" % origin_dir
    with open(root + '/etc/config.xml', 'w') as f:
        f.write(cfg)


def fetch(port, path):
    conn = http.client.HTTPConnection('127.0.0.1', port, timeout=5)
    try:
        conn.request('GET', path, headers={'Host': 'cdn.test'})
        res = conn.getresponse()
        return res.status, res.read()
    finally:
        conn.close()


def new_connections(origin, port, tag):
    before = origin.connections
    for i in range(REQUESTS):
        status, body = fetch(port, '/%s/%d' % (tag, i))
        assert status == 200 and body == b'origin', (tag, status, body)
    return origin.connections - before


def reload(proc, executable):
    if os.name == 'nt':
        subprocess.check_call([executable, '-r'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    else:
        os.kill(proc.pid, signal.SIGHUP)
    time.sleep(1.5)


def run(binary):
    origin = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Origin) \
        if hasattr(http.server, 'ThreadingHTTPServer') else \
        type('T', (socketserver.ThreadingMixIn, http.server.HTTPServer), {})(('127.0.0.1', 0), Origin)
    origin.daemon_threads = True
    origin.connections = 0
    threading.Thread(target=origin.serve_forever, daemon=True).start()
    base = 'http://127.0.0.1:%d/' % origin.server_port
    root = tempfile.mkdtemp(prefix='kangle-cdn-life-time-')
    proc = None
    executable = None
    try:
        for name in ('etc', 'ext', 'bin', 'var', 'tmp', 'log', 'www'):
            os.makedirs(root + '/' + name)
        port = free_port()
        executable = copy_server(binary, root + '/bin')
        write_config(root, port, base)
        with open(root + '/console.log', 'wb') as log:
            proc = subprocess.Popen([executable, '-n', '-g'], stdout=log, stderr=log)
        for _ in range(100):
            try:
                socket.create_connection(('127.0.0.1', port), timeout=.1).close()
                break
            except OSError:
                assert proc.poll() is None, 'server exited: ' + root
                time.sleep(.05)
        else:
            raise AssertionError('server did not start: ' + root)

        got = new_connections(origin, port, 'close')
        assert got == REQUESTS, 'no life_time: %d origin connections (expected %d)' % (got, REQUESTS)
        print('PASS: no life_time opens one origin connection per request', flush=True)

        write_config(root, port, base + '?life_time=60')
        reload(proc, executable)
        got = new_connections(origin, port, 'keep')
        assert got == 1, 'life_time=60 after reload: %d origin connections (expected 1)' % got
        print('PASS: life_time=60 applies after reload and reuses one connection', flush=True)

        write_config(root, port, base)
        reload(proc, executable)
        got = new_connections(origin, port, 'close-again')
        assert got == REQUESTS, 'life_time removed: %d origin connections (expected %d)' % (got, REQUESTS)
        print('PASS: removing life_time applies after reload', flush=True)
        print('CDN life_time regression passed', flush=True)
    finally:
        if proc is not None:
            stop_server(proc, executable)
        origin.shutdown()
        origin.server_close()
        shutil.rmtree(root, ignore_errors=True)


if __name__ == '__main__':
    if len(sys.argv) != 2:
        print(__doc__)
        sys.exit(2)
    run(os.path.abspath(sys.argv[1]))
