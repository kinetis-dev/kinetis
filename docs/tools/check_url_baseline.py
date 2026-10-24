#!/usr/bin/env python3
"""Freeze and verify what the Sphinx build publishes under /docs/.

  capture <build-dir>   write docs/baseline/ from a build
  check <build-dir>     fail when a baselined URL, anchor or head field
                        disappeared or changed

Baselined anchors are the ids inside each page's main landmark (<main> or
role="main"); see PageParser.

See docs/baseline/README.md for the rules around re-capturing.
"""

import argparse
import ast
import json
import re
import sys
import urllib.parse
import urllib.request
# Parses only the Sphinx-built sitemap or the kinetis.dev one, never
# third-party XML; ElementTree does not resolve external entities.
import xml.etree.ElementTree as ET  # nosemgrep: python.lang.security.use-defused-xml.use-defused-xml
from html.parser import HTMLParser
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
DOCS = REPO / "docs"
BASELINE = DOCS / "baseline"

NON_HTML_URLS = ["sitemap.xml", "objects.inv", "searchindex.js"]
AUX_STATUSES = {"keep", "remove-pending", "remove"}
SITEMAP_NS = "{http://www.sitemaps.org/schemas/sitemap/0.9}"

# Curated inputs: capture writes these only when absent and never
# overwrites a hand-edited copy.
CURATED = {
    "aux.txt": """\
# <path> <keep|remove-pending|remove>
# keep: must be present. remove: must be absent. remove-pending: may be present.
llms.txt keep
robots.txt remove-pending
404.html remove-pending
""",
    "external-anchors.txt": """\
# Anchors and pages other sites link to. index.html is served as /docs/.
# The two anchors below are linked externally but absent from the current
# build; they stay disabled until the docs restore them or a redirect lands.
# routing-validation.html#zero-config-openapi-swagger-ui
# concurrency.html#the-suspend-resume-pattern
"""
    + "".join(
        f"{name}.html\n"
        for name in (
            "index appendix appendix-ci appendix-contributing appendix-packages"
            " auth auth-jwt authorization aws-sigv4 benchmarks broadcasting"
            " caching cli concurrency config container core-concepts events"
            " logging mailer mcp middleware migrations performance-tuning"
            " persistence query-builder queue queue-rabbitmq queue-redis"
            " queue-sql queue-sqs revolt-http-client routing-validation"
            " runtime-adapters search-opensearch session storage storage-s3"
            " telemetry testing tutorial"
        ).split()
    ),
    "redirects.txt": """\
# <old-path> <new-url>
# The old path may then be served by a stub whose meta refresh or canonical
# link points at the new URL; its baselined anchors and head fields are skipped.
""",
}


# Elements without an end tag; one marked data-pagefind-ignore is skipped
# alone instead of opening a subtree that never closes.
VOID_TAGS = {"area", "base", "br", "col", "embed", "hr", "img", "input", "link", "meta", "source", "track", "wbr"}


class PageParser(HTMLParser):
    """Collects head metadata, all ids, and the ids inside the main landmark.

    The main landmark is the outermost element that is a <main> tag or has
    role="main": pydata's <main>, or Furo's <article role="main">. Its own id,
    sd- ids and ids inside a data-pagefind-ignore subtree are not collected.
    """

    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.meta = {"title": None, "canonical": None, "og:url": None, "og:image": None, "description": None}
        self.refresh = None
        self.ids = set()
        self.main_ids = set()
        self._in_head = False
        self._title = None
        # Each region is tracked by its opening element's tag name, so nested
        # same-name tags close it at the right end tag.
        self._main_tag = None
        self._main_depth = 0
        self._ignore_tag = None
        self._ignore_depth = 0

    def handle_starttag(self, tag, attrs):
        a = {k: (v or "") for k, v in attrs}
        if tag == "head":
            self._in_head = True
        elif tag == "body":
            self._in_head = False
        if "id" in a:
            self.ids.add(a["id"])
        if self._main_depth:
            if tag == self._main_tag:
                self._main_depth += 1
            if self._ignore_depth:
                self._ignore_depth += tag == self._ignore_tag
            elif "data-pagefind-ignore" in a:
                if tag not in VOID_TAGS:
                    self._ignore_tag, self._ignore_depth = tag, 1
            elif "id" in a and not a["id"].startswith("sd-"):
                self.main_ids.add(a["id"])
        elif tag == "main" or a.get("role") == "main":
            self._main_tag, self._main_depth = tag, 1
            return
        if not self._in_head:
            return
        if tag == "title" and self.meta["title"] is None:
            self._title = []
        elif tag == "link" and "canonical" in a.get("rel", "").split():
            self.meta["canonical"] = self.meta["canonical"] or a.get("href")
        elif tag == "meta":
            key = a.get("property") if a.get("property") in ("og:url", "og:image") else None
            key = key or ("description" if a.get("name") == "description" else None)
            if key and self.meta[key] is None:
                self.meta[key] = a.get("content")
            if a.get("http-equiv", "").lower() == "refresh":
                match = re.search(r"url\s*=\s*['\"]?([^'\"]+)", a.get("content", ""), re.I)
                self.refresh = match and match.group(1).strip()

    def handle_endtag(self, tag):
        if tag == "head":
            self._in_head = False
        elif tag == "title" and self._title is not None:
            self.meta["title"] = " ".join("".join(self._title).split())
            self._title = None
        if self._ignore_depth and tag == self._ignore_tag:
            self._ignore_depth -= 1
        if self._main_depth and tag == self._main_tag:
            self._main_depth -= 1
            if not self._main_depth:
                self._ignore_depth = 0

    def handle_data(self, data):
        if self._title is not None:
            self._title.append(data)


