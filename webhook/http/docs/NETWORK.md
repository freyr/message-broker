# Network rules

The URL of a subscription is input from a user of your application. The rules
of this document protect your internal systems from a request to them: the
private network rule, the proxy rule, the `https` rule and the rule of no
redirects. The request and the configuration of the sender are in
[SENDER.md](SENDER.md).

## The private network rule

The private network check is on by default (`blockPrivateNetworks: true`). It
refuses a destination in a private network, with the default subnet list of
Symfony's `NoPrivateNetworkHttpClient` (`IpUtils::PRIVATE_SUBNETS`: for example
`10.0.0.0/8`, `127.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `169.254.0.0/16`,
`::1` and `fc00::/7`).

| Destination | Result |
|---|---|
| A host that is an IP literal in a private network | refused for all time, reason code `invalid-destination`, before the request |
| A host name that resolves to a private address | failed, category `blocked`; no request |
| A host name that does not resolve | failed, category `dns`; no request |

- With the check on, the sender resolves each new host name itself, inside
  the DNS allowance of the call (see
  [SENDER.md](SENDER.md#time-of-one-send-call)), and pins the address for the
  request. The check thus tests the same address that the client connects to.
  `NoPrivateNetworkHttpClient` decorates the client and checks the address
  again at the connect.
- The sender keeps a resolved address until the reset of the client
  (`clientResetIntervalMs`, default 5 minutes). A DNS change of a host thus
  takes effect after the next reset.
- A host name that resolves to a private address is a failed request, not a
  refusal: the client reports it as a transport error of the same class as
  each other one. Its deliveries use the full retry schedule before they
  reach the dead letters.
- **Turn the check off** (`blockPrivateNetworks: false`) only for a test, or for
  a deployment whose receivers are internal. The pre-check of an IP literal
  follows the same switch.

## The proxy rule

With an HTTP proxy, the check can test only the address of the proxy, not of
the receiver. The proxy and the check are thus exclusive:

- A proxy is used only when `HttpSenderConfig::$proxy` names it. The sender
  ignores the proxy environment variables (`HTTP_PROXY`, `HTTPS_PROXY`,
  `NO_PROXY`) and a proxy in a client that your application gives.
- With a proxy, the private network check and the pre-check of an IP literal
  are off. **The proxy must then refuse internal addresses**, as the Standard
  Webhooks specification advises. The sender does not check the filter of the
  proxy.
- The proxy URL is never part of an exception message, because it can hold
  credentials.

## The https rule

By default, the sender sends only to `https` URLs. A URL with an other scheme
is refused for all time, with the reason code `invalid-destination`.
`allowHttp: true` permits `http` URLs too, for example for a test or for an
internal receiver. A URL with credentials (`https://user:pass@host/`) is
refused for all time.

## No redirects

The sender follows no redirect. A 3xx response is a failed request, and the
retry limit ends it. A redirect target would need the same checks as the URL
of the subscription, and the Standard Webhooks specification says not to
follow redirects.
