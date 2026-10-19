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
