# RIFA International API — v1

Contract between the React frontend and the PHP backend. Source of truth for field names,
values and messages. (Mirrors `plan.md` §4 and §7.)

- Base URL: `/api/v1` (same origin as the site; locally Vite proxies `/api` → `http://localhost:8000`)
- Body: JSON (`Content-Type: application/json`) except `POST /applications`, which is `multipart/form-data`
- Times: ISO-8601 with Dhaka offset, e.g. `2026-10-15T10:32:00+06:00`
- Every response has an `X-Request-Id` header — include it when reporting a bug.

## Response format

Success:

```json
{ "data": { ... } }
```

Lists add `meta`:

```json
{ "data": [ ... ], "meta": { "page": 1, "per_page": 20, "total": 57 } }
```

Error:

```json
{
  "error": {
    "code": "validation_failed",
    "message": "Please check the highlighted fields.",
    "fields": { "mobile": "Enter a valid Bangladeshi mobile number (01XXXXXXXXX)." }
  }
}
```

| HTTP | `code` | When | Frontend should |
|---|---|---|---|
| 400 | `invalid_json` / `bad_request` | malformed body | show generic error |
| 401 | `unauthenticated` | admin not logged in / session expired | redirect to `/admin/login` |
| 401 | `invalid_credentials` | login with wrong email/password | show "Invalid email or password." |
| 403 | `forbidden` | admin role not allowed | show "no permission" |
| 404 | `not_found` | unknown endpoint or record | show not found |
| 405 | `method_not_allowed` | wrong HTTP method | (developer error) |
| 419 | `csrf_mismatch` | admin write without valid `X-CSRF-Token` | re-fetch `/admin/auth/me`, retry once |
| 422 | `validation_failed` | invalid input — `fields` has one message per field | show each message under its field |
| 429 | `rate_limited` | too many submissions (`Retry-After` header, seconds) | "Too many attempts. Please try again later." |
| 500 | `server_error` | unexpected error (`request_id` included) | "Something went wrong. Please try again or call us." |

---

## Public endpoints

### GET /health

```json
{ "data": { "status": "ok", "database": "ok", "time": "2026-10-19T10:24:53+06:00" } }
```

`503` with `"status": "degraded"` when the database is unreachable.

### GET /lookups

Options for the Apply and Contact form dropdowns. Cached for 1 hour. If this call fails, the frontend
falls back to the same lists hardcoded in `src/data`.

```json
{
  "data": {
    "job_categories": [
      { "slug": "construction", "name": "Construction" },
      { "slug": "factory-manufacturing", "name": "Factory & Manufacturing" },
      { "slug": "plantation-agriculture", "name": "Plantation & Agriculture" },
      { "slug": "hospitality", "name": "Hospitality" },
      { "slug": "cleaning-service", "name": "Cleaning & Service" },
      { "slug": "logistics-warehouse", "name": "Logistics & Warehouse" }
    ],
    "genders": [
      { "value": "male", "label": "Male" },
      { "value": "female", "label": "Female" }
    ],
    "work_experience": [
      { "value": "none", "label": "No experience" },
      { "value": "lt_1", "label": "Less than 1 year" },
      { "value": "1_2", "label": "1–2 years" },
      { "value": "3_5", "label": "3–5 years" },
      { "value": "gt_5", "label": "More than 5 years" }
    ],
    "education_levels": [
      { "value": "none", "label": "No formal education" },
      { "value": "psc", "label": "PSC" },
      { "value": "jsc", "label": "JSC/JDC" },
      { "value": "ssc", "label": "SSC/Dakhil" },
      { "value": "hsc", "label": "HSC/Alim" },
      { "value": "diploma", "label": "Diploma" },
      { "value": "bachelor", "label": "Bachelor" },
      { "value": "masters", "label": "Masters or above" }
    ],
    "passport_statuses": [
      { "value": "have_passport", "label": "Have Passport" },
      { "value": "will_apply", "label": "Will Apply Soon" }
    ],
    "contact_subjects": [
      { "value": "general_inquiry", "label": "General Inquiry" },
      { "value": "job_application", "label": "Job Application" },
      { "value": "employer_partnership", "label": "Employer Partnership" },
      { "value": "support", "label": "Support" }
    ]
  }
}
```

