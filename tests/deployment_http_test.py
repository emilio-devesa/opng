"""Run inside a disposable OPNG container with the three web profiles and MariaDB.

See docs/DEPLOYMENT-HTTP-TEST.md. No real accounts or email delivery are used.
"""

import hashlib
import http.client
import http.cookies
import json
import os
from pathlib import Path
import re
import secrets
import ssl
import subprocess
import sys
import urllib.parse


def sql(statement):
    host, _, port = os.environ["OPNG_DB_HOST"].partition(":")
    return subprocess.run(
        ["mariadb", "--batch", "--skip-column-names", "-h", host, "-P", port or "3306",
         "-u", os.environ["OPNG_DB_USER"], os.environ["OPNG_DB_NAME"]],
        input=statement, text=True, stdout=subprocess.PIPE, check=True,
        env=dict(os.environ, MYSQL_PWD=os.environ["OPNG_DB_PASS"]),
    ).stdout.strip()


def request(port, path, headers=None, data=None):
    if port == 8080:
        conn = http.client.HTTPConnection("127.0.0.1", port, timeout=10)
    else:
        # Fixture-only loopback certificate.
        conn = http.client.HTTPSConnection("127.0.0.1", port, timeout=10, context=ssl._create_unverified_context())
    try:
        conn.request("GET" if data is None else "POST", path, data, headers or {})
        res = conn.getresponse()
        return res.status, res.headers, res.read()
    finally:
        conn.close()


def supervisor(action):
    subprocess.run(["supervisorctl", "-c", "/etc/supervisord.conf", action, "php-fpm"], check=True)


def exercise(evidence):
    suffix = secrets.token_hex(8)
    user = "http_" + suffix
    token = "opng_" + secrets.token_hex(32)
    digest = lambda s: hashlib.sha256(s.encode()).hexdigest()
    markers = [token]
    failures = []
    sql(f"INSERT INTO users(username,email,password_hash) VALUES ('{user}','{user}@example.test','fixture')")
    uid = int(sql(f"SELECT id FROM users WHERE username='{user}'"))
    try:
        sql(f"INSERT INTO api_tokens(user_id,token_hash) VALUES ({uid},'{digest(token)}');"
            f"INSERT INTO entries(id,topic,language,text,user_id) VALUES ('{suffix}','{suffix}','text','fixture',{uid})")
        for port in (443, 8443, 8080):
            bearer = {"Authorization": "Bearer " + token}
            for route, headers, expected in [
                ("entries", {}, 401), ("entries?topic=" + suffix, bearer, 200),
                ("entries/" + suffix, bearer, 200), ("missing", bearer, 404),
            ]:
                status, h, body = request(port, "/api/v1/" + route, headers)
                assert status == expected, (port, route, status)
                assert h.get_content_type() == "application/json" and "no-store" in h["Cache-Control"]
                assert h["X-Request-ID"] and h["X-Content-Type-Options"] == "nosniff"
                payload = json.loads(body)
                if expected == 401:
                    assert h["WWW-Authenticate"].startswith("Bearer")
                if "?" in route:
                    assert [r["id"] for r in payload["data"]] == [suffix]
                elif expected == 200:
                    assert payload["data"]["text"] == "fixture"

            marker = secrets.token_hex(32)
            markers.append(marker)
            sql(f"UPDATE users SET reset_token='{digest(marker)}', reset_token_expires=NOW()+INTERVAL 1 HOUR WHERE id={uid}")
            path = "/auth/reset_password.php"
            status, h, _ = request(port, path + "?token=" + marker)
            assert status == 303 and h["Location"] == "reset_password.php", (port, status)
            assert h.get_all("Referrer-Policy")[-1] == "no-referrer", (port, h.get_all("Referrer-Policy"))
            cookie = http.cookies.SimpleCookie()
            for value in h.get_all("Set-Cookie", []):
                cookie.load(value)
            # Even an older client forwarding the previous URL must not copy
            # its query into an access log through the Referer header.
            origin = ("http" if port == 8080 else "https") + f"://127.0.0.1:{port}"
            headers = {"Cookie": "; ".join(v.key + "=" + v.value for v in cookie.values()),
                       "Referer": origin + path + "?token=" + marker}
            status, _, page = request(port, path, headers)
            assert status == 200
            csrf = re.search(rb'name="csrf_token" value="([^"]+)"', page)[1].decode()
            headers["Content-Type"] = "application/x-www-form-urlencoded"
            status, _, _ = request(port, path, headers, urllib.parse.urlencode(
                {"csrf_token": csrf, "password": secrets.token_hex(20)}))
            assert status == 303 and sql(f"SELECT reset_token IS NULL FROM users WHERE id={uid}") == "1"
        audit = sql(f"SELECT action,target FROM audit_log WHERE actor_id={uid}")
        assert audit.count("auth.reset_completed") == 3 and not any(m in audit for m in markers)
        supervisor("stop")
        try:
            for port in (443, 8443, 8080):
                status, h, _ = request(port, "/auth/reset_password.php?token=" + markers[-1],
                                       {"Referer": "https://localhost/auth/reset_password.php?token=" + markers[-1]})
                assert status in (502, 503), (port, status)
                assert h["X-OPNG-Trace"], port
                failures.append([port, h["X-OPNG-Trace"], status])
        finally:
            supervisor("start")
        evidence.write_text(json.dumps([markers, failures]))
    finally:
        sql(f"DELETE FROM entries WHERE id='{suffix}'; DELETE FROM users WHERE id={uid}")


def check(evidence, paths):
    markers, failures = json.loads(evidence.read_text())
    logs = "\n".join(Path(path).read_text() for path in paths)
    assert "/var/www/opng/src/auth/reset_password.php" in logs, "missing PHP-FPM access records"
    if any(value in logs for value in markers):
        raise SystemExit("FAIL: credential marker found in captured logs")
    for port, trace, status in failures:
        assert any(trace in line and str(status) in line and "reset_password.php" in line
                   for line in logs.splitlines()), (port, "failure not correlated")
    evidence.unlink()
    print("PASS: no credential markers in captured logs; all three failures correlated")


if __name__ == "__main__":
    os.umask(0o077)
    if sys.argv[1] == "check" and len(sys.argv) >= 4:
        check(Path(sys.argv[2]), sys.argv[3:])
    elif sys.argv[1] == "exercise" and os.getenv("OPNG_TEST_ALLOW_DATABASE_FIXTURES") == "1":
        exercise(Path(sys.argv[2]))
        print("PASS: API, session exchange, reset and backend failure on all three profiles")
    else:
        raise SystemExit("Expected an explicitly enabled fixture or captured logs")
