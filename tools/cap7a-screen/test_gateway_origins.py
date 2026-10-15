"""Origin configuration and request gate tests, without opening sockets."""
import ast
import asyncio
import os
from pathlib import Path
import re
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch
from urllib.parse import urlsplit


class Forbidden(Exception):
    pass


class OriginTests(unittest.TestCase):
    def setUp(self):
        source = Path(__file__).with_name('gateway.py')
        tree = ast.parse(source.read_text())
        functions = [node for node in tree.body
                     if isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef))
                     and node.name in ('settings', 'screen')]
        self.regex = Mock(wraps=re)
        self.namespace = {'os': os, 'urlsplit': urlsplit, 're': self.regex,
                          'web': SimpleNamespace(HTTPForbidden=Forbidden)}
        exec(compile(ast.Module(body=functions, type_ignores=[]), str(source), 'exec'), self.namespace)
        self.env = patch.dict(os.environ, {
            'CAP7A_AUTH_URL': 'https://backend.invalid/api/cap7a-screen/authorize',
            'CAP7A_SCREEN_GATEWAY_KEY': 'x' * 32,
            'CAP7A_VNC_VIEW_ONLY_CONFIRMED': 'yes',
            'CAP7A_FE_ORIGIN': 'https://a7acompany.com,http://localhost:5173',
        })
        self.env.start()
        self.addCleanup(self.env.stop)

    def test_multiple_origins_and_single_origin_compatibility(self):
        self.assertEqual(self.namespace['settings']()['origins'],
                         {'https://a7acompany.com', 'http://localhost:5173'})
        os.environ['CAP7A_FE_ORIGIN'] = ' https://a7acompany.com/ '
        self.assertEqual(self.namespace['settings']()['origins'], {'https://a7acompany.com'})

    def test_rejects_unsafe_or_malformed_configuration(self):
        for value in ('', '*', 'https://*.example.com', 'http://a7acompany.com',
                      'https://user:pass@example.com', 'https://example.com/path',
                      'https://example.com?query=1', 'https://example.com#fragment',
                      'http://localhost.evil.test:5173', 'http://localhost:wrong'):
            with self.subTest(value=value):
                os.environ['CAP7A_FE_ORIGIN'] = value
                with self.assertRaises(ValueError):
                    self.namespace['settings']()

    def test_allowed_origins_still_require_a_ticket(self):
        config = self.namespace['settings']()
        for origin in config['origins']:
            self.regex.reset_mock()
            request = SimpleNamespace(app={'config': config}, headers={'Origin': origin}, query={})
            with self.assertRaises(Forbidden):
                asyncio.run(self.namespace['screen'](request))
            self.regex.fullmatch.assert_called_once_with('[a-f0-9]{64}', '')

    def test_other_origins_rejected_before_ticket_processing(self):
        config = self.namespace['settings']()
        for origin in (None, 'null', 'http://localhost:5174', 'https://evil.test',
                       'https://a7acompany.com.evil.test'):
            self.regex.reset_mock()
            request = SimpleNamespace(app={'config': config}, headers={'Origin': origin})
            with self.assertRaises(Forbidden):
                asyncio.run(self.namespace['screen'](request))
            self.regex.fullmatch.assert_not_called()


if __name__ == '__main__':
    unittest.main()
