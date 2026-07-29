# HZ Flix — Cloudflare Security Configuration Guide

## Overview
This guide covers the recommended Cloudflare settings to protect HZ Flix from DDoS, bots, scraping, and API abuse.

---

## 1. DNS & SSL/TLS

| Setting | Value |
|---------|-------|
| SSL/TLS Mode | **Full (Strict)** |
| Always Use HTTPS | ✅ Enabled |
| HSTS | ✅ Enabled (max-age=31536000, includeSubDomains, preload) |
| Min TLS Version | TLS 1.2 |
| Opportunistic Encryption | ✅ Enabled |
| TLS 1.3 | ✅ Enabled |

---

## 2. WAF (Web Application Firewall)

### Enable OWASP Core Ruleset
- Go to **Security → WAF → Managed Rules**
- Enable **Cloudflare Managed Ruleset** (Sensitivity: Medium)
- Enable **OWASP Core Ruleset** (Sensitivity: Low to start)

### Custom WAF Rules

#### Rule 1: Block API access from suspicious countries (optional)
```
(http.request.uri.path matches "^/api/" and not ip.geoip.country in {"SA" "AE" "EG" "US" "GB" "DE" "FR" "JP" "KW" "QA" "BH" "OM" "JO" "LB"})
```
Action: **Block**

#### Rule 2: Block direct access to sensitive paths
```
(http.request.uri.path matches "^/(data|includes|\.git|\.env|config\.php)" or 
 http.request.uri.path matches "\.(zip|tar|gz|sql|bak|env|log)$")
```
Action: **Block**

#### Rule 3: Block empty or suspicious User-Agents on API
```
(http.request.uri.path matches "^/api/" and 
 (http.user_agent eq "" or 
  http.user_agent matches "(curl|wget|python-requests|scrapy|masscan|zgrab)"))
```
Action: **Managed Challenge**

#### Rule 4: Protect login/register from brute force
```
(http.request.uri.path in {"/login" "/register"} and http.request.method eq "POST")
```
Action: **Managed Challenge** (Rate Limit: 5 requests per minute per IP)

---

## 3. Rate Limiting

### Stream API Rate Limit
- **Expression**: `http.request.uri.path matches "^/api/.*/index\.php"`
- **Requests**: 30 per minute per IP
- **Action**: Block for 10 minutes
- **Count on**: Requests with status code 200

### Search Rate Limit
- **Expression**: `http.request.uri.path eq "/api/search"`
- **Requests**: 60 per minute per IP
- **Action**: Managed Challenge

### Auth Rate Limit
- **Expression**: `http.request.uri.path in {"/login" "/register"} and http.request.method eq "POST"`
- **Requests**: 10 per 10 minutes per IP
- **Action**: Block for 30 minutes

---

## 4. Bot Protection

### Bot Fight Mode
- Go to **Security → Bots**
- Enable **Bot Fight Mode** ✅
- Enable **Super Bot Fight Mode** (if on Pro plan or above)

### Super Bot Fight Mode Settings
| Bot Type | Action |
|----------|--------|
| Definitely Automated | Block |
| Likely Automated | Managed Challenge |
| Verified Bots | Allow (Google, Bing, etc.) |

---

## 5. DDoS Protection

- **HTTP DDoS Attack Protection**: Enabled (Sensitivity: Medium)
- **Network-layer DDoS Attack Protection**: Enabled
- Under **Security → DDoS**, set override:
  - Sensitivity: **High** for `/api/*` paths
  - Action: **Block**

---

## 6. Cloudflare Turnstile (for Sensitive Actions)

Use Cloudflare Turnstile on:
1. **Login page** — embed widget in form
2. **Register page** — embed widget in form
3. **API endpoints** — add validation for `/api/search` if spam is detected

### Setup Steps
1. Go to **Cloudflare Dashboard → Turnstile**
2. Add a site → get `sitekey` and `secret`
3. Add to environment variables:
   ```
   TURNSTILE_SITE_KEY=your_site_key
   TURNSTILE_SECRET=your_secret_key
   ```
4. Embed widget in HTML forms
5. Validate `cf-turnstile-response` on backend with:
   ```
   POST https://challenges.cloudflare.com/turnstile/v0/siteverify
   ```

---

## 7. Security Headers (via Cloudflare Transform Rules)

Create a **Response Header Transform Rule** to add:

| Header | Value |
|--------|-------|
| X-Content-Type-Options | nosniff |
| X-Frame-Options | SAMEORIGIN |
| Referrer-Policy | strict-origin-when-cross-origin |
| Permissions-Policy | camera=(), microphone=(), geolocation=() |

Note: `Content-Security-Policy` and `Strict-Transport-Security` are already set by the PHP backend.

---

## 8. Caching Configuration

### Cache Rules
- **Static assets** (`/assets/*`, `/uploads/*`): Cache for 30 days
- **API endpoints** (`/api/*`): **Bypass Cache** (always dynamic)
- **Stream pages** (`/watch/*`): Cache for 0 seconds (no-cache)
- **Details/home pages**: Cache for 5 minutes (Edge TTL)

### Browser Cache TTL
- Static assets: 1 year
- HTML pages: No cache

---

## 9. Page Rules / Cache Rules for Watch Page

```
URL: */watch/*
Settings:
  - Cache Level: Bypass
  - Browser Cache TTL: Respect existing headers
  - Security Level: High
```

---

## 10. Firewall Analytics

Monitor these events regularly:
- **Blocked requests** spike → possible DDoS in progress
- **Challenge passes** → tune bot detection thresholds
- **WAF triggered rules** → review if legitimate users are blocked

### Recommended Alerts
- DDoS attack detected → Email + PagerDuty
- Origin error rate > 5% → Email alert
- Traffic spike > 10x normal → Email alert

---

## 11. IP Geoblocking (Optional)

If you see abuse from specific regions:
```
(ip.geoip.country eq "XX") → Block
```

**Important**: Use `CF-Connecting-IP` header in PHP (already implemented in `get_client_ip()`) — the PHP app correctly reads the real visitor IP behind Cloudflare.

---

## 12. Orange-Cloud All DNS Records

Make sure all DNS A/AAAA records are **proxied** (orange cloud) in Cloudflare DNS settings. This hides your origin IP from attackers.

If your origin IP is exposed, DDoS attacks can bypass Cloudflare entirely.

---

## Implementation Notes for PHP App

The PHP backend (`includes/security.php`) already handles:
- ✅ `CF-Connecting-IP` header for real IP detection
- ✅ Rate limiting (file-based, no Redis needed)
- ✅ Security headers (CSP, HSTS, X-Frame-Options)
- ✅ Stream token validation (HMAC-SHA256)
- ✅ Nonce-based replay attack prevention
- ✅ Session fingerprint binding on tokens
- ✅ Bot detection and scoring
- ✅ Progressive blocking with exponential backoff
- ✅ Security event logging

Cloudflare adds an extra layer on top — not a replacement for backend security.
