"""Exercise actual manager cookies, TV rendering and connector across HTTP requests."""
import html, http.cookiejar, json, re, struct, sys, urllib.request, urllib.parse, urllib.error, zlib
base = sys.argv[1].rstrip('/')
manager_path = sys.argv[3] if len(sys.argv) > 3 else '/manager/'
jar = http.cookiejar.CookieJar()
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
def response(req):
    try:
        return client.open(req).read()
    except urllib.error.HTTPError as error:
        raise AssertionError(str(error) + ': ' + error.read().decode()) from error
def get(path):
    return response(base + path).decode()
def post(path, fields):
    req = urllib.request.Request(base + path, urllib.parse.urlencode(fields).encode())
    return json.loads(response(req))
r = post('/connectors/index.php', dict(action='security/login', username='multipic-ci', password='multipic-ci-only-admin', login_context='mgr'))
assert r['success'], r
page = get(manager_path + '?a=resource/update&id=' + sys.argv[2])
matches = re.findall(r'data-multipic-config="([^"]+)"', page)
assert matches, 'Gallery missing from real manager page: ' + '\n'.join(line for line in page.splitlines() if any(word in line.lower() for word in ['multipic', 'error', 'exception']))[:4000]
cfg = json.loads(html.unescape(matches[0]))
assert cfg['maxPixels'] == 32000000, cfg
assert cfg['maxBytes'] == 20971520, cfg
assert 'uploadBytes' in cfg, cfg
r = post(cfg['connector'], dict(action='heartbeat', token=cfg['token'], HTTP_MODAUTH=cfg['auth']))
assert r['success'], r
print('PASS: real manager session survives TV render and separate connector request')
# multipart file upload, not a direct Service call
def chunk(kind, data):
    return struct.pack('>I', len(data)) + kind + data + struct.pack('>I', zlib.crc32(kind + data))
png = b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', 1, 1, 8, 2, 0, 0, 0)) + chunk(b'IDAT', zlib.compress(b'\x00\xff\x00\x00')) + chunk(b'IEND', b'')
boundary = 'MultiPicHttpFixtureBoundary'
parts = []
for key, value in dict(action='upload', token=cfg['token'], HTTP_MODAUTH=cfg['auth']).items():
    parts.append(('--' + boundary + '\r\nContent-Disposition: form-data; name="' + key + '"\r\n\r\n' + value + '\r\n').encode())
parts += [('--' + boundary + '\r\nContent-Disposition: form-data; name="file"; filename="http-test.png"\r\nContent-Type: image/png\r\n\r\n').encode(), png, ('\r\n--' + boundary + '--\r\n').encode()]
req = urllib.request.Request(base + cfg['connector'], b''.join(parts), {'Content-Type': 'multipart/form-data; boundary=' + boundary})
r = json.loads(response(req))
assert r['success'] and r['object']['file'], r
print('PASS: image uploads through authenticated HTTP connector')
item = r['object']
record = json.loads(re.search(r',record:\s*(\{[^\n]+\})', page).group(1))
fields = {key: (json.dumps(value) if isinstance(value, (dict, list)) else int(value) if isinstance(value, bool) else '' if value is None else value) for key, value in record.items()}
gallery = cfg['data']
gallery['items'] = [item]
fields.update(action='resource/update', HTTP_MODAUTH=cfg['auth'], tvs=1, multipic_tokens=json.dumps([cfg['token']]))
fields[cfg['field']] = json.dumps(gallery)
r = post('/connectors/index.php', fields)
assert r['success'], r
reloaded = get(manager_path + '?a=resource/update&id=' + sys.argv[2])
new_cfg = json.loads(html.unescape(re.findall(r'data-multipic-config="([^"]+)"', reloaded)[0]))
assert new_cfg['data']['items'][0]['file'] == item['file']
r = post(cfg['connector'], dict(action='heartbeat', token=cfg['token'], HTTP_MODAUTH=cfg['auth']))
assert r['success'], r
print('PASS: resource saves uploaded gallery through actual manager processor and reloads it')

closed = get('/multipic-ci-closed.php')
closed_cfg = json.loads(html.unescape(re.findall(r'data-multipic-config="([^"]+)"', closed)[0]))
r = post(closed_cfg['connector'], dict(action='heartbeat', token=closed_cfg['token'], HTTP_MODAUTH=closed_cfg['auth']))
assert r['success'], r
print('PASS: gallery capability persists when PHP session is closed before TV rendering')

# Reject a too-large pixel header before GD allocation and return the requested plain message.
large_png = b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', 9000, 4000, 8, 2, 0, 0, 0)) + chunk(b'IDAT', zlib.compress(b'\x00\xff\x00\x00')) + chunk(b'IEND', b'')
req = urllib.request.Request(base + cfg['connector'], b''.join(parts).replace(png, large_png), {'Content-Type': 'multipart/form-data; boundary=' + boundary})
try:
    client.open(req)
    raise AssertionError('Excessive pixel count accepted')
except urllib.error.HTTPError as error:
    result = json.loads(error.read())
    assert result['code'] == 'multipic_pixels', result
    assert result['message'] == 'Файл слишком большого размера. Выберите другой.', result
print('PASS: friendly oversize message and custom manager URL')
