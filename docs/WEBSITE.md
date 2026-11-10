# Project website

The public project page is a dependency-free static site published with GitHub
Pages from the `gh-pages` branch:

<https://emilio-devesa.github.io/opng/>

It is intentionally separate from the PHP application so the repository can be
served as documentation without exposing `src/`, `tools/`, configuration files
or the database.

## Branch layout

The `gh-pages` branch contains only the published site:

| Path | Purpose |
|---|---|
| `index.html` | Single-page project site |
| `assets/site.css` | Site styles and dark-mode support |
| `assets/site.js` | Theme toggle, mobile navigation, copy buttons and sticky-header state |
| `assets/screenshots/` | Screenshots copied from the repository `screenshots/` directory |
| `.nojekyll` | Disables Jekyll processing so all static files are served as-is |

There is no build step, no Node dependency and no external CDN request. The
site works from any static host, not only GitHub Pages.

## Preview locally

From a checkout of the `gh-pages` branch:

```bash
python3 -m http.server 8080
```

Then open <http://127.0.0.1:8080/>.

Alternatively, preview the branch directly from the repository checkout:

```bash
git worktree add /tmp/opng-gh-pages gh-pages
cd /tmp/opng-gh-pages
python3 -m http.server 8080
```

## Publish

Push the branch to GitHub:

```bash
git push origin gh-pages
```

Then, in the repository settings, open **Pages** and select:

- **Source:** Deploy from a branch
- **Branch:** `gh-pages`
- **Folder:** `/ (root)`

The site becomes available at the URL above. Every later push to `gh-pages`
redeploys it.

## Update the site

1. Refresh the screenshots in the repository `screenshots/` directory if the
   interface changed.
2. Check out `gh-pages`, replace `assets/screenshots/` with the new files and
   update the copy in `index.html` when features or versions change.
3. Commit and push `gh-pages`.

Keep the version badge, feature list and quick-start commands aligned with
`README.md` and `CHANGELOG.md`.
