#!/usr/bin/env python3
# Converts README.MD → readme.html and USERGUIDE.MD → userguide.html.
# Run from the map/ directory: python3 make-docs-html.py

import markdown
import os

BASE = os.path.dirname(os.path.abspath(__file__))

def render_body(src_name):
    src = os.path.join(BASE, src_name)
    with open(src, encoding="utf-8") as f:
        md_text = f.read()
    return markdown.markdown(md_text, extensions=["tables", "fenced_code", "toc"])

def build(src_name, dst_name, title):
    dst = os.path.join(BASE, dst_name)
    body = render_body(src_name)

    html = f"""<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{title}</title>
<style>
  * {{ box-sizing: border-box; margin: 0; padding: 0; }}

  body {{
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
    font-size: 14px;
    line-height: 1.5;
    color: #222;
    background: #f5f5f5;
  }}

  header {{
    background: #444;
    color: #fff;
    padding: 14px 24px;
  }}

  header h1 {{
    font-size: 1.1rem;
    font-weight: 600;
    letter-spacing: 0.01em;
  }}

  #content {{
    max-width: 820px;
    margin: 20px auto 40px;
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 6px;
    padding: 24px 40px;
  }}

  #content h1 {{
    font-size: 1.7rem;
    font-weight: 700;
    margin-bottom: 6px;
    padding-bottom: 12px;
    border-bottom: 2px solid #ddd;
    margin-top: 24px;
  }}

  #content h1:first-child {{ margin-top: 0; }}

  #content h2 {{
    font-size: 1.2rem;
    font-weight: 700;
    margin-top: 28px;
    margin-bottom: 8px;
    padding-bottom: 5px;
    border-bottom: 1px solid #e0e0e0;
  }}

  #content h3 {{
    font-size: 1rem;
    font-weight: 700;
    margin-top: 18px;
    margin-bottom: 6px;
    color: #333;
  }}

  #content h4 {{
    font-size: 0.92rem;
    font-weight: 700;
    margin-top: 14px;
    margin-bottom: 5px;
    color: #444;
  }}

  #content p {{ margin-bottom: 8px; }}

  #content ul, #content ol {{
    margin: 4px 0 10px 22px;
  }}

  #content li {{ margin-bottom: 3px; }}

  #content li > p {{ margin-bottom: 3px; }}

  #content strong {{ font-weight: 700; }}

  #content em {{ font-style: italic; }}

  #content code {{
    font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
    font-size: 0.88em;
    background: #f0f0f0;
    border: 1px solid #ddd;
    border-radius: 3px;
    padding: 1px 5px;
  }}

  #content pre {{
    background: #f0f0f0;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 14px 16px;
    overflow-x: auto;
    margin: 10px 0 16px;
    font-size: 0.88em;
    line-height: 1.5;
  }}

  #content pre code {{
    background: none;
    border: none;
    padding: 0;
  }}

  #content table {{
    border-collapse: collapse;
    width: 100%;
    margin: 8px 0 12px;
    font-size: 0.9em;
  }}

  #content th {{
    background: #444;
    color: #fff;
    text-align: left;
    padding: 5px 10px;
    font-weight: 600;
  }}

  #content td {{
    padding: 4px 10px;
    border-bottom: 1px solid #e0e0e0;
    vertical-align: top;
  }}

  #content tr:nth-child(even) td {{ background: #fafafa; }}

  #content hr {{
    border: none;
    border-top: 1px solid #e0e0e0;
    margin: 16px 0;
  }}

  #content a {{ color: #0969da; text-decoration: none; }}
  #content a:hover {{ text-decoration: underline; }}

  @media (max-width: 680px) {{
    #content {{ padding: 24px 18px; margin: 16px 8px 40px; }}
    header {{ padding: 12px 16px; }}
  }}

  @media print {{
    header {{ display: none; }}
    body {{ background: #fff; font-size: 12px; }}
    #content {{
      margin: 0;
      border: none;
      border-radius: 0;
      padding: 0;
      max-width: 100%;
    }}
    pre {{
      break-inside: avoid;
      page-break-inside: avoid;
    }}
    h2, h3, h4 {{
      break-after: avoid;
      page-break-after: avoid;
    }}
  }}
</style>
</head>
<body>

<header>
  <div id="hdr-inner" style="display:flex;align-items:baseline;gap:20px">
    <h1>{title}</h1>
  </div>
</header>
<script>
(function(){{
  var b = new URLSearchParams(window.location.search).get('back');
  var a = document.createElement('a');
  a.href = b || '/';
  a.textContent = b ? '← Back' : '← Map';
  a.style.cssText = 'color:#bbb;font-size:0.85rem;font-weight:400;text-decoration:none;white-space:nowrap;flex-shrink:0';
  a.onmouseover = function(){{ this.style.color='#fff'; }};
  a.onmouseout  = function(){{ this.style.color='#bbb'; }};
  document.getElementById('hdr-inner').appendChild(a);
}})();
</script>

<div id="content">
{body}
</div>

</body>
</html>
"""

    with open(dst, "w", encoding="utf-8") as f:
        f.write(html)

    print(f"Written: {dst}")

DOCS = [
    ("README.MD",          "readme.html",          "MARS APRS — README"),
    ("USERGUIDE.MD",       "userguide.html",       "MARS APRS — User Guide"),
    ("TROUBLESHOOTING.MD", "troubleshooting.html", "MARS APRS — Troubleshooting"),
]

for args in DOCS:
    build(*args)

# Write the userguide body fragment for userguide.php
frag_dst = os.path.join(BASE, "userguide-body.html")
with open(frag_dst, "w", encoding="utf-8") as f:
    f.write(render_body("USERGUIDE.MD"))
print(f"Written: {frag_dst}")
