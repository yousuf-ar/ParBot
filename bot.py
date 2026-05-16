#!/usr/bin/env python3
"""
ParBot - Arabic Wikisource Uncategorized Pages Updater
Logs in as ParBot and updates ويكي مصدر:صفحات رئيسية غير مصنفة
with a list of uncategorized main pages (excluding subpages).
"""

import os
import requests
from datetime import datetime, timezone

# Configuration
API_URL = "https://ar.wikisource.org/w/api.php"
USERNAME = "ParBot"
TARGET_PAGE = "ويكي مصدر:صفحات رئيسية غير مصنفة"

# Safely fetch the password from GitHub Secrets environment variable
PASSWORD = os.getenv("WIKI_PASSWORD")
print(PASSWORD)

if not PASSWORD:
    raise ValueError("Error: WIKI_PASSWORD environment variable is not set.")

session = requests.Session()
session.headers.update({
    "User-Agent": "ParBot/1.0 (Arabic Wikisource maintenance bot)"
})


def login():
    """Log in to Wikisource using the API."""
    print("Fetching login token...")
    r = session.get(API_URL, params={
        "action": "query",
        "meta": "tokens",
        "type": "login",
        "format": "json"
    })
    r.raise_for_status()
    login_token = r.json()["query"]["tokens"]["logintoken"]

    print(f"Logging in as {USERNAME}...")
    r = session.post(API_URL, data={
        "action": "login",
        "lgname": USERNAME,
        "lgpassword": PASSWORD,
        "lgtoken": login_token,
        "format": "json"
    })
    r.raise_for_status()
    result = r.json()

    if result["login"]["result"] != "Success":
        raise Exception(f"Login failed: {result['login']}")
    print("Login successful.")


def get_uncategorized_pages():
    """Fetch all uncategorized pages, excluding subpages (pages with '/' in title)."""
    print("Fetching uncategorized pages...")
    pages = []
    params = {
        "action": "query",
        "list": "querypage",
        "qppage": "Uncategorizedpages",
        "qplimit": "500",
        "format": "json"
    }

    while True:
        r = session.get(API_URL, params=params)
        r.raise_for_status()
        data = r.json()

        results = data["query"]["querypage"]["results"]
        for item in results:
            title = item["title"]
            # Exclude subpages (contain '/')
            if "/" not in title:
                pages.append(title)

        # Handle continuation
        if "continue" in data:
            params.update(data["continue"])
        else:
            break

    print(f"Found {len(pages)} uncategorized main pages.")
    return sorted(pages)


def build_page_content(pages):
    """Build the wikitext content for the target page."""
    now = datetime.now(timezone.utc)
    timestamp = now.strftime("%Y-%m-%d، %H:%M")

    lines = []
    lines.append("<noinclude>")
    lines.append("{{صندوق|رمادي|")
    lines.append(f"قام {{{{مس|ParBot}}}} بتحديث هذه القائمة في {timestamp} (UTC)}}}}")
    lines.append("{{Div col|colwidth=20em}}")
    lines.append("</noinclude>")

    for title in pages:
        lines.append(f"# [[{title}]]")

    lines.append("[[تصنيف:صيانة ويكي مصدر]]")

    return "\n".join(lines)


def get_csrf_token():
    """Get a CSRF token for editing."""
    r = session.get(API_URL, params={
        "action": "query",
        "meta": "tokens",
        "format": "json"
    })
    r.raise_for_status()
    return r.json()["query"]["tokens"]["csrftoken"]


def update_page(content):
    """Update the target page with the new content."""
    print(f"Fetching CSRF token...")
    csrf_token = get_csrf_token()

    print(f"Updating page: {TARGET_PAGE}")
    r = session.post(API_URL, data={
        "action": "edit",
        "title": TARGET_PAGE,
        "text": content,
        "summary": "بوت: تحديث قائمة الصفحات الرئيسية غير المصنفة",
        "bot": "true",
        "token": csrf_token,
        "format": "json"
    })
    r.raise_for_status()
    result = r.json()

    if "edit" in result and result["edit"].get("result") == "Success":
        print("Page updated successfully!")
        if result["edit"].get("nochange"):
            print("(No changes were needed - page was already up to date.)")
    else:
        raise Exception(f"Edit failed: {result}")


def main():
    try:
        login()
        pages = get_uncategorized_pages()

        if not pages:
            print("No uncategorized pages found. Aborting.")
            return

        content = build_page_content(pages)
        print("\n--- Preview (first 500 chars) ---")
        print(content[:500])
        print("---\n")

        update_page(content)

    except Exception as e:
        print(f"Error: {e}")
        raise


if __name__ == "__main__":
    main()
