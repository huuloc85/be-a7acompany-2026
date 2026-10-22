"""Private WS-to-VNC gateway. Run behind HTTPS proxy; never expose VNC TCP.

Requires a dedicated x11vnc -viewonly endpoint through a loopback SSH tunnel.
Authorization is rechecked every 10s; active sessions are renewed by the backend.
"""
import asyncio
import os
import re
import uuid
from urllib.parse import urlsplit
from aiohttp import ClientError, ClientSession, ClientTimeout, WSMsgType, web


class TemporaryAuthError(Exception):
    pass


def lease_deadline(grant, started):
    seconds = grant.get('remaining_seconds')
    if type(seconds) is not int or seconds <= 0:
        raise web.HTTPForbidden()
    return started + min(seconds, 300)


async def renew_access(client, config, ticket, lease, deadline):
    loop = asyncio.get_running_loop()
    while True:
        await asyncio.sleep(min(10, max(0, deadline - loop.time())))
        remaining = deadline - loop.time()
        if remaining <= 0:
            raise TimeoutError('Authorization lease expired')
        started = loop.time()
        try:
            grant = await asyncio.wait_for(authorize(client, config, ticket, lease, False), remaining)
        except (TemporaryAuthError, ClientError, OSError, asyncio.TimeoutError) as error:
            print('Screen: authorization temporarily unavailable:', type(error).__name__, flush=True)
            continue
        deadline = lease_deadline(grant, started)


def settings():
    url = os.environ['CAP7A_AUTH_URL']
    parsed = urlsplit(url)
    if parsed.scheme != 'https' and not (parsed.scheme == 'http' and parsed.hostname in ('127.0.0.1', 'localhost')):
        raise ValueError('Auth endpoint requires HTTPS or loopback')
    key = os.environ['CAP7A_SCREEN_GATEWAY_KEY']
    if len(key) < 32:
        raise ValueError('Gateway key must have at least 32 characters')
    if os.environ.get('CAP7A_VNC_VIEW_ONLY_CONFIRMED') != 'yes':
        raise ValueError('Configure and verify a server-side view-only VNC endpoint first')
    origins = {value.strip().rstrip('/') for value in os.environ['CAP7A_FE_ORIGIN'].split(',')}
    for origin in origins:
        parsed_origin = urlsplit(origin)
        # HTTP is only allowed for an explicitly configured local development UI.
        local_http = parsed_origin.scheme == 'http' and parsed_origin.hostname in ('localhost', '127.0.0.1', '::1')
        if (not parsed_origin.hostname or '*' in origin or parsed_origin.username is not None
                or parsed_origin.password is not None or parsed_origin.path
                or parsed_origin.query or parsed_origin.fragment
                or (parsed_origin.scheme != 'https' and not local_http)):
            raise ValueError('FE origins must be exact HTTPS origins or HTTP loopback origins')
        parsed_origin.port  # Validate any explicit port before starting the service.
    return {'auth_url': url, 'key': key, 'origins': origins,
            'vnc_password_file': os.environ.get('CAP7A_VNC_PASSWORD_FILE'),
            'vnc_port': int(os.environ.get('CAP7A_VNC_PORT', '5903'))}


async def authorize(client, config, ticket, lease, opening):
    async with client.post(config['auth_url'], json={'ticket': ticket, 'lease': lease, 'opening': opening},
                           headers={'X-Cap7A-Gateway-Key': config['key'], 'Accept': 'application/json'},
                           allow_redirects=False) as response:
        if response.status in (408, 409, 429) or response.status >= 500:
            raise TemporaryAuthError('HTTP ' + str(response.status))
        if response.status != 200:
            print('Screen: authorization rejected, HTTP', response.status, flush=True)
            raise web.HTTPForbidden()
        body = await response.json()
        if body.get('allowed') is not True or body.get('view_only') is not True:
            raise web.HTTPForbidden()
        return body


async def screen(request):
    config = request.app['config']
    if request.headers.get('Origin') not in config['origins']:
        raise web.HTTPForbidden()
    ticket = request.query.get('ticket', '')
    if not re.fullmatch('[a-f0-9]{64}', ticket):
        raise web.HTTPForbidden()
    if request.app['connections']['active'] >= 2:
        raise web.HTTPTooManyRequests()
    request.app['connections']['active'] += 1
    writer = None
    ws = None
    tasks = []
    try:
        lease = str(uuid.uuid4())
        async with ClientSession(timeout=ClientTimeout(total=8)) as client:
            started = asyncio.get_running_loop().time()
            grant = await authorize(client, config, ticket, lease, True)
            deadline = lease_deadline(grant, started)
            reader, writer = await asyncio.wait_for(asyncio.open_connection('127.0.0.1', config['vnc_port']), 5)
            password_file = config.get('vnc_password_file')
            if password_file:
                from vnc_auth import authenticate_vnc, authenticate_browser
                await asyncio.wait_for(authenticate_vnc(reader, writer, password_file), 10)
            ws = web.WebSocketResponse(protocols=('binary',), max_msg_size=1024 * 1024, heartbeat=20)
            await ws.prepare(request)
            initial = b''
            if password_file:
                initial = await asyncio.wait_for(authenticate_browser(ws), 10)
            if asyncio.get_running_loop().time() >= deadline:
                raise TimeoutError('Authorization lease expired during handshake')
            if password_file:
                # A slow handshake must not deliver frames after a logout/revocation.
                started = asyncio.get_running_loop().time()
                grant = await asyncio.wait_for(authorize(client, config, ticket, lease, False), deadline - started)
                deadline = lease_deadline(grant, started)

            async def to_vnc():
                if initial:
                    writer.write(initial)
                    await writer.drain()
                async for msg in ws:
                    if msg.type != WSMsgType.BINARY:
                        break
                    writer.write(msg.data)
                    await writer.drain()

            async def to_browser():
                while chunk := await reader.read(65536):
                    await ws.send_bytes(chunk)

            async def check_access():
                await renew_access(client, config, ticket, lease, deadline)

            tasks = [asyncio.create_task(fn(), name=name) for fn, name in (
                (to_vnc, 'browser-connection'), (to_browser, 'vnc-connection'), (check_access, 'authorization'))]
            done, _ = await asyncio.wait(tasks, return_when=asyncio.FIRST_COMPLETED)
            for task in done:
                error = task.exception()
                print('Screen: disconnected:', task.get_name(),
                      type(error).__name__ if error else 'connection closed', flush=True)
    except (TemporaryAuthError, ClientError, OSError, asyncio.TimeoutError,
            asyncio.IncompleteReadError, ValueError, ImportError) as error:
        print('Screen: connection failed:', type(error).__name__, flush=True)
        if ws is None:
            raise web.HTTPServiceUnavailable(text='Screen gateway unavailable')
    finally:
        for task in tasks:
            task.cancel()
        await asyncio.gather(*tasks, return_exceptions=True)
        if ws is not None:
            await ws.close()
        if writer is not None:
            writer.close()
        request.app['connections']['active'] -= 1
    return ws


def create_app(config):
    app = web.Application()
    app['config'] = config
    app['connections'] = {'active': 0}
    app.router.add_get('/screen', screen)
    return app


if __name__ == '__main__':
    # Tokens are in query strings: disable access logging here AND on the proxy.
    web.run_app(create_app(settings()), host='127.0.0.1',
                port=int(os.environ.get('CAP7A_GATEWAY_PORT', '6081')), access_log=None)
