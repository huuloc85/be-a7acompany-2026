"""Terminate VNC authentication locally, only after the gateway grants a ticket.

RFB 3.8 / security type 2 is required upstream. DES is mandated by classic VNC,
not used to protect public traffic: the upstream stays loopback and WS uses TLS.
"""
import os
import stat
from aiohttp import WSMsgType

VERSION = b'RFB 003.008\n'


def reverse_bits(data):
    return bytes(int(f'{value:08b}'[::-1], 2) for value in data)


def read_password(path):
    from Crypto.Cipher import DES
    # Use the same private rfbauth file as x11vnc; never create a second secret.
    with open(path, 'rb') as source:
        info = os.fstat(source.fileno())
        if (not stat.S_ISREG(info.st_mode) or info.st_uid != os.getuid()
                or info.st_mode & 0o077 or info.st_size not in (8, 16)):
            raise ValueError('VNC password file must be private and owned by the gateway user')
        encrypted = source.read(8)
    # LibVNCServer's rfbauth storage key, with its RFB DES bit ordering.
    key = reverse_bits(bytes((23, 82, 107, 6, 35, 78, 88, 7)))
    password = DES.new(key, DES.MODE_ECB).decrypt(encrypted).split(b'\0', 1)[0]
    if not password:
        raise ValueError('Empty VNC password is not supported')
    return password


async def authenticate_vnc(reader, writer, password_file):
    from Crypto.Cipher import DES
    password = read_password(password_file)
    if await reader.readexactly(12) != VERSION:
        raise ValueError('Gateway-managed VNC authentication requires RFB 3.8')
    writer.write(VERSION)
    await writer.drain()
    count = (await reader.readexactly(1))[0]
    if not count or 2 not in await reader.readexactly(count):
        raise ValueError('VNC server must require password authentication')
    writer.write(b'\x02')
    await writer.drain()
    challenge = await reader.readexactly(16)
    key = reverse_bits(password.ljust(8, b'\0'))
    writer.write(DES.new(key, DES.MODE_ECB).encrypt(challenge))
    await writer.drain()
    if await reader.readexactly(4) != b'\0\0\0\0':
        raise ValueError('VNC authentication rejected')
    # Leave ClientInit/ServerInit to noVNC after the browser-side handshake.


async def authenticate_browser(ws):
    pending = bytearray()

    async def read(size):
        while len(pending) < size:
            message = await ws.receive()
            if message.type != WSMsgType.BINARY:
                raise ValueError('Incomplete browser RFB handshake')
            pending.extend(message.data)
            if len(pending) > 1024 * 1024:
                raise ValueError('Browser RFB handshake too large')
        value = bytes(pending[:size])
        del pending[:size]
        return value

    # Browser authentication already happened through the BE ticket, not VNC.
    await ws.send_bytes(VERSION)
    if await read(12) != VERSION:
        raise ValueError('Browser must use RFB 3.8')
    await ws.send_bytes(b'\x01\x01')
    if await read(1) != b'\x01':
        raise ValueError('Unexpected browser RFB security selection')
    await ws.send_bytes(b'\0\0\0\0')
    # Preserve any ClientInit bytes coalesced into the final handshake frame.
    return bytes(pending)
