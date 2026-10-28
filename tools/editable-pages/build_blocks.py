#!/usr/bin/env python3
"""Turn an approved static page body into Scout Core editable-page content.

Library and command line. The output is block markup made of five Scout Core
blocks (scout-core/includes/editable-pages.php), so the page renders byte for
byte like the approved build while its words and photos are editable on the
page in WordPress:

    scout/box     a layout element. Locked.
    scout/text    a run of words. Editable, with bold, italic and links.
    scout/image   a photo. Replaceable from the Media Library.
    scout/frozen  markup with no words (icons, decorative marks, scripts), kept
                  in the theme's content file, never in the database.
    scout/part    a theme part the theme renders itself (a form, a widget).
                  Mark it in the page as <scout-part name="contact-form" label="Contact form"></scout-part>.

The page sits in one root scout/box with templateLock "contentOnly".
Links to other pages are stored as {{home}}/path/ and theme assets as
{{theme}}/assets/..., swapped for the real addresses when the page renders.

Usage:
    python3 build_blocks.py page BODY.html KEY /path/ CONTENT_DIR [--body-class "home"]
        Writes CONTENT_DIR/KEY.php and adds the page to CONTENT_DIR/index.php.
        BODY.html is the page body only, links and asset paths already tokenized.
        Exits non-zero if the blocks do not render back to the approved page.
    python3 build_blocks.py editor-css OUT.css SITE.css [MORE.css ...] [--body-classes .home,.inner]
        Writes the site stylesheets scoped to the editor canvas.
"""
import json
import re
from html.parser import HTMLParser

VOID = {"area", "base", "br", "col", "embed", "hr", "img", "input", "link", "meta",
        "source", "track", "wbr"}
INLINE = {"a", "b", "strong", "em", "i", "small", "span", "br", "sup", "sub", "abbr",
          "time", "mark", "u", "s", "code", "q", "cite"}
# Never edited as words: interactive, embedded or scripted markup.
FREEZE = {"svg", "script", "style", "form", "button", "select", "option", "textarea",
          "input", "label", "iframe", "video", "audio", "noscript", "hr", "canvas", "template"}
IMAGE = {"img", "picture"}


class Node:
    __slots__ = ("tag", "raw_attrs", "start", "open_end", "close_start", "end", "children", "parent")

    def __init__(self, tag, raw_attrs, start, open_end, parent):
        self.tag, self.raw_attrs, self.start, self.open_end = tag, raw_attrs, start, open_end
        self.close_start = self.end = open_end
        self.children, self.parent = [], parent


class TreeBuilder(HTMLParser):
    """Element tree with exact source offsets, so frozen markup is copied byte for byte."""

    def __init__(self, src):
        super().__init__(convert_charrefs=False)
        self.src = src
        self.line_starts = [0]
        for m in re.finditer("\n", src):
            self.line_starts.append(m.end())
        self.root = Node("#root", "", 0, 0, None)
        self.cur = self.root

    def abs_pos(self):
        line, col = self.getpos()
        return self.line_starts[line - 1] + col

    def _open(self, tag, closed):
        start = self.abs_pos()
        raw = self.get_starttag_text()
        attrs = raw[len(tag) + 1:-2 if raw.endswith("/>") else -1]
        node = Node(tag, attrs, start, start + len(raw), self.cur)
        self.cur.children.append(node)
        if not closed and tag not in VOID:
            self.cur = node

    def handle_starttag(self, tag, attrs):
        self._open(tag, False)

    def handle_startendtag(self, tag, attrs):
        self._open(tag, True)

    def handle_endtag(self, tag):
        pos = self.abs_pos()
        end = self.src.index(">", pos) + 1
        node = self.cur
        while node is not self.root and node.tag != tag:
            node = node.parent
        if node is self.root:
            return
        node.close_start, node.end = pos, end
        self.cur = node.parent


def raw(src, n):
    return src[n.start:n.end]


def inner(src, n):
    return src[n.open_end:n.close_start]


def has_words(src, n):
    text = re.sub(r"<[^>]*>", "", inner(src, n) if n.children or n.tag not in VOID else "")
    return bool(re.sub(r"&nbsp;|&#160;|\s", "", text))


def inline_pure(src, n):
    return n.tag in INLINE and 'aria-hidden="true"' not in n.raw_attrs \
        and all(inline_pure(src, c) for c in n.children)


def words_anywhere(src, n):
    if n.tag in FREEZE or n.tag in IMAGE:
        return False
    gaps = gap_texts(src, n)
    if any(re.sub(r"&nbsp;|&#160;|\s", "", g) for g in gaps):
        return True
    return any(words_anywhere(src, c) for c in n.children)


