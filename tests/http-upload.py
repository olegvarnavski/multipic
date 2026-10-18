"""Exercise actual manager cookies, TV rendering and connector across HTTP requests."""
import base64, html, http.cookiejar, json, re, sys, urllib.request, urllib.parse
base = sys.argv[1].rstrip('/')
jar = http.cookiejar.CookieJar()
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
def get(path):
    return client.open(base + path).read().decode()
def post(path, fields):
    req = urllib.request.Request(base + path, urllib.parse.urlencode(fields).encode())
    return json.loads(client.open(req).read())
r = post('/connectors/index.php', dict(action='security/login', username='multipic-ci', password='multipic-ci-only-admin', login_context='mgr'))
assert r['success'], r
page = get('/manager/?a=resource/update&id=' + sys.argv[2])
matches = re.findall(r'data-multipic-config="([^"]+)"', page)
assert matches, 'Gallery missing from real manager page: ' + '\n'.join(line for line in page.splitlines() if any(word in line.lower() for word in ['multipic', 'error', 'exception']))[:4000]
cfg = json.loads(html.unescape(matches[0]))
r = post(cfg['connector'], dict(action='heartbeat', token=cfg['token'], HTTP_MODAUTH=cfg['auth']))
assert r['success'], r
print('PASS: real manager session survives TV render and separate connector request')
# multipart file upload, not a direct Service call
png = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aN1sAAAAASUVORK5CYII=')
boundary = 'MultiPicHttpFixtureBoundary'
parts = []
for key, value in dict(action='upload', token=cfg['token'], HTTP_MODAUTH=cfg['auth']).items():
    parts.append(('--' + boundary + '\r\nContent-Disposition: form-data; name="' + key + '"\r\n\r\n' + value + '\r\n').encode())
parts += [('--' + boundary + '\r\nContent-Disposition: form-data; name="file"; filename="http-test.png"\r\nContent-Type: image/png\r\n\r\n').encode(), png, ('\r\n--' + boundary + '--\r\n').encode()]
req = urllib.request.Request(base + cfg['connector'], b''.join(parts), {'Content-Type': 'multipart/form-data; boundary=' + boundary})
r = json.loads(client.open(req).read())
assert r['success'] and r['object']['file'], r
print('PASS: image uploads through authenticated HTTP connector')
