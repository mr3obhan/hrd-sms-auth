# HRD SMS Auth

Login & registration for WordPress/WooCommerce using an Iranian mobile number and an SMS one-time-code (OTP) delivered via [sms.ir](https://sms.ir).

## Usage

- **Shortcode:** place `[hrd_sms_login]` on any page (renders an inline centered card).
- **Dedicated login page:** the plugin serves a **fullscreen** login page at the
  configured slug (default `/hrd-login`). It renders its own minimal document —
  no theme header/footer/sidebar — showing only the form plus a customizable
  "back to homepage" control.
- Optionally replace the default `wp-login.php` and WooCommerce login pages from settings.

## Admin settings (tabbed)

*Settings → ورود با پیامک*: **General · SMS Provider · OTP & Security · Redirects ·
WooCommerce · Design / Appearance · Tools**. The Design tab themes the login form
(title, subtitle, logo, background type/colors/image, card width/radius/shadow,
all brand colors, back-button text/url/position, and an advanced custom-CSS field).
All design values are sanitized on save and injected as scoped, escaped inline CSS.

## Architecture

```
hrd-sms-auth.php          Bootstrap: constants, includes, activation/deactivation
uninstall.php             Data cleanup — runs ONLY on real uninstall
inc/
  Config.php              Centralised defaults & validated option access
  Helper.php              Phone/IP normalisation utilities
  Services/
    RateLimiter.php       Soft (configurable) + hard (always-on) brute-force limits
    SmsIrGateway.php      sms.ir client; parses the provider response body
    OtpService.php        Single source of truth: send + verify + login/register
  Users.php               User lookup / creation
  RestAPI_Auth.php        The one public API surface (REST)
  Ajax.php                Admin-only tools (test SMS, WooCommerce sync)
  Front.php               Shortcode + conditional asset loading
  LoginControl.php        Rewrite rule, login takeover, SAFE redirects
  admin/Admin.php         Settings screen + user profile field
  templates/login-form.php
assets/
  css/style.css           Login widget styles
  js/login.js             Vanilla-JS widget (no framework, no build step)
  admin/admin.js          Admin settings helpers
```

## API

Both endpoints require a valid `wp_rest` nonce in the `X-WP-Nonce` header.

| Method | Endpoint | Body |
|--------|----------|------|
| POST | `/wp-json/hrd-sms-auth/v1/send-code` | `{ mobile }` |
| POST | `/wp-json/hrd-sms-auth/v1/verify-code` | `{ mobile, code, first_name?, last_name? }` |

`send-code` never reveals whether an account exists. A new user is only asked for
their name **after** submitting a correct code (`verify-code` replies with
`needs_registration: true`).

## Security model

- Only a mobile-number username or an OTP-verified `hrd_phone` is accepted as a
  login identity. WooCommerce billing/shipping phones are contact data only.
- OTP values are stored as keyed hashes; legacy plaintext transients are accepted
  only until their already-existing expiry time.
- Brute-force limits apply per mobile/IP pair, per mobile across all IPs, and per
  IP across all mobiles. Provider failures also consume the send allowance.
- A mobile number and national ID cannot be assigned to a second account through
  the plugin flows.

## Build

There is **no build step** — the frontend is plain ES5-compatible JavaScript.
`node_modules`, IDE folders and packaged `.zip` files are git-ignored.