### POST /applications

`multipart/form-data`. Rate limit: 5 successful submissions per IP per hour (`429` after that).

| Field | Required | Rule | Error message (shown under the field) |
|---|---|---|---|
| `full_name` | ✅ | 2–100 chars; letters, spaces, `.` `-` `'` | "Please enter your full name." / "Full name must be 2–100 letters." |
| `father_name` | ✅ | same | "Please enter your father's name." / "Father's name must be 2–100 letters." |
| `date_of_birth` | ✅ | `YYYY-MM-DD`, past, age ≥ 18 | "Please enter your date of birth." / "Enter a valid date (YYYY-MM-DD)." / "Date of birth must be in the past." / "You must be at least 18 years old." |
| `gender` | ✅ | `male` \| `female` | "Please select your gender." |
| `nid_no` | — | digits only, 10, 13 or 17 long | "NID number must be 10, 13 or 17 digits." |
| `mobile` | ✅ | `01[3-9]XXXXXXXX` (11 digits). `+8801…`/`8801…` also accepted | "Please enter your mobile number." / "Enter a valid Bangladeshi mobile number (01XXXXXXXXX)." |
| `email` | — | valid email, ≤ 150 | "Enter a valid email address." |
| `present_address` | ✅ | 5–255 chars | "Please enter your present address." / "Present address must be 5–255 characters." |
| `job_category` | ✅ | a `job_categories[].slug` | "Please select a job category." / "Please select a valid job category." |
| `work_experience` | — | a `work_experience[].value` | "Please select a valid option." |
| `education_level` | — | an `education_levels[].value` | "Please select a valid option." |
| `passport_status` | ✅ | `have_passport` \| `will_apply` | "Please select your passport status." |
| `cv` | — | file: PDF/JPG/PNG, ≤ 5 MB | "CV must be a PDF, JPG or PNG file." / "CV must be 5 MB or smaller." |
| `passport_copy` | ✅ if `passport_status=have_passport` | file: PDF/JPG/PNG, ≤ 5 MB | "Please upload your passport copy." / "Passport copy must be a PDF, JPG or PNG file." / "Passport copy must be 5 MB or smaller." |
| `declaration` | ✅ | `1` / `true` | "Please confirm that the information is true and correct." |
| `website` | — | honeypot: hidden input, must stay empty | — |
| `captcha_token` | ✅ when captcha is on | Cloudflare Turnstile token (see *Captcha* below) | "Please complete the security check and try again." |

Notes:
- Empty optional fields may be omitted or sent as `""`.
- Applicants over 45 are accepted (flagged for the admin). Applicants under 18 get the `date_of_birth` error.
- File type is checked from the file contents, not the extension.

Example request (browser):

```js
const form = new FormData();
form.append('full_name', 'Md. Rahim Uddin');
form.append('father_name', 'Md. Karim Uddin');
form.append('date_of_birth', '1998-05-14');
form.append('gender', 'male');
form.append('nid_no', '1234567890');
form.append('mobile', '01712345678');
form.append('email', 'rahim@example.com');
form.append('present_address', 'Village X, Upazila Y, Cumilla');
form.append('job_category', 'construction');
form.append('work_experience', '1_2');
form.append('education_level', 'ssc');
form.append('passport_status', 'have_passport');
form.append('passport_copy', passportFile);   // File object
form.append('cv', cvFile);                    // optional
form.append('declaration', '1');
form.append('website', '');                   // honeypot
await fetch('/api/v1/applications', { method: 'POST', body: form });   // do NOT set Content-Type
```

