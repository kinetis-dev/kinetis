# Configuration file for the Sphinx documentation builder.
# https://www.sphinx-doc.org/en/master/usage/configuration.html

project = "Kinetis"
copyright = "2026, Alon Noy"
author = "Alon Noy"
release = "1.0.0-dev"

extensions = [
    "myst_parser",
    "sphinx_copybutton",
    "sphinx_design",
    "sphinxext.opengraph",
    "sphinx_sitemap",
]

myst_enable_extensions = [
    "colon_fence",
    "deflist",
    "fieldlist",
    "substitution",
    "tasklist",
]

# Auto-generates an #anchor-slug for every heading up to this depth, so
# in-page links like [text](#some-heading) resolve without each heading
# needing an explicit {#custom-id}.
myst_heading_anchors = 4

source_suffix = {
    ".md": "markdown",
}

templates_path = ["_templates"]
exclude_patterns = ["_build", "Thumbs.db", ".DS_Store"]


def setup(app):
    # Pygments' stock PHP lexer only starts highlighting inside a literal
    # `<?php` tag — a bare snippet (a `use` statement plus a class body, no
    # opening tag) gets lexed as plain HTML/text instead, so every token
    # falls into the generic "Other" class and renders with no color at
    # all. Most code-block snippets on this site are deliberately partial
    # fragments, not complete files, so they don't have one. Registering
    # the lexer with startinline=True treats the whole block as PHP
    # unconditionally, regardless of whether a `<?php` tag is present.
    from pygments.lexers.php import PhpLexer
    from sphinx.highlighting import lexers

    lexers["php"] = PhpLexer(startinline=True)

# -- HTML output -------------------------------------------------------------

html_theme = "furo"
html_static_path = ["_static"]
# Copied verbatim to the site root (not under _static/), so it's served at
# /llms.txt — the flat, plain-text index AI coding tools look for there,
# per https://llmstxt.org's convention.
html_extra_path = ["_extra"]
html_css_files = ["custom.css"]
html_logo = "_static/logo.svg"
# Deliberately unset: the favicon is kinetis-website's own
# /assets/favicon.svg, not a file this build ships. Sphinx always renders
# html_favicon's <link> with a page-relative pathto() href anyway, with no
# leading-slash option, so it couldn't point at a site-root asset even if
# one existed here. docs/_templates/page.html's `extrahead` block emits
# the real <link rel="icon"> instead (see its comment for why).
html_title = "Kinetis"

# The real, deployed URL — required by sphinx-sitemap to build sitemap.xml
# and consulted by sphinxext-opengraph for canonical/OG URLs below. Sphinx
# itself uses it to emit a <link rel="canonical"> on every page. Served
# under kinetis-website's own domain at a /docs/ prefix, not a separate
# docs.kinetis.dev subdomain — the trailing slash matters here exactly as
# it would for a bare-domain baseurl, since everything else (sitemap
# entries, canonical links, OG URLs) is built by joining onto it.
html_baseurl = "https://kinetis.dev/docs/"

# -- Open Graph / social previews --------------------------------------------
# sphinxext-opengraph auto-generates <meta name="description"> plus
# og:*/twitter:* tags per page from each page's own first paragraph — no
# per-page front matter needed. ogp_social_cards (matplotlib-rendered
# per-page preview images) is left unset: the real logo below is enough,
# and pulling in matplotlib for this alone isn't worth the weight.
ogp_site_url = html_baseurl
ogp_site_name = "Kinetis"
ogp_image = "_static/og-image.png"
# A real PNG rendered from the actual logo.svg (rsvg-convert -w 800 -h 840),
# not a fabricated banner — matches the source SVG's own 400x420 viewBox.
# twitter:card defaults to "summary" (a square-ish preview) rather than
# "summary_large_image", which expects a 1200x630 banner this image isn't.

# -- Sitemap ------------------------------------------------------------------
# Written to sitemap.xml at the build root; html_baseurl above is all it
# needs. robots.txt (docs/_extra/robots.txt) points crawlers at it.
sitemap_url_scheme = "{link}"

html_theme_options = {
    # The logo's own secondary-title color (.brand-sub, "FRAMEWORK" in
    # logo.svg) — same hex in both modes, matching how the logo itself
    # stays legible on both light and dark backgrounds unchanged.
    "light_css_variables": {
        "color-brand-primary": "#D97706",
        "color-brand-content": "#D97706",
        "color-brand-visited": "#D97706",
    },
    "dark_css_variables": {
        "color-brand-primary": "#D97706",
        "color-brand-content": "#D97706",
        "color-brand-visited": "#D97706",
    },
}

# -- LaTeX / PDF output ------------------------------------------------------
# `make latexpdf` — theme choice above only affects HTML; this section is
# what actually controls the printed/PDF output.

latex_engine = "xelatex"
latex_elements = {
    "papersize": "letterpaper",
    "pointsize": "10pt",
}
latex_documents = [
    ("index", "kinetis.tex", "Kinetis Documentation", author, "manual"),
]
# Not setting latex_logo: it's an SVG, and LaTeX's default image handling
# doesn't render SVG directly (needs Inkscape + shell-escape wired up) — a
# PNG/PDF export of the logo would need to be added before PDF output is
# actually exercised. PDF/print is a stated long-run goal, not verified yet;
# the HTML build is what's been built and checked so far.
