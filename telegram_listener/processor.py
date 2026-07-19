import re


def process_message(text: str, prefix: str = "") -> str | None:
    """Return cleaned text ready for publishing, or None to skip it."""
    cleaned = text.strip()
    if not cleaned:
        return None

    cleaned = re.sub(r"https?://\S+", "", cleaned)
    cleaned = re.sub(r"(?m)^\s*@\w+\s*$", "", cleaned)
    cleaned = re.sub(r"\n{3,}", "\n\n", cleaned).strip()

    if not cleaned:
        return None

    return f"{prefix}{cleaned}" if prefix else cleaned