`201 Created`:

```json
{
  "data": {
    "reference_no": "RIFA-20261015-00125",
    "submitted_at": "2026-10-15T10:32:00+06:00",
    "confirmation_email_sent": true
  }
}
```

`confirmation_email_sent` is `false` when no email was given or sending failed — the success page then
shows "Please save your reference number."

`422` example:

```json
{
  "error": {
    "code": "validation_failed",
    "message": "Please check the highlighted fields.",
    "fields": {
      "mobile": "Enter a valid Bangladeshi mobile number (01XXXXXXXXX).",
      "passport_copy": "Please upload your passport copy."
    }
  }
}
```

### POST /messages

JSON. Rate limit: 5 successful messages per IP per hour.

| Field | Required | Rule | Error message |
|---|---|---|---|
| `full_name` | ✅ | 2–100 chars | "Please enter your name." / "Name must be 2–100 characters." |
| `email` | ✅ | valid email | "Please enter your email address." / "Enter a valid email address." |
| `phone` | ✅ | 7–20 chars: digits, spaces, `+`, `-` | "Please enter your phone number." / "Enter a valid phone number." |
| `subject` | ✅ | a `contact_subjects[].value` | "Please select a subject." / "Please select a valid subject." |
| `message` | ✅ | 10–5000 chars | "Please write your message." / "Message must be 10–5000 characters." |
| `website` | — | honeypot, must be empty | — |
| `captcha_token` | ✅ when captcha is on | Cloudflare Turnstile token (see *Captcha* below) | "Please complete the security check and try again." |

```json
{
  "full_name": "Md. Rahim Uddin",
  "email": "rahim@example.com",
  "phone": "01712345678",
  "subject": "general_inquiry",
  "message": "I want to know about construction jobs in Johor.",
  "website": ""
}
```

`201 Created`:

```json
{ "data": { "id": 42, "received_at": "2026-10-15T10:32:00+06:00" } }
```

---

### POST /applications/status (P5-04)

Applicant status lookup for the public "Check your application" page. JSON body (POST, so the mobile number never
appears in URLs or server logs). Also checks `captcha_token` when captcha is on.

| Field | Required | Rule | Message |
|---|---|---|---|
| `reference_no` | ✅ | `RIFA-YYYYMMDD-NNNNN` (case and spaces ignored) | "Please enter your reference number." / "Enter the reference number from your confirmation, e.g. RIFA-20261015-00042." |
| `mobile` | ✅ | the mobile used on the application; `+88`, spaces, dashes allowed | "Please enter your mobile number." / "Enter a valid Bangladeshi mobile number (01XXXXXXXXX)." |

```json
{ "reference_no": "RIFA-20261015-00042", "mobile": "01712345678" }
```

`200 OK` — no name or other personal data; internal admin notes are never included:

```json
{
  "data": {
    "reference_no": "RIFA-20261015-00042",
    "job_category": "Construction",
    "submitted_at": "2026-10-15T09:30:00+06:00",
    "status": "shortlisted",
    "status_label": "Shortlisted",
    "status_updated_at": "2026-10-18T15:45:00+06:00",
    "history": [
      { "status": "received", "status_label": "Received", "changed_at": "2026-10-15T09:30:00+06:00" },
      { "status": "under_review", "status_label": "Under Review", "changed_at": "2026-10-16T11:00:00+06:00" },
      { "status": "shortlisted", "status_label": "Shortlisted", "changed_at": "2026-10-18T15:45:00+06:00" }
    ]
  }
}
```

Statuses: `received, under_review, shortlisted, interview, medical, visa_processing, departed, on_hold, rejected`.

| Error | When | Show |
|---|---|---|
| `404 not_found` | no live application with this reference **and** mobile (same answer for either being wrong) | `error.message` |
| `429 rate_limited` | 10 unsuccessful lookups from one IP in 15 minutes, or 30 successful per hour | `error.message`; `Retry-After` header in seconds |

