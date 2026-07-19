import unittest

from processor import process_message


class ProcessMessageTest(unittest.TestCase):
    def test_removes_links_and_source_signature(self) -> None:
        text = "Повітряна тривога\nhttps://example.com/post\n@source_channel"
        self.assertEqual(process_message(text), "Повітряна тривога")

    def test_applies_prefix(self) -> None:
        self.assertEqual(process_message("Тест", "SkyGuardian: "), "SkyGuardian: Тест")

    def test_skips_empty_message(self) -> None:
        self.assertIsNone(process_message("   "))


if __name__ == "__main__":
    unittest.main()
