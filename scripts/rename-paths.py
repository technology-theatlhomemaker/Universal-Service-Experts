#!/usr/bin/env python3
"""Rename WP-flavored directories under public_html/ and rewrite all
path references inside the 8 HTML pages.

Idempotent: re-running on an already-renamed tree is a no-op."""

from __future__ import annotations

import re
import shutil
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
PUBLIC = ROOT / 'public_html'

# (from, to) directory + file moves, in order.
MOVES: list[tuple[str, str]] = [
    ('wp-content/uploads',                          'media'),
    ('wp-content/plugins/elementor',                'lib/elementor'),
    ('wp-content/plugins/elementor-pro',            'lib/elementor-pro'),
    ('wp-content/themes/hello-elementor',           'lib/theme'),
    ('wp-includes/js/jquery',                       'lib/jquery'),
    ('wp-includes/js/dist/hooks.min.js',            'lib/hooks.min.js'),
    ('wp-includes/js/dist/i18n.min.js',             'lib/i18n.min.js'),
    ('wp-includes/js/wp-emoji-release.min.js',      'lib/emoji-release.min.js'),
]

# Path-prefix rewrites, longest-first to avoid prefix collisions.
# Forward-slash form; the rewriter also handles the JSON-escaped (\/)
# variant of every entry, plus optional leading "/", "../" or "\/".
REWRITES: list[tuple[str, str]] = [
    ('wp-content/uploads/',                         '/media/'),
    ('wp-content/plugins/elementor-pro/',           '/lib/elementor-pro/'),
    ('wp-content/plugins/elementor/',               '/lib/elementor/'),
    ('wp-content/themes/hello-elementor/',          '/lib/theme/'),
    ('wp-includes/js/jquery/',                      '/lib/jquery/'),
    ('wp-includes/js/dist/hooks.min.js',            '/lib/hooks.min.js'),
    ('wp-includes/js/dist/i18n.min.js',             '/lib/i18n.min.js'),
    ('wp-includes/js/wp-emoji-release.min.js',      '/lib/emoji-release.min.js'),
    ('wp-includes/js/wp-emoji-loader.min.js',       '/lib/emoji-loader.min.js'),  # sourceURL comment
    # Dead WP-flavored endpoints in elementor*FrontendConfig blobs;
    # nothing fetches them today (no admin), so the rename is purely
    # cosmetic — but it removes the wp-* substring from the HTML.
    ('wp-admin/admin-ajax.php',                     '/api/admin-ajax.php'),
    # Bare-prefix forms (no trailing slash). MUST come AFTER the
    # trailing-slash entries so longer matches win first.
    ('wp-content/uploads',                          '/media'),
    ('wp-content/plugins/elementor-pro',            '/lib/elementor-pro'),
    ('wp-content/plugins/elementor',                '/lib/elementor'),
]

PAGES = [
    'index.html',
    'contact-us/index.html',
    'electrical-services/index.html',
    'handyman-services/index.html',
    'hvac/index.html',
    'metering-services/index.html',
    'plumbing/index.html',
    'thank-you/index.html',
]


def move_files() -> None:
    for src, dst in MOVES:
        s, d = PUBLIC / src, PUBLIC / dst
        if not s.exists():
            print(f'  skip (not present): {src}')
            continue
        d.parent.mkdir(parents=True, exist_ok=True)
        if d.exists():
            print(f'  skip (target exists): {src} → {dst}')
            continue
        shutil.move(str(s), str(d))
        print(f'  moved: {src} → {dst}')


def remove_speculation_rules(text: str) -> str:
    """Drop the inline <script type="speculationrules">…</script> block.
    Its JSON listed `/wp-*.php`, `/wp-admin/*`, `/wp-content/*`, etc. as
    no-prefetch globs; with WordPress gone those globs match nothing and
    the rules are pure noise. Removing the block has no behavioral
    effect — it was a perf hint at best."""
    return re.sub(
        r'<script[^>]*\btype=["\']speculationrules["\'][^>]*>.*?</script>\s*',
        '',
        text,
        flags=re.S,
    )


