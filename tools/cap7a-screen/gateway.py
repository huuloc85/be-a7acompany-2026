"""Private WS-to-VNC gateway. Run behind HTTPS proxy; never expose VNC TCP.

Requires a dedicated x11vnc -viewonly endpoint through a loopback SSH tunnel.
Authorization is rechecked every 10s, and streams close after at most 5 minutes.
"""
import asyncio
import os
import re
import uuid
from urllib.parse import urlsplit
from aiohttp import ClientSession, ClientTimeout, WSMsgType, web


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
    origin = os.environ['CAP7A_FE_ORIGIN'].rstrip('/')
    if not origin.startswith('https://'):
        raise ValueError('Production FE origin must use HTTPS')
    return {'auth_url': url, 'key': key, 'origin': origin,
            'vnc_port': int(os.environ.get('CAP7A_VNC_PORT', '5903'))}


async def authorize(client, config, ticket, lease, opening):
    async with client.post(config['auth_url'], json={'ticket': ticket, 'lease': lease, 'opening': opening},
                           headers={'X-Cap7A-Gateway-Key': config['key'], 'Accept': 'application/json'},
                           allow_redirects=False) as response:
        if response.status != 200:
            raise web.HTTPForbidden()
        body = await response.json()
        if body.get('allowed') is not True or body.get('view_only') is not True:
            raise web.HTTPForbidden()
        return body


async def screen(request):
    config = request.app['config']
    if request.headers.get('Origin') != config['origin']:
        raise web.HTTPForbidden()
    ticket = request.query.get('ticket', '')
    if not re.fullmatch('[a-f0-9]{64}', ticket):
        raise web.HTTPForbidden()
    if request.app['active'] >= 2:
        raise web.HTTPTooManyRequests()
    request.app['active'] += 1
    writer = None
    ws = None
    tasks = []
    try:
        lease = str(uuid.uuid4())
        async with ClientSession(timeout=ClientTimeout(total=4)) as client:
            grant = await authorize(client, config, ticket, lease, True)
            reader, writer = await asyncio.wait_for(asyncio.open_connection('127.0.0.1', config['vnc_port']), 5)
            ws = web.WebSocketResponse(protocols=('binary',), max_msg_size=1024 * 1024, heartbeat=20)
            await ws.prepare(request)

            async def to_vnc():
                async for msg in ws:
                    if msg.type != WSMsgType.BINARY:
                        break
                    writer.write(msg.data)
                    await writer.drain()

            async def to_browser():
                while chunk := await reader.read(65536):
                    await ws.send_bytes(chunk)

            async def check_access():
                while True:
                    await asyncio.sleep(10)
                    await authorize(client, config, ticket, lease, False)

            tasks = [asyncio.create_task(fn()) for fn in (to_vnc, to_browser, check_access)]
            await asyncio.wait(tasks, timeout=min(300, grant['remaining_seconds']), return_when=asyncio.FIRST_COMPLETED)
    except (OSError, asyncio.TimeoutError):
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
        request.app['active'] -= 1
    return ws


def create_app(config):
    app = web.Application()
    app['config'] = config
    app['active'] = 0
    app.router.add_get('/screen', screen)
    return app


if __name__ == '__main__':
    # Tokens are in query strings: disable access logging here AND on the proxy.
    web.run_app(create_app(settings()), host='127.0.0.1', port=6081, access_log=None)
