"""Integration with the actual OpenCart ZIP installer, authenticated admin and storefront."""
from pathlib import Path
from urllib.request import build_opener, HTTPCookieProcessor, Request
from urllib.parse import urlencode, urlsplit, parse_qs, urljoin
from urllib.error import HTTPError
from http.cookiejar import CookieJar
import html
import json
import re
import subprocess
import sys

BASE = 'http://127.0.0.1:8080/'
PACKAGE = 'yuei_spanish_pe'
STORE = sys.argv[1]
client = build_opener(HTTPCookieProcessor(CookieJar()))

def request(url, data=None, content_type=None):
    if isinstance(data, dict):
        data = urlencode(data).encode()
    headers = {'User-Agent': 'Spanish-Language-CI'}
    if content_type:
        headers['Content-Type'] = content_type
    try:
        response = client.open(Request(urljoin(BASE, url), data, headers), timeout=30)
    except HTTPError as error:
        response = error
    with response:
        return response.status, response.read().decode('utf-8', errors='replace'), response.headers

def api(url, data=None, content_type=None):
    status, body, _ = request(url, data, content_type)
    assert status == 200, (urlsplit(url).path, status, body[:1200])
    try:
        return json.loads(body)
    except json.JSONDecodeError as error:
        summary = re.sub(r'\s+', ' ', re.sub('<[^>]+>', ' ', body)).strip()
        raise AssertionError(f'Expected JSON, received {summary[:1200]!r}') from error

def fixture(command='status'):
    return json.loads(subprocess.check_output(['php', 'tests/fixture.php', STORE, command], text=True))

status, login, _ = request('admin/index.php?route=common/login')
login_token = re.search(r'login_token=([a-zA-Z0-9]+)', login)
assert login_token, login[:1000]
redirect = api('admin/index.php?route=common/login.login&login_token=' + login_token[1],
               {'username': 'admin', 'password': 'LanguageTest12345'})['redirect']
token = parse_qs(urlsplit(redirect).query)['user_token'][0]
auth = '&user_token=' + token

# Exercise OpenCart's multipart ZIP upload and every extraction page, rather than copying files by hand.
boundary = 'SpanishPeruTestBoundary2026'
archive = Path('dist/yuei_spanish_pe.ocmod.zip').read_bytes()
body = (f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="yuei_spanish_pe.ocmod.zip"\r\nContent-Type: application/zip\r\n\r\n'.encode()
        + archive + f'\r\n--{boundary}--\r\n'.encode())
upload = api('admin/index.php?route=marketplace/installer.upload' + auth, body, 'multipart/form-data; boundary=' + boundary)
assert 'success' in upload, upload
package_id = fixture()['package_id']
assert package_id > 0
next_url = 'admin/index.php?route=marketplace/installer.install' + auth + '&extension_install_id=' + str(package_id)
for attempt in range(100):
    result = api(next_url)
    assert 'error' not in result, result
    next_url = result.get('next')
    if not next_url:
        break
else:
    raise AssertionError('Installer did not finish')

# Real MariaDB rollback and two independent PHP processes installing the first language simultaneously.
subprocess.run(['php', 'tests/concurrency.php', STORE], check=True)
install_url = 'admin/index.php?route=extension/language.install&extension=' + PACKAGE + '&code=' + PACKAGE + auth
assert 'success' in api(install_url)
state = fixture()
language_id = state['language']['language_id']
assert state['language']['extension'] == PACKAGE and state['products'] > 0 and state['registrations'] == 1
assert state['guards'] == 2

page = 'admin/index.php?route=extension/' + PACKAGE + '/language/' + PACKAGE + auth
status, english, _ = request(page)
assert status == 200 and 'Language pack for OpenCart 4.1.0.4' in english
api('admin/index.php?route=common/language.save' + auth, {'code': 'es-pe', 'redirect': BASE + page})
status, spanish, headers = request(page)
assert status == 200 and 'Administrar idiomas' in spanish and 'Paquete de idioma' in spanish
assert headers.get('X-Content-Type-Options') == 'nosniff'
assert '<html dir="ltr" lang="es-pe">' in spanish
status, storefront, _ = request('index.php?route=common/home&language=es-pe')
assert status == 200 and 'Mi cuenta' in storefront and 'Destacados' in storefront
assert '<html dir="ltr" lang="es-pe">' in storefront
status, product, _ = request('index.php?route=product/product&language=es-pe&product_id=40')
assert status == 200 and 'iPhone' in product and 'Agregar al carrito' in product
status, _, _ = request('extension/' + PACKAGE + '/catalog/language/es-pe/es-pe.png')
assert status == 200

# A second install preserves merchant edits and the original language ID.
fixture('edit')
assert 'success' in api(install_url)
state = fixture()
assert state['language']['language_id'] == language_id and state['product_name'] == 'Producto de prueba conservado'
assert state['registrations'] == 1

# Guard all stores before the native controller can unregister the extension.
uninstall_url = 'admin/index.php?route=extension/language.uninstall&extension=' + PACKAGE + '&code=' + PACKAGE + auth
api('admin/index.php?route=common/language.save' + auth, {'code': 'en-gb', 'redirect': BASE + page})
fixture('default')
result = api(uninstall_url)
assert 'all stores' in result.get('error', ''), result
assert fixture()['registrations'] == 1
fixture('clear-default')
result = api(uninstall_url)
assert 'success' in result, result
state = fixture()
assert state['language']['status'] == 0 and state['products'] > 0 and state['registrations'] == 0
remove_package = 'admin/index.php?route=marketplace/installer.uninstall&extension_install_id=' + str(package_id) + auth
assert 'error' in api(remove_package)
assert fixture()['guards'] == 2
assert 'success' in api(install_url)
assert fixture()['language']['language_id'] == language_id

# Removing the package is allowed only after an explicit language-record removal.
assert 'success' in api(uninstall_url)
fixture('remove-record')
result = api(remove_package)
assert 'success' in result, result
assert fixture()['guards'] == 0
assert not Path(STORE, 'extension', PACKAGE).exists()

print('Native ZIP installer, language activation, admin/storefront/default extensions, content preservation, reinstall and uninstall guards: PASS')