def parse_page(path):
    parser = PageParser()
    parser.feed(path.read_text(encoding="utf-8"))
    parser.close()
    return parser


def read_lines(path):
    if not path.exists():
        return []
    lines = (line.strip() for line in path.read_text(encoding="utf-8").splitlines())
    return [line for line in lines if line and not line.startswith("#")]


def read_aux(baseline):
    aux = {}
    for line in read_lines(baseline / "aux.txt"):
        path, status = line.split()
        if status not in AUX_STATUSES:
            raise SystemExit(f"aux.txt: unknown status {status!r} for {path}")
        aux[path] = status
    return aux


def read_redirects(baseline):
    return dict(line.split() for line in read_lines(baseline / "redirects.txt"))


def sitemap_locs(xml_text):
    root = ET.fromstring(xml_text)
    return sorted(loc.text.strip() for loc in root.iter(f"{SITEMAP_NS}loc"))


def sitemap_excludes(conf_py):
    match = re.search(r"^sitemap_excludes\s*=\s*(\[.*?\])", conf_py.read_text(encoding="utf-8"), re.S | re.M)
    return sorted(ast.literal_eval(match.group(1))) if match else []


def html_pages(build, aux):
    """Built HTML pages, excluding _static/ and aux.txt-governed files."""
    return sorted(
        p.relative_to(build).as_posix()
        for p in build.rglob("*.html")
        if p.relative_to(build).parts[0] != "_static" and p.relative_to(build).as_posix() not in aux
    )


def capture(build, baseline=BASELINE, docs=DOCS):
    baseline.mkdir(parents=True, exist_ok=True)
    for name, content in CURATED.items():
        if not (baseline / name).exists():
            (baseline / name).write_text(content, encoding="utf-8")
    pages = html_pages(build, read_aux(baseline))
    parsed = {page: parse_page(build / page) for page in pages}
    urls = sorted(pages + [name for name in NON_HTML_URLS if (build / name).exists()])
    meta = {
        "pages": {page: p.meta for page, p in parsed.items()},
        "sitemap": {
            "locs": sitemap_locs((build / "sitemap.xml").read_text(encoding="utf-8")),
            "excluded": sitemap_excludes(docs / "conf.py"),
        },
    }
    anchors = {page: sorted(p.main_ids) for page, p in parsed.items()}
    (baseline / "urls.txt").write_text("".join(f"{u}\n" for u in urls), encoding="utf-8")
    (baseline / "anchors.json").write_text(json.dumps(anchors, indent=1, sort_keys=True) + "\n", encoding="utf-8")
    (baseline / "meta.json").write_text(json.dumps(meta, indent=1, sort_keys=True, ensure_ascii=False) + "\n", encoding="utf-8")
    sources = sorted(p.name for p in docs.glob("*.md"))
    (baseline / "sources.txt").write_text("".join(f"{s}\n" for s in sources), encoding="utf-8")


def same_target(base, page, found, target):
    base = urllib.parse.urljoin(base, page)
    return found is not None and urllib.parse.urljoin(base, found) == urllib.parse.urljoin(base, target)


