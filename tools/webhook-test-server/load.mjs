// Load check of the test HTTP server alone (step 1 of the webhook benchmark): it sends POST requests at a
// fixed rate, open loop (a new request does not wait for an answer), and then reads the request log of the
// server. Node.js with built-in modules only.
//
// Usage: node load.mjs <base-url> <rps> <delay-ms> <seconds>
//   make bench-webhook-server ARGS="<rps> <delay-ms> <seconds>"
//
// It prints one JSON line: the requests sent, the errors (no 200 answer), the records in the log, the
// records in each second of the log (by time_ms), and the 50th and 99th percentile and the maximum of the
// time of a request at the client. The server reaches the rate when each second of the log holds the rate
// within 1% and the 99th percentile stays near the delay.

import http from 'node:http';

const [base, rpsArg, delayArg, secondsArg] = process.argv.slice(2);
const rps = Number(rpsArg);
const delay = Number(delayArg);
const seconds = Number(secondsArg);
if (!base || !(rps > 0) || !(delay >= 0) || !(seconds > 0)) {
  console.error('Usage: node load.mjs <base-url> <rps> <delay-ms> <seconds>');
  process.exit(2);
}

const agent = new http.Agent({ keepAlive: true, maxSockets: 2000 });
const prefix = `/load-${Date.now()}`;

function post(path) {
  return new Promise((resolve) => {
    const started = Date.now();
    const req = http.request(base + path, { method: 'POST', agent, headers: { 'content-type': 'application/json' } }, (res) => {
      res.resume();
      res.on('end', () => resolve({ ok: res.statusCode === 200, ms: Date.now() - started }));
    });
    req.on('error', () => resolve({ ok: false, ms: Date.now() - started }));
    req.end('{"load":1}');
  });
}

const total = Math.round(rps * seconds);
const start = Date.now();
const pending = [];
let sent = 0;
await new Promise((done) => {
  const tick = setInterval(() => {
    const due = Math.min(total, Math.floor(((Date.now() - start) / 1000) * rps));
    while (sent < due) {
      pending.push(post(`${prefix}/${sent}?delay=${delay}`));
      sent++;
    }
    if (sent >= total) {
      clearInterval(tick);
      done();
    }
  }, 1);
});
const results = await Promise.all(pending);
const wallMs = Date.now() - start;

const log = await (await fetch(`${base}/_times?prefix=${prefix}`)).json();
await fetch(`${base}/_requests?prefix=${prefix}`, { method: 'DELETE' });
const perSecond = new Array(Math.ceil(seconds)).fill(0);
for (const [timeMs] of log) {
  const second = Math.floor((timeMs - start) / 1000);
  if (second >= 0 && second < perSecond.length) perSecond[second]++;
}
const times = results.map((r) => r.ms).sort((a, b) => a - b);
console.log(JSON.stringify({
  rps,
  delay_ms: delay,
  seconds,
  sent,
  errors: results.filter((r) => !r.ok).length,
  logged: log.length,
  wall_ms: wallMs,
  logged_each_second_min: Math.min(...perSecond),
  logged_each_second_max: Math.max(...perSecond),
  logged_each_second: perSecond,
  client_ms_p50: times[Math.floor(times.length * 0.5)],
  client_ms_p99: times[Math.floor(times.length * 0.99)],
  client_ms_max: times[times.length - 1],
}));
