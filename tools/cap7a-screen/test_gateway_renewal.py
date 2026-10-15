"""Exercise renewal with a simulated clock; no network or real waiting."""
import ast
import asyncio
from pathlib import Path
from types import SimpleNamespace
import unittest
from unittest.mock import AsyncMock, Mock


class Forbidden(Exception):
    pass


class RenewalTests(unittest.IsolatedAsyncioTestCase):
    def setUp(self):
        self.now = 0
        async def sleep(seconds):
            self.now += seconds
        async def wait_for(awaitable, timeout):
            return await awaitable
        self.auth = AsyncMock()
        fake_asyncio = SimpleNamespace(get_running_loop=lambda: SimpleNamespace(time=lambda: self.now),
                                       sleep=sleep, wait_for=wait_for, TimeoutError=TimeoutError)
        self.ns = {'asyncio': fake_asyncio, 'authorize': self.auth,
                   'ClientError': ConnectionError, 'web': SimpleNamespace(HTTPForbidden=Forbidden),
                   'print': Mock()}
        tree = ast.parse(Path(__file__).with_name('gateway.py').read_text())
        nodes = [node for node in tree.body if getattr(node, 'name', '') in
                 ('TemporaryAuthError', 'lease_deadline', 'renew_access')]
        exec(compile(ast.Module(body=nodes, type_ignores=[]), 'gateway.py', 'exec'), self.ns)

    async def test_transient_error_recovers_but_revocation_stops_immediately(self):
        self.auth.side_effect = [ConnectionError(), {'remaining_seconds': 300}, Forbidden()]
        with self.assertRaises(Forbidden):
            await self.ns['renew_access'](None, {}, 'ticket', 'lease', 25)
        self.assertEqual(self.auth.await_count, 3)
        self.assertEqual(self.now, 30)  # Recovery extended the original 25s deadline.

    async def test_outage_never_extends_existing_grant(self):
        self.auth.side_effect = self.ns['TemporaryAuthError']()
        with self.assertRaises(TimeoutError):
            await self.ns['renew_access'](None, {}, 'ticket', 'lease', 25)
        self.assertEqual(self.now, 25)
        self.assertEqual(self.auth.await_count, 2)

    async def test_forbidden_is_not_retried(self):
        self.auth.side_effect = Forbidden()
        with self.assertRaises(Forbidden):
            await self.ns['renew_access'](None, {}, 'ticket', 'lease', 300)
        self.assertEqual(self.auth.await_count, 1)

    def test_lease_uses_request_start_and_caps_duration(self):
        self.assertEqual(self.ns['lease_deadline']({'remaining_seconds': 999}, 10), 310)
        for value in (None, 0, -1, True, '300'):
            with self.assertRaises(Forbidden):
                self.ns['lease_deadline']({'remaining_seconds': value}, 10)


if __name__ == '__main__':
    unittest.main()
