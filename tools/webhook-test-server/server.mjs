// Test HTTP server for the functional tests and the benchmark of the HTTP webhook sender.
//
// Node.js with the built-in `http` module only (no npm package): one event loop, so it holds many open
// requests at the same time. PHP's built-in server handles one request at a time and cannot do this.
//
// Endpoints: each path that does not start with `/_` answers a request with any method. The query sets
// the answer:
//   status=<code>       the status code (default 200)
//   delay=<ms>          wait this long before the answer (default 0; a large value is an endpoint that
//                       does not answer in time)
//   location=<url>      the Location header (for a redirect)
//   retry_after=<value> the Retry-After header
//   body=<text>         the response body (default empty)
//
// The request log: the server records each request when its body is complete, in memory, with the time
// of its arrival in epoch milliseconds (`time_ms`), the method, the path with the query, the headers (lower
// case names) and the body.
//   GET    /_requests?prefix=<path>  the records whose path starts with the prefix, as a JSON array in the
//                                    order of arrival (no prefix: all records)
//   DELETE /_requests                remove all records
//   GET    /_health                  200 when the server runs
//
// Each test uses its own path prefix, so parallel test workers can share one server.
//
// Usage: node server.mjs   (port from WEBHOOK_SERVER_PORT, default 8080; listens on all addresses)

import http from 'node:http';

const port = Number(process.env.WEBHOOK_SERVER_PORT || 8080);
const records = [];

function json(res, status, value) {
  const body = JSON.stringify(value);
  res.writeHead(status, { 'content-type': 'application/json', 'content-length': Buffer.byteLength(body) });
  res.end(body);
}

function control(req, res, url) {
  if (url.pathname === '/_health') {
    return json(res, 200, { status: 'ok', requests: records.length });
  }
  if (url.pathname === '/_requests' && req.method === 'GET') {
    const prefix = url.searchParams.get('prefix');
    return json(res, 200, prefix === null ? records : records.filter((r) => r.path.startsWith(prefix)));
  }
  if (url.pathname === '/_requests' && req.method === 'DELETE') {
    records.length = 0;
    return json(res, 200, { status: 'cleared' });
  }
  return json(res, 404, { error: 'unknown control path' });
}

function endpoint(req, res, url, timeMs) {
  const chunks = [];
  req.on('data', (chunk) => chunks.push(chunk));
  req.on('end', () => {
    records.push({
      time_ms: timeMs,
      method: req.method,
      path: req.url,
      headers: req.headers,
      body: Buffer.concat(chunks).toString('utf8'),
    });

    const query = url.searchParams;
    const status = Number(query.get('status') || 200);
    const delay = Number(query.get('delay') || 0);
    const headers = { 'content-type': 'text/plain' };
    if (query.has('location')) headers.location = query.get('location');
    if (query.has('retry_after')) headers['retry-after'] = query.get('retry_after');
    const body = query.get('body') || '';

    const answer = () => {
      if (res.destroyed) return;
      headers['content-length'] = Buffer.byteLength(body);
      res.writeHead(status, headers);
      res.end(body);
    };
    if (delay > 0) {
      const timer = setTimeout(answer, delay);
      res.on('close', () => clearTimeout(timer)); // the client gave up: no answer
    } else {
      answer();
    }
  });
}

const server = http.createServer((req, res) => {
  const timeMs = Date.now();
  const url = new URL(req.url, 'http://test-server');
  if (url.pathname.startsWith('/_')) {
    req.resume();
    req.on('end', () => control(req, res, url));
    return;
  }
  endpoint(req, res, url, timeMs);
});

// Keep idle connections longer than the client does (curl reuses a connection for up to 118 s), so the
// server never closes a connection that the client is about to reuse.
server.keepAliveTimeout = 120_000;
server.headersTimeout = 125_000;
server.requestTimeout = 0;

server.listen(port, () => {
  console.log(`webhook test server on port ${port}`);
});

for (const signal of ['SIGINT', 'SIGTERM']) {
  process.on(signal, () => process.exit(0)); // PID 1 in a container: Node has no default handler there
}
