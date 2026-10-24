import shutil
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import check_url_baseline as cub  # noqa: E402

PAGE = """<!doctype html>
<html><head>
<title>{title}</title>
<link rel="canonical" href="https://kinetis.dev/docs/{name}">
<meta property="og:url" content="https://kinetis.dev/docs/{name}" />
<meta property="og:image" content="https://kinetis.dev/docs/_static/og-image.png" />
<meta name="description" content="A &amp; B &#8212; C" />
</head><body>
<svg><symbol id="svg-toc"><title>Contents</title></symbol></svg>
<article role="main" id="furo-main-content">
<section id="{slug}"><h1>Heading</h1>
<section id="setup"><h2>Setup</h2><div id="sd-tab-set-0"></div></section>
</section>
</article>
</body></html>
"""

SITEMAP = """<?xml version='1.0' encoding='utf-8'?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">{}</urlset>"""


class CheckUrlBaselineTest(unittest.TestCase):
    def setUp(self):
        self.root = Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, self.root)
        self.build = self.root / "build"
        self.docs = self.root / "docs"
        self.baseline = self.root / "baseline"
        self.catalogue = self.root / "DocsCatalogue.php"
        self.build.mkdir()
        self.docs.mkdir()
        (self.build / "_static").mkdir()
        (self.build / "_static" / "skip.html").write_text("<html></html>")
        for slug in ("index", "guide"):
            self.write_page(slug, "Kinetis" if slug == "index" else "Guide - Kinetis")
            (self.docs / f"{slug}.md").write_text(f"# {slug}\n")
        self.write_sitemap(["index.html", "guide.html"])
        for name in ("objects.inv", "searchindex.js", "llms.txt", "robots.txt", "404.html"):
            (self.build / name).write_text("x")
        (self.docs / "conf.py").write_text('html_baseurl = "https://kinetis.dev/docs/"\nsitemap_excludes = [\n    "hidden.html",\n]\n')
        self.catalogue.write_text("new DocsPage('index', 'Kinetis', 'x'),\nnew DocsPage('guide', 'Guide', 'y'),\n")
        self.baseline.mkdir()
        (self.baseline / "external-anchors.txt").write_text("# comment\nguide.html#setup\nindex.html\n")
        cub.capture(self.build, self.baseline, self.docs)

    def write_page(self, slug, title):
        (self.build / f"{slug}.html").write_text(PAGE.format(title=title, name=f"{slug}.html", slug=slug))

    def write_sitemap(self, pages):
        locs = "".join(f"<url><loc>https://kinetis.dev/docs/{p}</loc></url>" for p in pages)
        (self.build / "sitemap.xml").write_text(SITEMAP.format(locs))

    def check(self):
        return cub.check(self.build, self.baseline, self.docs, self.catalogue)

    def assertViolation(self, expected):
        errors = self.check()
        self.assertTrue(any(expected in e for e in errors), f"{expected!r} not in {errors}")

    def test_unchanged_build_passes(self):
        self.assertEqual([], self.check())

    def test_capture_records_urls_head_fields_and_sources(self):
        urls = (self.baseline / "urls.txt").read_text().split()
        self.assertEqual(["guide.html", "index.html", "objects.inv", "searchindex.js", "sitemap.xml"], urls)
        meta = cub.json.loads((self.baseline / "meta.json").read_text())
        self.assertEqual("Guide - Kinetis", meta["pages"]["guide.html"]["title"])
        self.assertEqual("A & B — C", meta["pages"]["guide.html"]["description"])
        self.assertEqual(["hidden.html"], meta["sitemap"]["excluded"])
        self.assertEqual("guide.md\nindex.md\n", (self.baseline / "sources.txt").read_text())

    def test_article_own_id_and_sd_ids_are_excluded(self):
        anchors = cub.json.loads((self.baseline / "anchors.json").read_text())
        self.assertEqual(["guide", "setup"], anchors["guide.html"])

    def test_capture_keeps_curated_files(self):
        (self.baseline / "aux.txt").write_text("llms.txt keep\n")
        cub.capture(self.build, self.baseline, self.docs)
        self.assertEqual("llms.txt keep\n", (self.baseline / "aux.txt").read_text())
        self.assertEqual("# comment\nguide.html#setup\nindex.html\n", (self.baseline / "external-anchors.txt").read_text())

    def test_deleted_heading_id_fails(self):
        html = (self.build / "guide.html").read_text().replace('id="setup"', "")
        (self.build / "guide.html").write_text(html)
        self.assertViolation('anchor guide.html#setup: missing from <article role="main">')

    def test_changed_title_fails(self):
        self.write_page("guide", "Guide &#8212; Kinetis")
        self.assertViolation("meta guide.html title: expected 'Guide - Kinetis', actual 'Guide — Kinetis'")

    def test_deleted_page_fails(self):
        (self.build / "guide.html").unlink()
        self.assertViolation("url guide.html: missing from build")

    def test_lost_sitemap_loc_fails(self):
        self.write_sitemap(["index.html"])
        self.assertViolation("sitemap loc https://kinetis.dev/docs/guide.html: missing from sitemap.xml")

    def test_missing_source_fails(self):
        (self.docs / "guide.md").unlink()
        self.assertViolation("source docs/guide.md: missing")

    def test_missing_keep_aux_file_fails(self):
        (self.build / "llms.txt").unlink()
        self.assertViolation("aux llms.txt: status keep, expected present, actual missing")

    def test_remove_aux_file_present_fails_while_remove_pending_passes(self):
        self.assertEqual([], self.check())
        (self.baseline / "aux.txt").write_text("llms.txt keep\nrobots.txt remove\n404.html remove-pending\n")
        self.assertViolation("aux robots.txt: status remove, expected absent, actual present")
        (self.build / "robots.txt").unlink()
        self.assertEqual([], self.check())

    def test_missing_external_anchor_fails(self):
        (self.baseline / "external-anchors.txt").write_text("guide.html#gone\nmissing.html\n")
        self.assertViolation("external guide.html#gone: anchor #gone missing from guide.html")
        self.assertViolation("external missing.html: page missing.html missing")

    def test_missing_catalogue_slug_fails(self):
        self.catalogue.write_text(self.catalogue.read_text() + "new DocsPage('absent', 'Absent', 'z'),\n")
        self.assertViolation("catalogue slug absent: expected absent.html in build, actual missing")

    def test_redirect_stub_satisfies_moved_page(self):
        (self.baseline / "external-anchors.txt").write_text("index.html\n")
        (self.build / "guide.html").write_text(
            '<html><head><meta http-equiv="refresh" content="0; url=new-guide.html"></head><body></body></html>'
        )
        (self.baseline / "redirects.txt").write_text("guide.html https://kinetis.dev/docs/other.html\n")
        self.assertViolation("redirect guide.html: expected target 'https://kinetis.dev/docs/other.html'")
        (self.baseline / "redirects.txt").write_text("# moved\nguide.html https://kinetis.dev/docs/new-guide.html\n")
        self.assertEqual([], self.check())

    def test_redirect_without_stub_fails(self):
        (self.build / "guide.html").unlink()
        (self.baseline / "redirects.txt").write_text("guide.html new-guide.html\n")
        self.assertViolation("redirect guide.html: stub missing")


if __name__ == "__main__":
    unittest.main()