---

### GET /jobs (P5-02)

Published job listings for the Home and Malaysia Jobs pages, in the admin's order. Cached 5 minutes
(`Cache-Control: public, max-age=300`). Filtering by category/location/search stays in the browser (the list is small).

```json
{
  "data": [
    {
      "id": 1,
      "slug": "construction-worker",
      "title": "Construction Worker",
      "employer_type": "Construction Company",
      "category": { "slug": "construction", "name": "Construction" },
      "location": "Kuala Lumpur",
      "salary_min": 1800,
      "salary_max": 2200,
      "currency": "MYR",
      "description": null,
      "image_public_id": "rifa/jobs/construction-worker-3fa2c1d9"
    }
  ]
}
```

- Salaries are monthly. `description` is optional plain text (keep line breaks, no HTML).
- `image_public_id` is a **public** Cloudinary image — use it with `cloudinaryUrl()`. `null` = no photo uploaded:
  fall back to the local `job.<slug>` image, then the category image.
- The 12 starting jobs are the same as `src/data/jobs.js` (same slugs), so the existing photos keep working.
- Apply link: `/apply?category=<category.slug>` (as today).

---

### GET /news and GET /news/{slug} (P5-03)

News & Updates for the News pages (and e.g. the latest 3 on Home). Cached 5 minutes. Only published posts whose
date has been reached are returned, newest first.

`GET /news?limit=3` (`limit` optional, 1–50) — list without the body:

```json
{
  "data": [
    {
      "id": 1,
      "slug": "new-job-openings-in-malaysia",
      "title": "New Job Openings in Malaysia",
      "published_on": "2026-10-12",
      "excerpt": "Fresh opportunities are now open in construction, manufacturing and hospitality.",
      "image_public_id": null
    }
  ]
}
```

`GET /news/{slug}` — the same fields plus `body`, an array of plain-text paragraphs (render each as `<p>`, no HTML):

```json
{ "data": { "id": 1, "slug": "new-job-openings-in-malaysia", "…": "…", "body": ["First paragraph.", "Second paragraph."] } }
```

`404 not_found` for an unknown, draft or not-yet-published slug — show the normal Not Found page.
The 3 starting posts are the same as `src/data/news.js` (same slugs). `image_public_id` `null` → keep the current
local image as fallback.

---

## Captcha on Apply + Contact (P5-06)

