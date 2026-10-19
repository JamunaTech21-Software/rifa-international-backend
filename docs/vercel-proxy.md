# Connecting the Vercel frontends to this backend

The public site (`rifa-international-customer-frontend`) and the admin app are hosted on Vercel and call
`/api/v1/...` on their own domain. Vercel forwards those requests to the PHP backend on cPanel.

Because every forwarded request reaches PHP from a Vercel address, the backend only trusts the visitor's real
IP (used for rate limits, audit logs and stored IPs) when Vercel also sends a **shared secret**
(see `src/Core/Middleware/TrustedProxy.php`). Requests without the secret use the connecting IP, so nobody can
fake their IP by calling the backend directly.

## 1. Backend (`.env` on cPanel)

```dotenv
TRUSTED_PROXY_SECRET=<long random string, e.g. `openssl rand -hex 32`>
APP_URL=https://rifaint.com            # public site
ADMIN_URL=https://admin.rifaint.com    # admin app (links in emails)
SESSION_SECURE=true
```

## 2. Each Vercel project (customer site and admin app)

**Environment variables** (Settings → Environment Variables; *not* prefixed with `VITE_`, so they never reach the browser):

| Name | Value |
|---|---|
| `BACKEND_ORIGIN` | `https://api.rifaint.com` (where the PHP backend is served) |
| `TRUSTED_PROXY_SECRET` | the same value as in the backend `.env` |

**`middleware.js`** in the project root (next to `package.json`), and `npm i @vercel/functions`:

```js
// Forwards /api/* to the PHP backend and tells it the visitor's real IP.
// The secret proves the request came through Vercel; browsers never see it.
import { ipAddress, rewrite } from '@vercel/functions'

export const config = { matcher: '/api/:path*' }

export default function middleware(request) {
  const url = new URL(request.url)
  const target = new URL(url.pathname + url.search, process.env.BACKEND_ORIGIN)

  const headers = new Headers(request.headers)
  headers.set('X-Rifa-Proxy-Secret', process.env.TRUSTED_PROXY_SECRET ?? '')
  headers.set('X-Rifa-Client-Ip', ipAddress(request) ?? '') // overwrites anything the browser sent

  return rewrite(target, { request: { headers } })
}
```

With this middleware in place, **remove** any `/api/:path*` rewrite from `vercel.json` (the catch-all
`/(.*) → /index.html` rewrite stays).

## 3. Check on staging (must be done once)

1. Submit the Contact form on the Vercel URL, then in the admin panel (or DB) confirm `contact_messages.ip_address`
   is your own IP, not a Vercel address.
2. Log in to the admin app → the session cookie works through the proxy (`/admin/auth/me` returns 200 after login).
3. Submit an application with **two 5 MB files**. Vercel may limit the size of forwarded request bodies; if this
   fails with 413 or a Vercel error page, tell the backend team — the fix is to post the Apply form directly to
   `BACKEND_ORIGIN` with CORS enabled for the site's domain.

The middleware API (`@vercel/functions` → `rewrite` with modified request headers) must be verified on the first
staging deploy; if Vercel rejects it, the fallback is the same flow using a small Vercel Function as the proxy.
