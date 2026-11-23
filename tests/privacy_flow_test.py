"""Opt-in HTTP regressions using two PHP workers and an isolated fixture database."""

import base64
import concurrent.futures
import contextlib
import hashlib
import hmac
import http.cookiejar
import os
from pathlib import Path
import re
import secrets
import shutil
import socket
import subprocess
import tempfile
import threading
import time
import urllib.error
import urllib.parse
import urllib.request


ROOT = Path(__file__).resolve().parents[1]


class Browser:
    def __init__(self, base):
        self.base = base
        self.cookies = http.cookiejar.CookieJar()
        self.http = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.cookies))

    def request(self, path, data=None):
        payload = None if data is None else urllib.parse.urlencode(data).encode()
        try:
            response = self.http.open(self.base + path, payload, timeout=15)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.status, response.geturl(), response.read().decode()

    def login(self, name):
        page = self.request("/auth/login.php")[2]
        return self.request("/auth/login.php", dict(csrf_token=csrf(page), email=name+"@example.test", password="fixture-pass"))


def csrf(page):
    return re.search(r'name="csrf_token" value="([^"]+)"', page)[1]


def seed(page):
    return re.search(r'class="mfa-secret-key">([A-Z2-7]+)<', page)[1]


def otp(secret):
    counter = (int(time.time()) // 30).to_bytes(8, "big")
    digest = hmac.digest(base64.b32decode(secret), counter, "sha1")
    offset = digest[-1] & 15
    return f"{(int.from_bytes(digest[offset:offset+4], 'big') & 0x7fffffff) % 1000000:06d}"


@contextlib.contextmanager
def server(directory, env):
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        port = sock.getsockname()[1]
    address = f"127.0.0.1:{port}"
    process = subprocess.Popen(["php", "-d", f"session.save_path={directory}", "-S", address, "-t", directory/"src"],
                               env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        for _ in range(100):
            if process.poll() is not None:
                raise RuntimeError("PHP fixture server stopped")
            try:
                with socket.create_connection(("127.0.0.1", port), timeout=.2):
                    break
            except OSError:
                time.sleep(.05)
        yield "http://" + address
    finally:
        process.terminate()
        process.wait(timeout=10)


def exercise(first, second, sql, directory):
    guest, user = Browser(first), Browser(first)
    page = guest.request("/pastebin.php")[2]
    assert 'value="private"' not in page and 'value="unlisted"' in page
    paste = dict(input_topic="fixture", input_text="original", input_language="Plaintext")
    assert guest.request("/submit.php", dict(paste, visibility="private", csrf_token=csrf(page)))[0] == 403
    assert sql("SELECT COUNT(*) FROM entries") == "0"
    user.login("writer")
    page = user.request("/pastebin.php")[2]
    assert 'value="private"' in page
    fields = dict(paste, visibility="private", csrf_token=csrf(page))
    assert user.request("/submit.php", dict(fields, protection_password="é"*37))[0] == 400
    assert sql("SELECT COUNT(*) FROM entries") == "0"
    assert user.request("/submit.php", fields)[0] == 200
    entry = sql("SELECT id FROM entries WHERE visibility='private'")
    assert user.request("/view.php?id="+entry)[0] == 200 and guest.request("/view.php?id="+entry)[0] == 404
    edit = dict(fields, input_ID=entry, input_text="original", protection_password="é"*36)
    assert user.request("/update.php", edit)[0] == 200
    stored_hash = sql("SELECT protection_hash FROM entries")
    assert stored_hash
    edit.update(input_text="changed", protection_password="", remove_protection="yes", expiry="unknown")
    assert user.request("/update.php", edit)[0] == 400
    assert sql("SELECT CONCAT(visibility,':',text) FROM entries") == "private:original"
    assert sql("SELECT protection_hash FROM entries") == stored_hash
    assert user.request("/update.php", dict(fields, input_ID=entry, remove_protection="yes"))[0] == 200
    # Simulate elapsed time: neither the root nor its reply has a preset TTL left.
    assert user.request("/submit.php", dict(fields, parent_id=entry))[0] == 200
    deadline = sql("SELECT UTC_TIMESTAMP() + INTERVAL 12 HOUR")
    sql(f"UPDATE entries SET expires_at='{deadline}'")
    edit = dict(fields, input_ID=entry, input_text="edited")
    for change in [{}, {"expiry": "keep"}]:
        assert user.request("/update.php", dict(edit, **change))[0] == 200
        assert sql("SELECT expires_at FROM entries").splitlines() == [deadline, deadline]
    assert user.request("/update.php", dict(edit, expiry="never"))[0] == 200
    assert sql("SELECT COUNT(*) FROM entries WHERE expires_at IS NULL") == "2"
    sql("UPDATE users SET session_version=session_version+1 WHERE username='writer'")
    assert user.request("/submit.php", fields)[1].endswith("/auth/login.php")
    assert sql("SELECT COUNT(*) FROM entries") == "2"
    # Give only this synthetic session a known image challenge. The POST
    # still has to pass the real CSRF, rate-limit and CAPTCHA validators.
    sid = next(c.value for c in guest.cookies if c.name == "opng_session")
    subprocess.run(["php", "-d", f"session.save_path={directory}", "-r",
                    'require "includes/captcha.php"; session_id($argv[1]); session_start(); captcha_issue_image("ABCDE");',
                    sid], cwd=directory/"src", check=True)
    result = guest.request("/submit.php", dict(paste, visibility="unlisted", csrf_token=csrf(guest.request("/pastebin.php")[2]),
                                                captcha_answer="ABCDE"))
    assert result[0] == 200 and sql("SELECT COUNT(*) FROM entries WHERE user_id IS NULL AND visibility='unlisted'") == "1"

    a, b = Browser(first), Browser(second)
    page = a.login("admin")[2]
    old = seed(page)
    assert seed(a.request("/auth/mfa_enroll.php")[2]) == old
    a.request("/auth/mfa.php?cancel=1")
    page = a.login("admin")[2]
    assert seed(page) != old
    sql("UPDATE users SET session_version=session_version+1 WHERE username='admin'")
    assert a.request("/auth/mfa_enroll.php", dict(csrf_token=csrf(page), mfa_code=otp(seed(page))))[0] != 200
    assert sql("SELECT SUM(mfa_enabled) FROM users") == "0"
    page = a.login("admin")[2]
    sql("UPDATE users SET role='user' WHERE username='admin'")
    assert a.request("/auth/mfa_enroll.php", dict(csrf_token=csrf(page), mfa_code=otp(seed(page))))[0] != 200
    assert sql("SELECT COUNT(*) FROM mfa_recovery_codes") == "0"
    sql("UPDATE users SET role='admin',session_version=session_version+1 WHERE username='admin'")

    # Independent password-verified sessions start at the same account version.
    pages = [a.login("admin")[2], b.login("admin")[2]]
    barrier = threading.Barrier(2)

    def confirm(pair):
        browser, form = pair
        barrier.wait(timeout=10)
        return browser.request("/auth/mfa_enroll.php", dict(csrf_token=csrf(form), mfa_code=otp(seed(form))))

    with concurrent.futures.ThreadPoolExecutor(2) as pool:
        responses = list(pool.map(confirm, zip([a, b], pages)))
    winners = [i for i, (status, _, html) in enumerate(responses) if status == 200 and "recovery-code-list" in html]
    assert len(winners) == 1
    winner = winners[0]
    assert sql("SELECT COUNT(*) FROM mfa_recovery_codes") == "10"
    codes = re.findall(r"<code>([A-F0-9-]{23})</code>", responses[winner][2])
    expected = sorted(hashlib.sha256(code.replace("-", "").encode()).hexdigest() for code in codes)
    assert len(expected) == 10
    assert sql("SELECT code_hash FROM mfa_recovery_codes ORDER BY code_hash").splitlines() == expected
    assert [a, b][winner].request("/auth/security.php")[0] == 200
    assert [a, b][1-winner].request("/auth/security.php")[0] == 403
    fresh = Browser(first)
    challenge = fresh.login("admin")[2]
    secret = seed(pages[winner])
    fresh.request("/auth/mfa.php", dict(csrf_token=csrf(challenge), mfa_code=otp(secret)))
    assert fresh.request("/auth/security.php")[0] == 200
    assert fresh.request("/view.php?id="+entry)[0] == 200
    stale = Browser(first)
    challenge = stale.login("admin")[2]
    sql("UPDATE users SET session_version=session_version+1 WHERE username='admin'")
    stale.request("/auth/mfa.php", dict(csrf_token=csrf(challenge), mfa_code=otp(secret)))
    assert stale.request("/auth/security.php")[0] == 403

    optional = Browser(second)
    optional.login("secondadmin")
    page = optional.request("/auth/security.php?enroll=1")[2]
    assert seed(optional.request("/auth/security.php")[2]) == seed(page)
    response = optional.request("/auth/security.php", dict(action="confirm_enroll", csrf_token=csrf(page),
                                                           mfa_code=otp(seed(page))))
    assert response[0] == 200 and "recovery-code-list" in response[2]
    assert optional.request("/auth/security.php")[0] == 200


def main():
    if os.environ.get("OPNG_TEST_ALLOW_DATABASE_FIXTURES") != "1":
        raise SystemExit("Enable database fixtures on a disposable server first")
    host = os.environ.get("OPNG_TEST_DB_HOST", "127.0.0.1")
    port = os.environ.get("OPNG_TEST_DB_PORT", "3306")
    user = os.environ["OPNG_TEST_DB_USER"]
    password = os.environ.get("OPNG_TEST_DB_PASS", "")
    database = "opng_privacy_" + secrets.token_hex(6)

    def sql(query, use_db=True):
        args = ["mysql", "--no-defaults", "--protocol=TCP", "-h", host, "-P", port, "-u", user, "-NB"]
        return subprocess.run(args + ([database] if use_db else []), input=query, text=True,
                              env=dict(os.environ, MYSQL_PWD=password), check=True, stdout=subprocess.PIPE).stdout.strip()

    sql(f"CREATE DATABASE `{database}` CHARACTER SET utf8mb4", False)
    try:
        sql((ROOT/"database/schema.sql").read_text())
        hashed = subprocess.check_output(["php", "-r", "echo password_hash('fixture-pass',PASSWORD_DEFAULT);"], text=True)
        for name, role in [("admin", "admin"), ("writer", "user"), ("secondadmin", "admin")]:
            sql(f"INSERT INTO users(username,email,password_hash,role) VALUES ('{name}','{name}@example.test','{hashed}','{role}')")
        with tempfile.TemporaryDirectory() as tmp:
            d = Path(tmp)
            shutil.copytree(ROOT/"src", d/"src", ignore=shutil.ignore_patterns("config.php"))
            shutil.copyfile(d/"src/config.example.php", d/"src/config.php")
            env = dict(os.environ, OPNG_DB_HOST=f"{host}:{port}", OPNG_DB_NAME=database, OPNG_DB_USER=user,
                       OPNG_DB_PASS=password, OPNG_MFA_KEY=base64.b64encode(os.urandom(32)).decode())
            with server(d, env) as first, server(d, env) as second:
                exercise(first, second, sql, d)
    finally:
        sql(f"DROP DATABASE `{database}`", False)
    print("PASS: privacy, revoked identity, MFA cancellation, version checks and concurrent enrollment")


if __name__ == "__main__":
    main()