def rewrite_html() -> None:
    for page in PAGES:
        p = PUBLIC / page
        if not p.exists():
            print(f'  skip (missing): {page}')
            continue
        before = p.read_text(encoding='utf-8')
        text = before

        # Drop the speculation-rules block before path rewriting so we
        # never have to chase its WP globs.
        text = remove_speculation_rules(text)

        for src, dst in REWRITES:
            # Plain forward-slash form. The optional prefix consumes a
            # leading "../", "/", or nothing. The negative lookbehind
            # rejects mid-identifier matches (e.g. "foo-wp-content/bar").
            text = re.sub(
                r'(?<![\w-])(?:\.\./|/)?' + re.escape(src),
                dst,
                text,
            )
            # JSON-escaped form: every "/" inside the path is "\/".
            esc_src = src.replace('/', '\\/')
            esc_dst = dst.replace('/', '\\/')
            text = re.sub(
                r'(?<![\w-])(?:\\/)?' + re.escape(esc_src),
                esc_dst,
                text,
            )

        if text != before:
            p.write_text(text, encoding='utf-8')
            delta = sum(1 for a, b in zip(before.split('\n'), text.split('\n')) if a != b)
            print(f'  rewrote: {page} ({delta} lines changed)')
        else:
            print(f'  unchanged: {page}')


def cleanup_empty() -> None:
    # Two classes of cleanup:
    #   - 'wp-content', 'wp-includes': must be empty (moves emptied them).
    #     Refuse if anything is left, so we don't lose data accidentally.
    #   - 'feed', 'comments': WordPress RSS leftovers, force-delete.
    must_be_empty = ['wp-content', 'wp-includes']
    force_delete = ['feed', 'comments']

    for d in must_be_empty:
        path = PUBLIC / d
        if not path.exists():
            continue
        leftover = [str(p.relative_to(path)) for p in path.rglob('*') if p.is_file()]
        if leftover:
            print(f'  REFUSED to remove {d}/ — still has {len(leftover)} files:')
            for f in leftover[:5]:
                print(f'    - {f}')
            if len(leftover) > 5:
                print(f'    … and {len(leftover) - 5} more')
            continue
        shutil.rmtree(path)
        print(f'  removed empty: {d}/')

    for d in force_delete:
        path = PUBLIC / d
        if not path.exists():
            continue
        n = sum(1 for _ in path.rglob('*') if _.is_file())
        shutil.rmtree(path)
        print(f'  removed orphan: {d}/ ({n} file{"s" if n != 1 else ""})')


def report_leftovers() -> int:
    leftover: list[tuple[str, str]] = []
    for page in PAGES:
        p = PUBLIC / page
        if not p.exists():
            continue
        text = p.read_text(encoding='utf-8')
        # Match wp-content/, wp-includes/, wp-emoji, wp-admin in path-ish strings.
        for ref in re.findall(
            r'(?<![\w-])(?:wp-content|wp-includes|wp-admin)[/\\][A-Za-z0-9./_\\-]*',
            text,
        ):
            leftover.append((page, ref))
    if leftover:
        print(f'\nWARN: {len(leftover)} wp-* path reference(s) still in HTML:')
        for page, ref in leftover[:15]:
            print(f'  {page}: {ref}')
        if len(leftover) > 15:
            print(f'  … and {len(leftover) - 15} more')
    else:
        print('\nOK: all 8 HTML pages are wp-clean.')
    return len(leftover)


def main() -> None:
    print('== move files/dirs ==')
    move_files()
    print('\n== rewrite HTML ==')
    rewrite_html()
    print('\n== cleanup empty dirs ==')
    cleanup_empty()
    n = report_leftovers()
    sys.exit(0 if n == 0 else 1)


if __name__ == '__main__':
    main()
