import unittest
from client import unwrap


class ClientTests(unittest.TestCase):
    def test_unwrap_jsonrpc_result(self):
        self.assertEqual(unwrap({"result": {"count": 3}}), {"count": 3})

    def test_unwrap_mcp_text_json(self):
        payload = {"result": {"content": [{"type": "text", "text": "{\"ok\": true}"}]}}
        self.assertEqual(unwrap(payload), {"ok": True})

    def test_unwrap_non_json_text(self):
        payload = {"result": {"content": [{"type": "text", "text": "hello"}]}}
        self.assertEqual(unwrap(payload), "hello")


if __name__ == "__main__":
    unittest.main()