def gap_texts(src, n):
    out, pos = [], n.open_end
    for c in n.children:
        out.append(src[pos:c.start])
        pos = c.end
    out.append(src[pos:n.close_start])
    return out


def words(html):
    """Source line breaks and indentation inside text become one space. The block
    editor shows a stored newline as a line break and saves it back as <br>."""
    return re.sub(r"[ \t]*\n\s*", " ", html)


def ws(text):
    if not text:
        return ""
    return "\n" if "\n" in text else (" " if text.isspace() else "")


class Converter:
    def __init__(self, src, page_key):
        self.src, self.key = src, page_key
        self.frozen = {}

    def freeze(self, html):
        ref = f"{self.key}:{len(self.frozen) + 1}"
        self.frozen[ref] = html
        return {"name": "scout/frozen", "attrs": {"ref": ref}}

    def node(self, n):
        s = self.src
        if n.tag == "scout-part":
            name = re.search(r'name="([^"]+)"', n.raw_attrs).group(1)
            label = re.search(r'label="([^"]*)"', n.raw_attrs)
            return {"name": "scout/part", "attrs": {"name": name, "label": label.group(1) if label else ""}}
        if n.tag in IMAGE:
            return {"name": "scout/image", "attrs": {"ref": self.freeze(raw(s, n))["attrs"]["ref"]}}
        if n.tag in FREEZE:
            return self.freeze(raw(s, n))
        photo = self.has_photo(n)
        if not photo and (not words_anywhere(s, n) or 'aria-hidden="true"' in n.raw_attrs):
            return self.freeze(raw(s, n))
        loose = re.sub(r"&nbsp;|&#160;|\s", "", "".join(gap_texts(s, n)))
        element_row = len(n.children) >= 2 and not loose
        if n.tag not in VOID and not element_row and all(inline_pure(s, c) for c in n.children) and has_words(s, n):
            return {"name": "scout/text", "attrs": {"tag": n.tag, "attrs": n.raw_attrs, "content": words(inner(s, n))}}
        return {"name": "scout/box", "attrs": {"tag": n.tag, "attrs": n.raw_attrs},
                "children": self.children(n)}

    def has_photo(self, n):
        for c in n.children:
            if c.tag in IMAGE:
                return True
            if c.tag not in FREEZE and self.has_photo(c):
                return True
        return False

    def walk(self, n):
        for c in n.children:
            yield c
            yield from self.walk(c)

    def children(self, n):
        s, out = self.src, []
        gaps = gap_texts(s, n)
        run_start, run = None, []

        def flush(end):
            nonlocal run_start, run
            if run_start is None:
                return None
            text = s[run_start:end]
            lead = len(text) - len(text.lstrip())
            body = text.strip()
            loose = text
            for el in run:
                loose = loose.replace(raw(s, el), "")
            if body and run and not loose.strip() and all(el.tag != "br" for el in run):
                # Only elements, no loose words: each element is its own block, so the
                # editor adds no wrapper between a grid or flex parent and its items.
                prev = run_start
                for el in run:
                    if has_words(s, el):
                        blk = {"name": "scout/text", "attrs": {"tag": el.tag, "attrs": el.raw_attrs, "content": words(inner(s, el))}}
                    else:
                        blk = self.freeze(raw(s, el))
                    blk["attrs"]["ws"] = ws(s[prev:el.start])
                    out.append(blk)
                    prev = el.end
            elif body:
                if re.sub(r"<[^>]*>|&nbsp;|&#160;|\s", "", body):
                    blk = {"name": "scout/text", "attrs": {"tag": "", "attrs": "", "content": words(body)}}
                else:
                    blk = self.freeze(body)
                blk["attrs"]["ws"] = ws(text[:lead])
                out.append(blk)
            run_start, run = None, []
            return text[len(text.rstrip()):] if body else text

        pos = n.open_end
        for i, c in enumerate(n.children):
            gap = gaps[i]
            if inline_pure(s, c) or re.sub(r"\s", "", gap):
                if run_start is None:
                    run_start = pos
            if inline_pure(s, c):
                run.append(c)
                pos = c.end
                continue
            trailing = flush(c.start)
            blk = self.node(c)
            blk["attrs"]["ws"] = ws(s[pos:c.start] if trailing is None else trailing)
            out.append(blk)
            pos = c.end
        tail = gaps[-1]
        if re.sub(r"\s", "", tail) and run_start is None:
            run_start = pos
        flush(n.close_start)
        return out


