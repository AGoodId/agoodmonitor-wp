"""Real HTTP smoke tests, pinned to a disposable localhost install.

Fixture: http-fixture.php as MU plugin. Config: enabled + WooCommerce.
Accounts: agm_turnstile_test (plain), agm_2fa_test (Two Factor TOTP + backup).
AGOODMONITOR_TEST_CODES points to JSON {"totp_secret": ..., "backup": ...}.
No real mail or Cloudflare traffic. Never point this at a customer site.
"""
import base64
import hashlib
import hmac
import http.cookiejar
import json
import os
import re
import struct
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

BASE = 'http://127.0.0.1:8899'
checks = 0


def check(ok, label):
    global checks
    assert ok, label
    checks += 1


def request(path, data=None, jar=None):
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar if jar is not None else http.cookiejar.CookieJar()))
    try:
        response = opener.open(BASE + path, None if data is None else urllib.parse.urlencode(data).encode(), timeout=15)
    except urllib.error.HTTPError as error:
        response = error
    return response.status, response.read().decode(), response.url


def token(action):
    return action + ':' + str(uuid.uuid4())


def login(user='agm_turnstile_test', captcha=None, jar=None):
    data = {'log': user, 'pwd': 'test-only-disposable-password'}
    if captcha:
        data['cf-turnstile-response'] = captcha
    return request('/wp-login.php', data, jar)


check(login()[0] == 403, 'native direct POST without token blocked')
check(login(captcha='invalid')[0] == 403, 'native invalid token blocked')
valid = token('agm_login')
jar = http.cookiejar.CookieJar()
login(captcha=valid, jar=jar)
check(any(c.name.startswith('wordpress_logged_in_') for c in jar), 'valid native login creates real session')
check(login(captcha=valid)[0] == 403, 'token replay across HTTP requests blocked')
check(request('/wp-login.php?action=lostpassword', {'user_login': 'agm_turnstile_test'})[0] == 403, 'reset direct POST blocked')
status, body, url = request('/wp-login.php?action=lostpassword', {'user_login': 'agm_turnstile_test', 'cf-turnstile-response': token('agm_lostpassword')})
check('checkemail=confirm' in url, 'valid native reset reaches confirmation (mail intercepted)')
wc_data = {'login': '1', 'username': 'agm_turnstile_test', 'password': 'test-only-disposable-password'}
check(request('/', wc_data)[0] == 403, 'WC handler direct POST blocked')
check(request('/?wc-ajax=anything', wc_data)[0] == 403, 'WC AJAX flag cannot bypass native handler')
check(request('/', {'wc_reset_password': '1', 'user_login': 'agm_turnstile_test'})[0] == 403, 'WC retrieval direct POST blocked')

codes_path = os.environ['AGOODMONITOR_TEST_CODES']
with open(codes_path) as codes_file:
    codes = json.load(codes_file)

account_path = codes['account_path']
_, wc_form, _ = request(account_path)
wc_nonce = re.search(r'name="woocommerce-login-nonce"[^>]*value="([^"]+)"', wc_form).group(1)
wc_jar = http.cookiejar.CookieJar()
request(account_path, {**wc_data, 'woocommerce-login-nonce': wc_nonce, 'cf-turnstile-response': token('agm_wc_login')}, wc_jar)
check(any(c.name.startswith('wordpress_logged_in_') for c in wc_jar), 'real WC form handler logs test user in')
lost_path = account_path + '&lost-password'
_, lost_form, _ = request(lost_path)
lost_nonce = re.search(r'name="woocommerce-lost-password-nonce"[^>]*value="([^"]+)"', lost_form).group(1)
_, _, lost_url = request(lost_path, {'wc_reset_password': '1', 'user_login': 'agm_turnstile_test', 'woocommerce-lost-password-nonce': lost_nonce, 'cf-turnstile-response': token('agm_wc_lostpassword')})
check('reset-link-sent=true' in lost_url, 'real WC reset handler reaches confirmation with intercepted mail')


def two_factor(provider, code_field, code):
    jar = http.cookiejar.CookieJar()
    status, body, url = login('agm_2fa_test', token('agm_login'), jar)
    check('wp-auth-nonce' in body and not any(c.name.startswith('wordpress_logged_in_') for c in jar), 'password step requires 2FA')
    fields = dict(re.findall(r'<input[^>]*name="([^"]+)"[^>]*value="([^"]*)"', body))
    fields.update({'provider': provider, code_field: code, 'action': 'validate_2fa'})
    # No Turnstile token: this step uses Two Factor's signed challenge nonce.
    request('/wp-login.php?action=validate_2fa', fields, jar)
    check(any(c.name.startswith('wordpress_logged_in_') for c in jar), '2FA completes real login: ' + provider)


key = base64.b32decode(codes['totp_secret'])
digest = hmac.new(key, struct.pack('>Q', int(time.time()) // 30), hashlib.sha1).digest()
offset = digest[-1] & 15
totp = str((struct.unpack('>I', digest[offset:offset+4])[0] & 0x7fffffff) % 1000000).zfill(6)
two_factor('Two_Factor_Totp', 'authcode', totp)
two_factor('Two_Factor_Backup_Codes', 'two-factor-backup-code', codes['backup'])
print(f'PASS: {checks} real HTTP assertions, including TOTP and backup-code logins; mail intercepted.')
