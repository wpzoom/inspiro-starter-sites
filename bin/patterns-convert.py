#!/usr/bin/env python3
"""Turn the Inspiro Patterns export (raw.json, see export.php) into the proxy's pattern library:
one catalog/patterns/<id>.json per design, static block markup with photo tokens.
Usage: convert.py raw.json <proxy>/catalog/patterns"""
import json, re, sys, pathlib, collections

raw = json.load(open(sys.argv[1]))
out = pathlib.Path(sys.argv[2])
THUMB = 'https://wpzoom.s3.amazonaws.com/elementor/templates/assets/thumbs/inspiro-ai/previews/%s.png'
TOKEN = 'https://pattern-photo.invalid/%s/%d.jpg'
SKIP = {'cta-countdown-timer'}  # needs the plugin's own countdown block
PEOPLE = re.compile(r'team|testimonial|links-bio|director|visionaries|coach')
ASSET = re.compile(r'https?://(?:demo\.wpzoom\.com|(?:www\.)?wpzoom\.com|wpzoom\.s3[a-z0-9.-]*amazonaws\.com|images\.pexels\.com)/[^"\'\s)\\<]+?\.(?:png|jpe?g|webp|svg)(?:\?[^"\'\s)\\<]*)?', re.I)

report = collections.Counter()
for old in out.glob('*.json'):
    old.unlink()

for pid, p in raw.items():
    if pid in SKIP:
        continue
    m = p['markup']
    people = bool(PEOPLE.search(pid)) or bool({'team', 'testimonials'} & set(p['category']))

    # The demo content put a photo URL where an email address belongs.
    m = re.sub(r'(✉️\s*)(?:<a[^>]*>)?https://images\.pexels\.com/[^<\s]+(?:</a>)?', r'\1hello@example.com', m)

    # An image block without an id must not carry a wp-image-N class: the editor calls it invalid.
    def fix_image(x):
        block = x.group(0)
        if '"id":' in x.group(1):
            return block
        return re.sub(r'\s+class="wp-image(?:-\d+)?"', '', block)
    m = re.sub(r'<!-- wp:image(.*?)-->.*?<!-- /wp:image -->', fix_image, m, flags=re.S)

    # Photos: the slots the export filled, random-avatar services and unfilled image placeholders.
    urls = []
    def slot(url):
        if url not in urls:
            urls.append(url)
        return '\x00%d\x00' % urls.index(url)
    m = re.sub(r'https://pattern-photo\.invalid/slot-\d+\.jpg', lambda x: slot(x.group(0)), m)
    m = re.sub(r'https://picsum\.photos/[^"\'\s)\\<]+', lambda x: slot(x.group(0)), m)
    m = re.sub(r'(src=")\{\{[a-z0-9_]+\}\}', lambda x: x.group(1) + slot(x.group(0)), m)
    m = re.sub(r'\{\{[a-z0-9_]+\}\}', '', m)

    def orientation(i):
        if people or 'picsum' in urls[i]:
            return 'square'
        ctx = m[max(0, m.find('\x00%d\x00' % i) - 400): m.find('\x00%d\x00' % i) + 200]
        if re.search(r'aspect-ratio:\s*(?:3/4|2/3|9/16|4/5)|"aspectRatio":"(?:3/4|2/3|9/16|4/5)"', ctx):
            return 'portrait'
        return 'landscape'
    for i in range(len(urls)):
        m = m.replace('\x00%d\x00' % i, TOKEN % (orientation(i), i + 1))

    assets = sorted(set(ASSET.findall(m)))
    blocks = set(re.findall(r'<!-- wp:([a-z0-9-]+/[a-z0-9-]+|[a-z0-9-]+)', m))
    needs = []
    if 'wpzoom-forms/form-block' in blocks: needs.append('wpzoom-forms')
    if 'wpzoom-video-popup-block/block' in blocks: needs.append('video-popup')
    if 'query' in blocks or 'core/query' in blocks: needs.append('posts')

    entry = {
        'id': pid,
        'title': p['title'],
        'categories': [c for c in p['category'] if c != 'countdown'],
        'tone': 'dark' if p['background'] == 'dark' else 'light',
        'tier': 'premium',
        'needs': needs,
        'photos': len(urls),
        'assets': assets,
        'thumb': THUMB % pid,
        'source': 'inspiro-patterns 1.1.0',
        'markup': m.strip(),
    }
    (out / (pid + '.json')).write_text(json.dumps(entry, ensure_ascii=False, indent=1) + '\n')
    report['patterns'] += 1; report['photos'] += len(urls); report['assets'] += len(assets)
    for n in needs: report['needs:' + n] += 1
    if 'outermost/icon-block' in blocks: report['icon-block'] += 1
    if '{{' in m: report['LEFTOVER ' + pid] += 1
print(dict(report))