def esc_json(attrs):
    """JSON for a block comment, escaped the way WordPress's serializer does."""
    j = json.dumps(attrs, ensure_ascii=False, separators=(",", ":"))
    return (j.replace("--", "\\u002d\\u002d").replace("<", "\\u003c").replace(">", "\\u003e")
            .replace("&", "\\u0026").replace('\\"', "\\u0022"))


def serialize(block):
    attrs = {k: v for k, v in block["attrs"].items() if v != ""}
    a = (" " + esc_json(attrs)) if attrs else ""
    name = block["name"]
    if "children" in block:
        inside = "".join(serialize(c) for c in block["children"])
        return f"<!-- wp:{name}{a} -->\n{inside}<!-- /wp:{name} -->\n"
    return f"<!-- wp:{name}{a} /-->\n"


def convert(body, page_key, bodyclass):
    """Return (block markup, frozen fragments) for one page body."""
    tb = TreeBuilder(body)
    tb.feed(body)
    tb.close()
    root = tb.root
    root.open_end, root.close_start = 0, len(body)
    conv = Converter(body, page_key)
    kids = conv.children(root)
    page = {"name": "scout/box",
            "attrs": {"tag": "", "root": True, "bodyclass": bodyclass, "templateLock": "contentOnly",
                      "lock": {"move": True, "remove": True}},
            "children": kids}
    return serialize(page), conv.frozen


def parse(blocks_markup):
    """Parse our own block markup back into a tree."""
    pat = re.compile(r"<!-- (/?)wp:(scout/[a-z]+)(?: (\{.*?\}))? (/?)-->\n", re.S)
    root = {"children": []}
    stack = [root]
    for m in pat.finditer(blocks_markup):
        closing, name, attrs, selfc = m.groups()
        if closing:
            stack.pop()
            continue
        node = {"name": name, "attrs": json.loads(attrs) if attrs else {}, "children": []}
        stack[-1]["children"].append(node)
        if not selfc:
            stack.append(node)
    return root["children"]


def render(blocks_markup, frozen):
    """Python mirror of inc/blocks.php, for the build's own round-trip check."""
    def r(b):
        a = b["attrs"]
        name = b["name"]
        if name == "scout/box":
            inside = "".join(a2.get("ws", "") + r(c) for c in b["children"] for a2 in [c["attrs"]])
            if not a.get("tag"):
                return inside
            return f"<{a['tag']}{a.get('attrs', '')}>{inside}\n</{a['tag']}>"
        if name == "scout/text":
            if not a.get("tag"):
                return a.get("content", "")
            return f"<{a['tag']}{a.get('attrs', '')}>{a.get('content', '')}</{a['tag']}>"
        if name in ("scout/frozen", "scout/image"):
            return frozen[a["ref"]]
        if name == "scout/part":
            label = f' label="{a["label"]}"' if a.get("label") else ""
            return f'<scout-part name="{a["name"]}"{label}></scout-part>'
        raise ValueError(name)
    return "".join(r(b) for b in parse(blocks_markup))


def squash(html):
    """Whitespace-insensitive form for comparing a render with its source."""
    return re.sub(r"\s+", " ", re.sub(r">\s+<", "><", html)).strip()


# ---- Theme files ----

def write_php_data(path, data):
    """Theme data as a PHP file that prints nothing when opened in a browser."""
    body = json.dumps(data, ensure_ascii=False, indent=1)
    if "\nSCOUTJSON" in body:
        raise SystemExit(f"{path}: data contains the heredoc marker")
    with open(path, "w") as f:
        f.write("<?php\n/* Generated by Scout-Plugins tools/editable-pages/build_blocks.py. Do not edit here. */\n"
                "if ( ! defined( 'ABSPATH' ) ) {\n\texit;\n}\n"
                "return json_decode( <<<'SCOUTJSON'\n" + body + "\nSCOUTJSON\n, true );\n")


def read_php_data(path):
    import os
    if not os.path.exists(path):
        return {}
    text = open(path).read()
    m = re.search(r"<<<'SCOUTJSON'\n(.*)\nSCOUTJSON\n", text, re.S)
    return json.loads(m.group(1)) if m else {}


def build_page(body, key, path, content_dir, bodyclass=""):
    import os
    blocks, frozen = convert(body, key, bodyclass)
    if squash(render(blocks, frozen)) != squash(body):
        raise SystemExit(f"{key}: block content does not render back to the approved page")
    os.makedirs(content_dir, exist_ok=True)
    write_php_data(os.path.join(content_dir, f"{key}.php"), {"blocks": blocks, "frozen": frozen})
    index = read_php_data(os.path.join(content_dir, "index.php"))
    index[path] = key
    write_php_data(os.path.join(content_dir, "index.php"), index)


