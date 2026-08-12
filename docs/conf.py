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
html_favicon = "_static/favicon.svg"
html_title = "Kinetis"

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