`POST /applications` and `POST /messages` check a [Cloudflare Turnstile](https://developers.cloudflare.com/turnstile/)
token **when the backend has `CAPTCHA_ENABLED=true`** (off by default — switch it on only after the widget is live).

- Render the widget on both forms with the site key from `VITE_TURNSTILE_SITE_KEY`.
- Send its token as `captcha_token` (JSON or FormData). A FormData built from the `<form>` element also works:
  the widget's hidden field `cf-turnstile-response` is accepted.
- The check runs **before** form validation. Missing/invalid/expired token →
  `422` with `fields.captcha_token` only; show the message near the widget and **reset the widget**
  (a token can be used once), then let the visitor submit again.
- Local/staging test keys: site key `1x00000000000000000000AA` (always passes) with backend secret
  `1x0000000000000000000000000000000AA`. Site key `2x00000000000000000000AB` always fails — use it to test the error state.

---

## Admin — authentication (implemented)

All admin requests use the session cookie (`fetch(url, { credentials: 'same-origin' })`).
Every non-GET request needs header `X-CSRF-Token` with the token from login or `/me`.

| Method | Endpoint | Body | Success | Errors |
|---|---|---|---|---|
| POST | `/admin/auth/login` | `{ "email", "password" }` | `200 { data: { admin: {id, name, email, role}, csrf_token } }` | 401 `invalid_credentials` · 429 locked ("Too many failed attempts. Please try again in 15 minutes.") · 422 |
| GET | `/admin/auth/me` | — | `200 { data: { admin, csrf_token } }` | 401 (not logged in / idle 60 min / password changed elsewhere) |
| POST | `/admin/auth/logout` | — | `204` | 419 without CSRF |
| POST | `/admin/auth/change-password` | `{ "current_password", "new_password" }` | `200 { data: { message } }` | 422 `current_password`: "Current password is incorrect." · `new_password`: "Password must be 10–200 characters." / "New password must be different from the current password." |
| POST | `/admin/auth/forgot-password` | `{ "email" }` | always `200 { data: { message } }` | 422 invalid email format |
| POST | `/admin/auth/reset-password` | `{ "token", "password" }` | `200 { data: { message } }` | 422 `token`: "This reset link is invalid or has expired." |

The reset email links to `/admin/reset-password?token=…` (frontend route). Tokens expire after 30 minutes and work once.
Changing or resetting a password logs out every other session of that admin.

## Admin — applications (implemented)

### GET /admin/applications

Query (all optional): `q` (name, reference, NID, email, or ≥ 4 digits of the mobile), `status`, `category` (slug),
`passport_status`, `gender`, `flag` (`age` | `duplicate`), `from`, `to` (`YYYY-MM-DD`, inclusive),
`sort` (`-created_at` default, `created_at`, `full_name`, `-full_name`), `page` (default 1), `per_page` (default 20, max 100).

```json
{
  "data": [{
    "id": 12, "reference_no": "RIFA-20261015-00125", "full_name": "Md. Rahim Uddin",
    "mobile": "+8801712345678", "email": "rahim@example.com",
    "category": { "slug": "construction", "name": "Construction" },
    "passport_status": "have_passport", "status": "received",
    "is_age_flagged": false, "is_duplicate_flagged": false,
    "created_at": "2026-10-15T10:32:00+06:00"
  }],
  "meta": { "page": 1, "per_page": 20, "total": 57 }
}
```

### GET /admin/applications/{id}

List fields plus `father_name, date_of_birth, age_at_submission, gender, nid_no, present_address,
work_experience, education_level, updated_at`, and:

```json
{
  "labels": { "gender": "Male", "work_experience": "1–2 years", "education_level": "SSC/Dakhil", "passport_status": "Have Passport", "status": "Received" },
  "allowed_next_statuses": ["under_review", "shortlisted", "interview", "medical", "visa_processing", "departed", "on_hold", "rejected"],
  "documents": [{ "id": 4, "doc_type": "passport_copy", "format": "pdf", "bytes": 182344, "original_filename": "passport.pdf", "created_at": "…" }],
  "notes": [{ "id": 9, "body": "Phoned the applicant.", "admin": { "id": 1, "name": "Md. Samin Israk" }, "created_at": "…" }],
  "history": [{ "id": 3, "from_status": "received", "to_status": "shortlisted", "note": "Good candidate", "changed_by": { "id": 1, "name": "Md. Samin Israk" }, "created_at": "…" }]
}
```

Notes and history are newest first. Use `allowed_next_statuses` to fill the status dropdown.

### PATCH /admin/applications/{id}/status

`{ "status": "shortlisted", "note": "optional, ≤ 1000 chars" }` → `200` with the full updated detail.
Rules: forward moves may skip steps; `on_hold`/`rejected` from any open status; `on_hold` back to any open status;
`departed`/`rejected` are final (only a super admin can reopen). Invalid move → `422 { status: "Cannot change status from Shortlisted to Received." }`.

### POST /admin/applications/{id}/notes

`{ "body": "≤ 2000 chars" }` → `201 { data: { id, body, admin: {id, name}, created_at } }`.

### GET /admin/applications/{id}/documents/{docId}/url

`200 { data: { url, expires_at } }` — open `url` in a new tab right away; it expires after 5 minutes. Every call is audit-logged.

### DELETE /admin/applications/{id}

Super admin only (`403` for staff). Soft delete → `204`; the application disappears from lists and detail.

## Admin — messages inbox (implemented)

### GET /admin/messages

Query: `q` (name, email, phone, message text), `status` (`new` | `read` | `replied` | `archived`; **default: everything except archived**),
`subject`, `page`, `per_page` (max 100). Newest first.

```json
{
  "data": [{
    "id": 42, "full_name": "Md. Rahim Uddin", "email": "rahim@example.com", "phone": "01712345678",
    "subject": "general_inquiry", "subject_label": "General Inquiry",
    "excerpt": "Hello, I want to know about construction jobs in Johor.",
    "status": "new", "status_label": "New", "replies_count": 0,
    "read_at": null, "created_at": "2026-10-19T09:00:00+06:00"
  }],
  "meta": { "page": 1, "per_page": 20, "total": 3, "unread_count": 2 }
}
```

Use `meta.unread_count` for the sidebar badge.

### GET /admin/messages/{id}

List fields plus `message` (full text) and `replies` (oldest first):
`{ id, body, admin: {id, name}, sent_to, delivery_status: "sent" | "failed", sent_at, created_at }`.
Opening a `new` message marks it `read`.

### PATCH /admin/messages/{id}

`{ "status": "new" | "read" | "archived" }` (`new` = mark unread) → `200` detail.

### POST /admin/messages/{id}/replies

`{ "body": "≤ 5000 chars" }` → `201` detail. The reply is emailed to the sender (Reply-To: office inbox) and saved.
If sending fails the reply is saved with `delivery_status: "failed"` and the status does not change — show a warning and let the admin retry.
On success the status becomes `replied`.

## Admin — dashboard, export, users (implemented)

### GET /admin/dashboard

```json
{
  "data": {
    "applications": { "today": 3, "this_week": 14, "total": 230 },
    "messages": { "unread": 2, "open": 9 },
    "by_status": [{ "status": "received", "label": "Received", "count": 40 }, "… all 9 statuses, zeros included"],
    "by_category": [{ "slug": "construction", "name": "Construction", "count": 88 }, "… all active categories"],
    "latest_applications": [{ "id": 12, "reference_no": "RIFA-…", "full_name": "…", "category_name": "Construction", "status": "received", "status_label": "Received", "created_at": "…" }],
    "latest_messages": [{ "id": 42, "full_name": "…", "subject_label": "Support", "status": "new", "created_at": "…" }]
  }
}
```

"This week" starts on Sunday (office week). Latest lists have up to 5 items; archived messages are excluded.

### GET /admin/applications/export

Same query filters as the list (no paging) → `text/csv` download named `rifa-applications-YYYYMMDD-HHMM.csv`,
UTF-8 with BOM (opens correctly in Excel). Columns: Reference, Submitted (GMT+6), Full name, Father's name,
Date of birth, Age, Gender, NID, Mobile, Email, Present address, Category, Work experience, Education,
Passport status, Status, Over 45, Possible duplicate. Trigger it with a normal link/`window.location`
(the session cookie is sent automatically). Every export is audit-logged.

### Admin users (super admin only — staff get 403)

| Method | Endpoint | Body | Result |
|---|---|---|---|
| GET | `/admin/users` | — | `{ data: [{ id, name, email, role, is_active, is_locked, last_login_at, created_at }] }` |
| POST | `/admin/users` | `{ name, email, role: "super_admin" \| "staff", password }` | `201` user · 422 "An admin with this email already exists." |
| PATCH | `/admin/users/{id}` | any of `{ name, role, is_active, password }` | `200` user |

Rules (422 messages): "You cannot deactivate your own account." · "Use \"Change password\" to change your own password." ·
"There must be at least one active super admin." Setting a password or deactivating a user logs them out everywhere.

## Admin — job listings (P5-02)

All need login + `X-CSRF-Token` on writes. Job objects are the public shape plus
`is_published`, `sort_order`, `created_at`, `updated_at`.

| Method | Path | Notes |
|---|---|---|
| GET | `/admin/jobs` | all jobs incl. drafts; filters `?status=published\|draft`, `?category=<slug>`, `?q=` (title, employer type, location) |
| GET | `/admin/jobs/{id}` | one job |
| POST | `/admin/jobs` | JSON, see fields below → `201` |
| PATCH | `/admin/jobs/{id}` | JSON, any subset of the fields; only sent fields change. The slug never changes. |
| DELETE | `/admin/jobs/{id}` | **super admin only** (staff get 403 — they can unpublish instead) → `204`; the photo is deleted too |
| POST | `/admin/jobs/{id}/image` | multipart, field `image`: JPG/PNG/WebP ≤ 5 MB; replaces the old photo → job |
| DELETE | `/admin/jobs/{id}/image` | removes the photo → job |

| Field | Required | Rule | Message |
|---|---|---|---|
| `title` | ✅ | 2–100 chars | "Please enter the job title." / "Job title must be 2–100 characters." |
| `employer_type` | ✅ | 2–100 chars | "Please enter the employer type." / "Employer type must be 2–100 characters." |
| `job_category` | ✅ | a `job_categories[].slug` from `/lookups` | "Please select a job category." / "Please select a valid job category." |
| `location` | ✅ | 2–100 chars | "Please enter the location." / "Location must be 2–100 characters." |
| `salary_min` | ✅ | whole number 0–100000 (RM / month) | "Please enter the minimum salary." / "Minimum salary must be a whole number from 0 to 100000." |
| `salary_max` | ✅ | whole number 0–100000, ≥ `salary_min` | "Please enter the maximum salary." / "Maximum salary must be at least the minimum salary." |
| `description` | — | ≤ 5000 chars; `""` clears it | "Description must be 5000 characters or fewer." |
| `is_published` | — | boolean, default `true` | — |
| `sort_order` | — | 0–9999, default: end of the list | "Order must be a whole number from 0 to 9999." |

Photo errors (422 `fields.image`): "Please choose a photo." / "Photo must be a JPG, PNG or WebP image." /
"Photo must be 5 MB or smaller." / "This file is not a valid image."

---

## Admin — News & Updates (P5-03)

Same pattern as job listings: login + `X-CSRF-Token` on writes. Post objects are the article shape plus
`body_text` (the raw text for the editor), `is_published`, `status` (`published` | `scheduled` | `draft`),
`created_at`, `updated_at`.

| Method | Path | Notes |
|---|---|---|
| GET | `/admin/news` | all posts, newest first; filters `?status=published\|scheduled\|draft`, `?q=` (title, summary) |
| GET | `/admin/news/{id}` | one post |
| POST | `/admin/news` | JSON → `201` |
| PATCH | `/admin/news/{id}` | JSON, any subset; only sent fields change; the slug never changes |
| DELETE | `/admin/news/{id}` | **super admin only** → `204`; cover photo deleted too |
| POST | `/admin/news/{id}/image` | multipart `image`: JPG/PNG/WebP ≤ 5 MB → post |
| DELETE | `/admin/news/{id}/image` | remove the cover photo → post |

| Field | Required | Rule | Message |
|---|---|---|---|
| `title` | ✅ | 5–150 chars | "Please enter the title." / "Title must be 5–150 characters." |
| `excerpt` | ✅ | 10–300 chars (shown on cards) | "Please enter a short summary." / "Summary must be 10–300 characters." |
| `body` | ✅ | 20–20000 chars, plain text; **a blank line starts a new paragraph** | "Please write the article." / "Article must be 20–20000 characters." |
| `published_on` | — | `YYYY-MM-DD`, default today; a future date schedules the post | "Enter a valid date (YYYY-MM-DD)." |
| `is_published` | — | boolean, default `true` (`false` = draft) | — |

Photo errors are the same as for jobs.

---

## Admin — emails to applicants (P5-01, email only)

From the application detail page. Any admin (staff or super admin). Replies from applicants go to the office inbox.

| Method | Path | Notes |
|---|---|---|
| GET | `/admin/applications/{id}/message-templates` | `{ can_email, templates: [{ key, label, subject, body }] }` — already filled with name, reference, category |
| GET | `/admin/applications/{id}/messages` | message log, newest first |
| POST | `/admin/applications/{id}/messages` | `{ template, subject, body }` → `201` with the logged message |

Templates: `shortlisted`, `interview`, `medical`, `documents_required`, `custom`. Text in `[CAPITALS]` (e.g. `[DATE]`,
`[PLACE]`, `[MEDICAL CENTRE]`) must be replaced by the admin — the API refuses to send while any is left
(422: "Replace [DATE] with the real details before sending."). The email adds the admin's name as signature and the
reference number. `can_email: false` → the applicant gave no email: disable sending ("Please call them instead.").

Message object:

```json
{
  "id": 7, "channel": "email", "template": "interview",
  "subject": "Interview invitation — RIFA-20261015-00042",
  "body": "Dear Md. Karim,\n\n…", "sent_to": "karim@example.com",
  "delivery_status": "sent", "error": null,
  "admin": { "id": 2, "name": "Nusrat Jahan" },
  "sent_at": "2026-10-20T12:00:00+06:00", "created_at": "2026-10-20T12:00:00+06:00"
}
```

`delivery_status: "failed"` (email server problem) is still `201` and logged — show the `error` and let the admin send again.

| Field | Message |
|---|---|
| `template` | "Please choose a template." / "Please choose a valid template." |
| `subject` (3–200) | "Please enter a subject." / "Subject must be 3–200 characters." |
| `body` (10–5000) | "Please write the message." / "Message must be 10–5000 characters." |
| `email` | "This applicant did not give an email address. Please call them instead." |

---

## Admin endpoints (summary — remaining ones are detailed as they are built)

Session cookie auth (`credentials: 'same-origin'`). Every non-GET request must send
`X-CSRF-Token: <csrf_token>` (returned by login and `/admin/auth/me`). Full details are added as
BE-11 … BE-14 are implemented.

| Method | Endpoint | Notes |
|---|---|---|
| POST | `/admin/auth/login` | `{email, password}` → `{data: {admin, csrf_token}}` |
| POST | `/admin/auth/logout` | |
| GET | `/admin/auth/me` | `{data: {admin, csrf_token}}` or 401 |
| POST | `/admin/auth/forgot-password` | `{email}` → always 200 |
| POST | `/admin/auth/reset-password` | `{token, password}` |
| POST | `/admin/auth/change-password` | `{current_password, new_password}` |
| GET | `/admin/dashboard` | stats |
| GET | `/admin/applications` | `q, status, category, passport_status, gender, flag, from, to, sort, page, per_page` |
| GET | `/admin/applications/export` | CSV, same filters |
| GET | `/admin/applications/{id}` | detail + documents + notes + history |
| PATCH | `/admin/applications/{id}/status` | `{status, note?}` |
| POST | `/admin/applications/{id}/notes` | `{body}` |
| GET | `/admin/applications/{id}/documents/{docId}/url` | `{data: {url, expires_at}}` (5 min) |
| DELETE | `/admin/applications/{id}` | super admin |
| GET | `/admin/messages` | `q, status, subject, page, per_page` |
| GET | `/admin/messages/{id}` | marks read |
| PATCH | `/admin/messages/{id}` | `{status}` |
| POST | `/admin/messages/{id}/replies` | `{body}` → emails the sender |
| GET/POST | `/admin/users` | super admin |
| PATCH | `/admin/users/{id}` | super admin |