def check(build, baseline=BASELINE, docs=DOCS, catalogue=None):
    errors = []
    redirects = read_redirects(baseline)
    baseurl = re.search(r"^html_baseurl\s*=\s*['\"]([^'\"]+)", (docs / "conf.py").read_text(encoding="utf-8"), re.M)
    baseurl = baseurl.group(1) if baseurl else ""
    parsed = {}

    def page(path):
        if path not in parsed:
            parsed[path] = parse_page(build / path) if (build / path).is_file() else None
        return parsed[path]

    for old, target in redirects.items():
        stub = page(old)
        if stub is None:
            errors.append(f"redirect {old}: stub missing (expected redirect to {target})")
        elif not (same_target(baseurl, old, stub.refresh, target) or same_target(baseurl, old, stub.meta["canonical"], target)):
            errors.append(f"redirect {old}: expected target {target!r}, actual refresh {stub.refresh!r} canonical {stub.meta['canonical']!r}")

    for url in read_lines(baseline / "urls.txt"):
        if not (build / url).is_file():
            errors.append(f"url {url}: missing from build")

    for path, ids in json.loads((baseline / "anchors.json").read_text(encoding="utf-8")).items():
        if path in redirects or page(path) is None:
            continue
        for anchor in ids:
            if anchor not in page(path).main_ids:
                errors.append(f"anchor {path}#{anchor}: missing from the main landmark")

    meta = json.loads((baseline / "meta.json").read_text(encoding="utf-8"))
    for path, fields in meta["pages"].items():
        if path in redirects or page(path) is None:
            continue
        for field, expected in fields.items():
            actual = page(path).meta[field]
            if actual != expected:
                errors.append(f"meta {path} {field}: expected {expected!r}, actual {actual!r}")

    sitemap = build / "sitemap.xml"
    built_locs = set(sitemap_locs(sitemap.read_text(encoding="utf-8"))) if sitemap.is_file() else set()
    for loc in meta["sitemap"]["locs"]:
        if loc not in built_locs:
            errors.append(f"sitemap loc {loc}: missing from sitemap.xml")

    for source in read_lines(baseline / "sources.txt"):
        if not (docs / source).is_file():
            errors.append(f"source docs/{source}: missing")

    for path, status in read_aux(baseline).items():
        present = (build / path).exists()
        if status == "keep" and not present:
            errors.append(f"aux {path}: status keep, expected present, actual missing")
        elif status == "remove" and present:
            errors.append(f"aux {path}: status remove, expected absent, actual present")

    for entry in read_lines(baseline / "external-anchors.txt"):
        path, _, anchor = entry.partition("#")
        target = page(path)
        if target is None:
            errors.append(f"external {entry}: page {path} missing")
        elif anchor and anchor not in target.ids:
            errors.append(f"external {entry}: anchor #{anchor} missing from {path}")

    if catalogue is not None:
        for slug in catalogue_slugs(catalogue):
            if not (build / f"{slug}.html").is_file():
                errors.append(f"catalogue slug {slug}: expected {slug}.html in build, actual missing")

    return errors


def catalogue_slugs(catalogue):
    return re.findall(r"new DocsPage\(\s*'([^']+)'", Path(catalogue).read_text(encoding="utf-8"))


def reconcile_live(url, baseline=BASELINE):
    try:
        # url is the operator's --live argument, not untrusted input.
        with urllib.request.urlopen(url, timeout=30) as response:  # nosemgrep: python.lang.security.audit.dynamic-urllib-use-detected.dynamic-urllib-use-detected
            live = set(sitemap_locs(response.read()))
    except OSError as error:
        return [f"live sitemap {url}: fetch failed: {error}"]
    sitemap = json.loads((baseline / "meta.json").read_text(encoding="utf-8"))["sitemap"]
    captured = set(sitemap["locs"])
    errors = [f"live sitemap: {loc} captured but missing live" for loc in sorted(captured - live)]
    errors += [f"live sitemap: {loc} live but missing from captured" for loc in sorted(live - captured)]
    for page in sitemap["excluded"]:
        state = "present in" if any(loc.endswith("/" + page) for loc in live) else "absent from"
        print(f"live sitemap: {page} excluded, {state} live sitemap")
        if state == "present in":
            errors.append(f"live sitemap: excluded {page} is present live")
    print(f"live sitemap: {len(live)} live locs, {len(captured)} captured locs, {len(live & captured)} shared")
    return errors


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = parser.add_subparsers(dest="command", required=True)
    sub.add_parser("capture").add_argument("build", type=Path)
    check_parser = sub.add_parser("check")
    check_parser.add_argument("build", type=Path)
    check_parser.add_argument("--catalogue", type=Path)
    check_parser.add_argument("--live", metavar="SITEMAP_URL")
    args = parser.parse_args(argv)

    if args.command == "capture":
        capture(args.build)
        print(f"captured {args.build} into {BASELINE}")
        return 0
    errors = check(args.build, catalogue=args.catalogue)
    if args.catalogue:
        print(f"catalogue: {len(catalogue_slugs(args.catalogue))} DocsPage slugs in {args.catalogue}")
    if args.live:
        errors += reconcile_live(args.live)
    for error in errors:
        print(error)
    print(f"url baseline: {len(errors)} violation(s)")
    return 1 if errors else 0


if __name__ == "__main__":
    sys.exit(main())