ROOT = ".editor-styles-wrapper .scout-root"


def split_top(selectors):
    parts, depth, cur = [], 0, ""
    for ch in selectors:
        depth += {"(": 1, ")": -1}.get(ch, 0)
        if ch == "," and depth == 0:
            parts.append(cur)
            cur = ""
        else:
            cur += ch
    return parts + [cur]


def scope_selector(sel, body_classes):
    sel = sel.strip()
    # body.quiet .x -> ROOT.quiet .x: body classes stay as conditions on the page
    # wrapper, which carries the page's body classes (body.review stays off).
    m = re.match(r"(body|:root|html)((?:\.[\w-]+)*)(?![\w-])", sel)
    if m:
        return [ROOT + m.group(2) + sel[m.end():]]
    if body_classes and sel.startswith(tuple(body_classes)):
        return [ROOT + sel, ROOT + " " + sel]
    return [ROOT + " " + sel]


def scope_css(css, body_classes=()):
    """Scope site stylesheets to the editor canvas. The .editor-styles-wrapper part
    lifts site rules above WordPress's classic-theme editor rules ((0,2,0))."""
    css = re.sub(r"/\*.*?\*/", "", css, flags=re.S)
    out, i = [], 0
    while i < len(css):
        j = css.find("{", i)
        if j < 0:
            break
        prelude = css[i:j].strip()
        depth, k = 1, j + 1
        while depth and k < len(css):
            depth += {"{": 1, "}": -1}.get(css[k], 0)
            k += 1
        block = css[j + 1:k - 1]
        if prelude.startswith(("@media", "@supports", "@layer", "@container")):
            out.append(prelude + "{" + scope_css(block, body_classes) + "}")
        elif prelude.startswith("@"):
            out.append(prelude + "{" + block + "}")
        else:
            out.append(",".join(x for part in split_top(prelude) for x in scope_selector(part, body_classes)) + "{" + block + "}")
        i = k
    return "\n".join(out)


EDITOR_EXTRA = """
/* Editor only. */
span.scout-frozen[style],span.scout-photo[style]{display:contents}
.scout-root{min-height:100%}
/* WordPress gives every block in a classic theme an 840px column and 28px margins;
   inside the page, hand those back to the browser so the site's own CSS decides. */
.editor-styles-wrapper .scout-root :where(.wp-block),.editor-styles-wrapper .scout-root:where(.wp-block){margin:revert;max-width:revert}
.editor-styles-wrapper:has(.scout-root) .is-root-container{padding:0}
/* The editor makes every block position: relative; layout blocks keep the site's value. */
.editor-styles-wrapper .scout-root :where(.wp-block-scout-box,.wp-block-scout-frozen,.wp-block-scout-image){position:revert}
/* WordPress makes editor images ignore clicks; photos here are editable blocks. */
.editor-styles-wrapper .scout-root img.wp-block-scout-image,.editor-styles-wrapper .scout-root .wp-block-scout-image img{pointer-events:auto;cursor:pointer}
/* Content hidden until scripted reveals run on the site shows in the editor. */
.editor-styles-wrapper .scout-root .reveal{opacity:1;transform:none}
.scout-part-note{display:grid;gap:.4rem;padding:1.25rem;border:1px dashed currentColor;border-radius:8px;font:400 .95rem/1.5 system-ui,sans-serif}
"""


def editor_css(sources, body_classes=()):
    return ("/* Generated by Scout-Plugins tools/editable-pages/build_blocks.py: the site styles scoped to the block editor. */\n"
            + scope_css("".join(open(p).read() for p in sources), body_classes) + EDITOR_EXTRA)


if __name__ == "__main__":
    import argparse
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    sub = ap.add_subparsers(dest="cmd", required=True)
    pg = sub.add_parser("page")
    pg.add_argument("body"); pg.add_argument("key"); pg.add_argument("path"); pg.add_argument("content_dir")
    pg.add_argument("--body-class", default="")
    ec = sub.add_parser("editor-css")
    ec.add_argument("out"); ec.add_argument("sources", nargs="+")
    ec.add_argument("--body-classes", default="")
    a = ap.parse_args()
    if a.cmd == "page":
        build_page(open(a.body).read(), a.key, a.path, a.content_dir, a.body_class)
        print(f"{a.key}: ok")
    else:
        classes = tuple(c for c in a.body_classes.split(",") if c)
        open(a.out, "w").write(editor_css(a.sources, classes))
        print(f"{a.out}: ok")
