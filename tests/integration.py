"""Real HTTP checks against PHP with a temporary SQLite database. Standard library only."""
import http.cookiejar
from html.parser import HTMLParser
from contextlib import contextmanager
import json
import os
from pathlib import Path
import re
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
PASSWORD = "integration-admin-password"


@contextmanager
def connection(path):
    db = sqlite3.connect(path)
    try:
        with db:
            yield db
    finally:
        db.close()


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class PageLinks(HTMLParser):
    def __init__(self, html):
        super().__init__()
        self.links = []
        self.ids = set()
        self.feed(html)

    def handle_starttag(self, tag, attributes):
        attrs = dict(attributes)
        if "id" in attrs:
            self.ids.add(attrs["id"])
        for name in ["href", "src", "data-img"]:
            if name in attrs:
                self.links.append(attrs[name])


class BackendTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory(prefix="kpi-php-test-")
        cls.db_path = Path(cls.temp.name) / "college.sqlite"
        cls.session_path = Path(cls.temp.name) / "sessions"
        # Start with the previous backend's schema and a real legacy message.
        with connection(cls.db_path) as db:
            db.execute("CREATE TABLE messages (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL, phone TEXT NOT NULL DEFAULT '', topic TEXT NOT NULL, message TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT (datetime('now')))")
            db.execute("INSERT INTO messages (name,email,topic,message) VALUES ('Legacy Student','legacy@example.com','Admission','A message from the previous backend.')")
        cls.env = {**os.environ, "ADMIN_USERNAME": "admin", "ADMIN_PASSWORD": PASSWORD,
                   "SQLITE_PATH": str(cls.db_path), "SESSION_PATH": str(cls.session_path), "COOKIE_SECURE": "false"}
        cls.log = open(Path(cls.temp.name) / "php.log", "w+", encoding="utf-8")
        cls.start_server()

    @classmethod
    def start_server(cls):
        with socket.socket() as sock:
            sock.bind(("127.0.0.1", 0))
            cls.port = sock.getsockname()[1]
        cls.base = f"http://127.0.0.1:{cls.port}"
        cls.process = subprocess.Popen([shutil.which("php"), "-S", f"127.0.0.1:{cls.port}", "-t", "public", "public/index.php"],
                                      cwd=ROOT, env=cls.env, stdout=cls.log, stderr=cls.log)
        for _ in range(100):
            try:
                with urllib.request.urlopen(cls.base + "/", timeout=1) as response:
                    if response.status == 200:
                        return
            except (OSError, urllib.error.URLError):
                if cls.process.poll() is not None:
                    break
                time.sleep(.1)
        cls.log.seek(0)
        raise RuntimeError("PHP failed to start: " + cls.log.read())

    @classmethod
    def tearDownClass(cls):
        cls.process.terminate()
        cls.process.wait(timeout=10)
        cls.log.close()
        cls.temp.cleanup()

    def setUp(self):
        self.jar, self.client = self.new_client()
        with connection(self.db_path) as db:
            db.execute("DELETE FROM request_limits")

    def new_client(self):
        jar = http.cookiejar.CookieJar()
        return jar, urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), NoRedirect())

    def request(self, path, data=None, *, client=None, headers=None, raw=False):
        headers = dict(headers or {})
        if data is not None:
            if raw:
                data = data.encode()
            else:
                data = urllib.parse.urlencode(data).encode()
                headers["Content-Type"] = "application/x-www-form-urlencoded"
        req = urllib.request.Request(self.base + path, data=data, headers=headers)
        try:
            response = (client or self.client).open(req, timeout=10)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            body = response.read()
            content = body.decode("utf-8") if not response.headers.get("Content-Type", "").startswith("image/") else ""
            return response.status, content, response.headers

    def token(self, html):
        return re.search(r'name="csrf" value="([a-f0-9]+)"', html)[1]

    def login(self, password=PASSWORD, client=None):
        status, page, _ = self.request("/admin/login", client=client)
        self.assertEqual(status, 200)
        return self.request("/admin/login", {"username": "admin", "password": password, "csrf": self.token(page)}, client=client)

    def test_01_public_pages_migration_and_private_files(self):
        for page in ["/", "/about.html", "/contact.html", "/admission.html", "/notices.html", "/departments.html", "/assets/css/admin.css"]:
            status, html, _ = self.request(page)
            self.assertEqual(status, 200, page)
            self.assertNotIn("{{DEPARTMENT", html)
        for path in ["/.env", "/data/college.sqlite", "/server/bootstrap.php", "/server/departments.json", "/tests/integration.py", "/assets/../.env", "/assets/%2e%2e/server/admin.php"]:
            self.assertEqual(self.request(path)[0], 404, path)
        with connection(self.db_path) as db:
            self.assertIn("status", [row[1] for row in db.execute("PRAGMA table_info(messages)")])
            self.assertEqual(db.execute("SELECT status FROM messages WHERE id=1").fetchone()[0], "unread")
            self.assertEqual(db.execute("SELECT COUNT(*) FROM departments").fetchone()[0], 9)
            hashed = db.execute("SELECT password_hash FROM admins").fetchone()[0]
            self.assertNotEqual(hashed, PASSWORD)
            self.assertTrue(hashed.startswith("$2y$") or hashed.startswith("$argon"))

    def test_02_login_csrf_rotation_and_logout(self):
        for route in ["/admin", "/admin/messages", "/admin/departments", "/admin/account"]:
            status, _, headers = self.request(route)
            self.assertEqual(status, 303)
            self.assertEqual(headers["Location"], "/admin/login")
        self.jar.clear()
        status, page, headers = self.request("/admin/login")
        old_cookie = next(c.value for c in self.jar if c.name == "kpi_admin")
        self.assertIn("HttpOnly", headers["Set-Cookie"])
        self.assertIn("SameSite=Strict", headers["Set-Cookie"])
        self.assertEqual(self.request("/admin/login", {"username": "admin", "password": PASSWORD})[0], 403)
        self.assertEqual(self.login("wrong-password")[0], 401)
        self.assertEqual(self.login()[0], 303)
        new_cookie = next(c.value for c in self.jar if c.name == "kpi_admin")
        self.assertNotEqual(old_cookie, new_cookie)
        status, html, headers = self.request("/admin")
        self.assertEqual(status, 200)
        self.assertIn("Recent enquiries", html)
        self.assertIn("no-store", headers["Cache-Control"])
        self.assertEqual(self.request("/admin/logout", {})[0], 403)
        self.assertEqual(self.request("/admin/logout", {"csrf": self.token(html)})[0], 303)
        self.assertEqual(self.request("/admin")[0], 303)
        _, fresh = self.new_client()
        self.assertEqual(self.request("/admin", client=fresh, headers={"Cookie": "kpi_admin=" + new_cookie})[0], 303)

    def test_03_contact_validation_and_inbox(self):
        message = {"name": "Test Student", "email": "student@example.com", "phone": "01712345678", "topic": "Admission", "message": "Please send admission details. <script>alert(1)</script>"}
        headers = {"Content-Type": "application/json"}
        post = lambda data, extra=None: self.request("/api/contact", json.dumps(data), raw=True, headers={**headers, **(extra or {})})
        self.assertEqual(post(None)[0], 400)
        self.assertEqual(post({})[0], 422)
        self.assertEqual(post({**message, "phone": "bad"})[0], 422)
        self.assertEqual(post({**message, "topic": "bad"})[0], 422)
        self.assertEqual(post({**message, "message": "x" * 5001})[0], 422)
        self.assertEqual(post(message, {"Origin": "https://another-site.example"})[0], 403)
        self.assertEqual(post(message)[0], 201)
        self.login()
        status, html, _ = self.request("/admin/messages?q=student%40example.com")
        self.assertEqual(status, 200)
        self.assertIn("Test Student", html)
        self.assertIn("&lt;script&gt;", html)
        self.assertNotIn("<script>alert", html)
        with connection(self.db_path) as db:
            id_ = db.execute("SELECT MAX(id) FROM messages").fetchone()[0]
        self.assertEqual(self.request("/admin/messages", {"id": id_, "status": "read"})[0], 403)
        for state in ["read", "archived", "unread"]:
            self.assertEqual(self.request("/admin/messages", {"csrf": self.token(html), "id": id_, "status": state})[0], 303)
            with connection(self.db_path) as db:
                self.assertEqual(db.execute("SELECT status FROM messages WHERE id=?", [id_]).fetchone()[0], state)
        self.assertEqual(self.request("/admin/messages?view=999999")[0], 404)
        self.assertIn("No messages found", self.request("/admin/messages?q=nothingmatches987")[1])

    def test_04_department_edit_validation_and_public_updates(self):
        self.login()
        status, html, _ = self.request("/admin/departments?edit=civil")
        self.assertEqual(status, 200)
        with connection(self.db_path) as db:
            db.row_factory = sqlite3.Row
            department = dict(db.execute("SELECT * FROM departments WHERE id='civil'").fetchone())
        department.update({"name": "Updated Civil Programme", "seats": "220", "labs": "Survey lab\nDrawing lab", "csrf": self.token(html)})
        self.assertEqual(self.request("/admin/departments", {"csrf": self.token(html)})[0], 422)
        self.assertEqual(self.request("/admin/departments", {**department, "seats": "221"})[0], 422)
        self.assertEqual(self.request("/admin/departments", {**department, "code": "ET"})[0], 422)
        self.assertEqual(self.request("/admin/departments", department)[0], 303)
        for path in ["/", "/departments.html", "/contact.html"]:
            self.assertIn("Updated Civil Programme", self.request(path)[1])
        public = self.request("/departments.html")[1]
        self.assertIn("Survey lab", public)
        self.assertIn("<td>110</td>", public)
        self.assertEqual(self.request("/admin/departments?edit=missing")[0], 404)

    def test_05_password_change_revokes_other_sessions(self):
        self.login()
        _, other = self.new_client()
        self.login(client=other)
        _, page, _ = self.request("/admin/account")
        data = {"csrf": self.token(page), "current_password": "wrong", "new_password": "new-private-password", "confirm_password": "new-private-password"}
        self.assertEqual(self.request("/admin/account", data)[0], 422)
        data["current_password"] = PASSWORD
        self.assertEqual(self.request("/admin/account", data)[0], 303)
        self.assertEqual(self.request("/admin", client=other)[0], 303)
        self.assertEqual(self.login(client=other)[0], 401)
        self.assertEqual(self.login(password=data["new_password"], client=other)[0], 303)
        # Restore the test password for independent tests; don't touch real credentials.
        _, page, _ = self.request("/admin/account")
        self.request("/admin/account", {"csrf": self.token(page), "current_password": data["new_password"], "new_password": PASSWORD, "confirm_password": PASSWORD})

    def test_06_session_expiry_and_fixation(self):
        self.request("/admin/login", headers={"Cookie": "kpi_admin=attackerchosenidentifier"})
        self.assertNotEqual(next(c.value for c in self.jar if c.name == "kpi_admin"), "attackerchosenidentifier")
        self.login()
        session = next(c.value for c in self.jar if c.name == "kpi_admin")
        file = self.session_path / ("sess_" + session)
        body = file.read_text()
        file.write_text(re.sub(r'last_seen\|i:\d+;', 'last_seen|i:1;', body))
        self.assertEqual(self.request("/admin")[0], 303)

    def test_07_rate_limits_survive_server_restart(self):
        for _ in range(8):
            self.assertEqual(self.login("wrong-password")[0], 401)
        self.assertEqual(self.login()[0], 429)
        type(self).process.terminate()
        type(self).process.wait(timeout=10)
        type(self).start_server()
        self.assertEqual(self.login()[0], 429)
        for _ in range(10):
            self.request("/api/contact", "{}", raw=True, headers={"Content-Type": "application/json"})
        self.assertEqual(self.request("/api/contact", "{}", raw=True, headers={"Content-Type": "application/json"})[0], 429)
        self.assertIn("Updated Civil Programme", self.request("/departments.html")[1])

    def test_08_inbox_pagination(self):
        with connection(self.db_path) as db:
            db.executemany("INSERT INTO messages (name,email,topic,message) VALUES (?, 'pagination@example.com', 'Suggestion', 'A sample message for checking pagination.')",
                           [(f"Pagination Student {number:02}",) for number in range(25)])
        self.login()
        first = self.request("/admin/messages?q=pagination%40example.com")[1]
        second = self.request("/admin/messages?q=pagination%40example.com&page=2")[1]
        self.assertEqual(len(re.findall(r'class="inbox-item ', first)), 20)
        self.assertEqual(len(re.findall(r'class="inbox-item ', second)), 5)
        self.assertIn("Pagination Student 24", first)
        self.assertNotIn("Pagination Student 00", first)
        self.assertIn("Pagination Student 00", second)
        self.assertNotIn("Pagination Student 24", second)
        self.assertIn("2 / 2", self.request("/admin/messages?q=pagination%40example.com&page=999")[1])

    def test_09_public_links_assets_and_anchors(self):
        cache = {}
        for page in ["/", "/about.html", "/admission.html", "/departments.html", "/notices.html", "/contact.html"]:
            html = self.request(page)[1]
            for link in PageLinks(html).links:
                url = urllib.parse.urlsplit(urllib.parse.urljoin(self.base + page, link))
                if url.netloc != urllib.parse.urlsplit(self.base).netloc or url.scheme not in ["http", "https"]:
                    continue
                target = url.path + ("?" + url.query if url.query else "")
                if target not in cache:
                    status, content, headers = self.request(target)
                    cache[target] = (status, content, headers)
                status, content, headers = cache[target]
                self.assertIn(status, [200, 303], f"Broken resource on {page}: {link}")
                if url.fragment and "text/html" in headers.get("Content-Type", ""):
                    self.assertIn(urllib.parse.unquote(url.fragment), PageLinks(content).ids, f"Missing anchor on {page}: {link}")


if __name__ == "__main__":
    unittest.main(verbosity=2)
