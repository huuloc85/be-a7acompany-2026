# Cap7A integration — deployment required

Only authenticated Super Admin via existing Sanctum + token expiration + api.permission.
No changes to existing auth/RBAC functions. New routes:

- GET /api/cap7a/status
- POST /api/cap7a/commands (confirmed, request_id UUID, session_id UUID, action, values)
- POST /api/cap7a/screen-sessions (confirmed)
- POST /api/cap7a-screen/authorize (gateway shared secret AND viewer ticket, not public access)

## Backend environment (never put credentials in VITE variables)

```dotenv
CAP7A_ENABLED=true
CAP7A_TB_URL=https://eu.thingsboard.cloud
CAP7A_TB_DEVICE_ID=REPLACE_WITH_DEVICE_UUID
CAP7A_TB_API_KEY="REPLACE_PRIVATELY_WITH_USER_API_KEY"
CAP7A_COMMANDS_ENABLED=false
CAP7A_SCREEN_ENABLED=false
CAP7A_SCREEN_WS_URL=wss://YOUR_GATEWAY_HOST/screen
CAP7A_SCREEN_GATEWAY_KEY=REPLACE_WITH_RANDOM_SECRET_AT_LEAST_32_CHARACTERS
```

Use a ThingsBoard user scoped to this device where the subscription permits; do not use
the device MQTT token here. API keys use `X-Authorization: ApiKey ...` and take priority
over username/password. This works with accounts signed in through GitHub: generate a
user API key under Account → Security → API keys. Store only its value, without the
`ApiKey` prefix. Rotate before expiry and never commit it or expose it to the FE.
Alternatively, omit the API key and set `CAP7A_TB_USERNAME` / `CAP7A_TB_PASSWORD` for
password login. JWT is cached server-side for 5 minutes; failures are sanitized.
An invalid API key does not trigger a login fallback or an automatic RPC retry.

After deploying the updated service/config and editing the hosting environment, run:

```bash
php artisan config:clear
php artisan config:cache
```

Reload the FE Cap7A page. `configured: true` confirms backend configuration, not device
connectivity; `online: true` also requires fresh Jetson telemetry on the configured UUID.

Use a shared persistent cache (Redis recommended) for all BE replicas: command locks,
deduplication, screen tickets and revocation must be shared. Do not use array cache in
production. Refresh config via your normal deployment process. No database migration.

Jetson publishes cap7a_state schema=1 every 5s; snapshot receive/sample times older than
20s or online=false disables commands. FE polls every 5s. Test commands with outputs
isolated before enabling CAP7A_COMMANDS_ENABLED and the local permission checkbox.
Request timeout has UNKNOWN outcome; never retry automatically. RPC is nonpersistent.
Logs record actor/action/request UUID, never tokens. Do not enable HTTP body/header logging
for these routes or gateway tickets. Result timestamps refer to the result mode (RUN/TEST).

## Read-only desktop: dedicated gateway host required

This cannot run on static FE hosting. A Linux service host needs VPN/SSH reachability to
Jetson, HTTPS/WSS reverse proxy, and HTTPS reachability to Laravel. No public port 5900.
The gateway is separate from PHP workers, but its source lives in this BE repo.

On Jetson, during maintenance, start a dedicated **server-side view-only** x11vnc endpoint
(verify Xorg display/auth path for this boot; do not reuse a writable endpoint):

```bash
x11vnc -display :0 -auth /run/user/1000/gdm/Xauthority \
  -rfbauth /home/ubuntu/.vnc/passwd -localhost -rfbport 5901 \
  -viewonly -forever -shared
```

On the gateway host create an SSH tunnel (host key verified, no disabled host-key checking):

```bash
ssh -N -o ExitOnForwardFailure=yes -o ServerAliveInterval=30 \
  -L 127.0.0.1:5903:127.0.0.1:5901 ubuntu@100.103.141.11
```

Run tools/cap7a-screen/gateway.py in a dedicated Python environment with its requirements.
Provide environment via a 0600 service EnvironmentFile, not checked into Git:

```dotenv
CAP7A_AUTH_URL=https://YOUR_BE_HOST/api/cap7a-screen/authorize
CAP7A_FE_ORIGIN=https://YOUR_FE_HOST
CAP7A_SCREEN_GATEWAY_KEY=SAME_SECRET_AS_LARAVEL
CAP7A_VNC_PORT=5903
CAP7A_VNC_VIEW_ONLY_CONFIRMED=yes
```

Set the last flag ONLY after verifying x11vnc -viewonly actually rejects keyboard/mouse
from a VNC client. noVNC viewOnly is an additional UI restriction, not a security boundary.
The transport can forward RFB input, so attaching it to a writable VNC server is unsafe.

Gateway binds 127.0.0.1:6081. Reverse proxy HTTPS /screen to it with WebSocket Upgrade,
HTTP/1.1 and read timeout >300s. **Disable access logging of /screen query strings**:
tickets are bearer secrets. Restrict Origin to the configured FE and firewall direct
gateway access. No arbitrary VNC target can be supplied by the browser.

Tickets connect once within 30 seconds and last at most 5 minutes. Gateway rechecks user,
role and login token every 10s; revocation closes the stream (plus HTTP timeout). A new
viewer ticket revokes the previous one for that user. VNC password is entered by user,
kept in memory only, never stored server-side. FE unmount closes the viewer. Maximum 2
gateway streams. No clipboard write, mouse/keyboard, restart/shutdown buttons provided.

## Acceptance (not executed on production by this change)

1. Non-superadmin/expired login denied; no credentials in responses/logs.
2. Check real telemetry, clock sync, lost MQTT, offline >20s, app restart/session change.
3. Reject command without local permission, confirmation, duplicate ID, stale session.
4. With PLC isolated test RUN/STOP/TEST/reset/ROI/threshold and uncertain timeout handling.
5. Verify view-only at VNC server, HTTPS, Origin denial, ticket replay/expiry, role/login
   revocation and tunnel loss. Check actual camera frame rate/CPU while viewing desktop.

The separate raw-camera snapshot endpoint is not implemented; live desktop includes
the app's camera display. No deployment, remote control or credential configuration is
performed automatically by these source changes.
