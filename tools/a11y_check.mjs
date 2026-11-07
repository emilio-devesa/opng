#!/usr/bin/env node
// Rendered HTML validation and axe accessibility checks (HYG-02).
//
// CI starts a PHP server against a seeded MySQL/MariaDB database, then runs
// this script. It checks the pages named in the audit (index, view, login,
// register and admin) with:
//   - html-validate over the rendered document;
//   - axe-core with the WCAG 2.x rule sets;
//   - a single visible <h1> per page.
//
// Serious and critical axe violations fail the job. HTML validation errors
// fail as well. Set OPNG_A11Y_BASE if the server is not on the default port.

import AxeBuilder from "@axe-core/playwright";
import { HtmlValidate } from "html-validate";
import { chromium } from "playwright";

const base = (process.env.OPNG_A11Y_BASE || "http://127.0.0.1:8080").replace(/\/$/, "");
const email = process.env.OPNG_A11Y_EMAIL || "root@example.test";
const password = process.env.OPNG_A11Y_PASSWORD || "Password123!";

const pages = [
    { name: "index", path: "/index.php" },
    { name: "view", path: "/view.php?id=seed0002" },
    { name: "login", path: "/auth/login.php" },
    { name: "register", path: "/auth/register.php" },
    { name: "admin", path: "/admin/admin.php", requiresLogin: true },
];

// Validate the server-rendered English document and one translated catalog.
// The SSR transform rebuilds the DOM, so a broken locale must fail too.
const locales = ["en", "es"];

const htmlvalidate = new HtmlValidate({
    extends: ["html-validate:recommended"],
    rules: {
        "no-inline-style": "error",
        "no-trailing-whitespace": "off",
        "wcag/h30": "error",
        "wcag/h32": "error",
        "wcag/h36": "error",
        "wcag/h37": "error",
        "wcag/h63": "error",
        "wcag/h67": "error",
        "wcag/h71": "error",
    },
});

const failures = [];

function fail(message) {
    failures.push(message);
    console.error("FAIL: " + message);
}

async function signIn(page) {
    await page.goto(base + "/auth/login.php", { waitUntil: "domcontentloaded" });
    await page.fill("#email", email);
    await page.fill("#password", password);
    await Promise.all([
        page.waitForURL((url) => !url.pathname.endsWith("/auth/login.php"), { timeout: 15000 }),
        page.click("button[type=submit]"),
    ]);
}

const browser = await chromium.launch();
try {
    for (const locale of locales) {
        const context = await browser.newContext();
        await context.addCookies([
            { name: "opng_lang", value: locale, url: base, sameSite: "Lax" },
        ]);
        const page = await context.newPage();
        const label = (target) => (locale === "en" ? target.name : `${target.name} [${locale}]`);

        let signedIn = false;
        for (const target of pages) {
            if (target.requiresLogin && !signedIn) {
                try {
                    await signIn(page);
                    signedIn = true;
                } catch (error) {
                    fail(`${label(target)}: administrator sign-in failed (${error.message})`);
                    continue;
                }
            }

            let response;
            try {
                response = await page.goto(base + target.path, { waitUntil: "networkidle" });
            } catch (error) {
                fail(`${label(target)}: navigation failed (${error.message})`);
                continue;
            }
            if (!response || response.status() >= 400) {
                fail(`${label(target)}: unexpected HTTP status ${response ? response.status() : "none"}`);
                continue;
            }

            const headingCount = await page.locator("h1").count();
            if (headingCount !== 1) {
                fail(`${label(target)}: expected exactly one <h1>, found ${headingCount}`);
            }

            const axeResults = await new AxeBuilder({ page })
                .withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa"])
                .analyze();
            for (const violation of axeResults.violations) {
                const summary = `${label(target)}: axe ${violation.id} (${violation.impact}) — ${violation.help}`;
                if (violation.impact === "serious" || violation.impact === "critical") {
                    fail(summary);
                    for (const node of violation.nodes.slice(0, 5)) {
                        console.error("  " + node.target.join(" "));
                    }
                } else {
                    console.warn("WARN: " + summary);
                }
            }

            const html = await response.text();
            const report = await htmlvalidate.validateString(html, target.path);
            if (!report.valid) {
                for (const result of report.results) {
                    for (const message of result.messages) {
                        fail(
                            `${label(target)}: html-validate line ${message.line} — ${message.ruleId}: ${message.message}`
                        );
                    }
                }
            }

            console.log(`PASS: ${label(target)} (${axeResults.violations.length} axe violations)`);
        }

        await context.close();
    }
} finally {
    await browser.close();
}

if (failures.length > 0) {
    console.error(`\n${failures.length} accessibility or HTML validation failure(s).`);
    process.exit(1);
}

console.log("\nAccessibility and HTML validation checks passed.");
