# osTicket API Plus

**osTicket REST API plugin** that adds the missing ticket endpoints the stock HTTP API never shipped.

Create tickets with core osTicket. **List, view, reply, assign, close, and automate** them with API Plus. Same `X-API-Key` auth. No core file patches. MIT licensed.

[![Licence](https://img.shields.io/badge/licence-MIT-blue.svg)](LICENSE)
[![osTicket](https://img.shields.io/badge/osTicket-1.17%2B%20%7C%201.18%2B-brightgreen.svg)](#requirements)
[![PHP](https://img.shields.io/badge/PHP-8%2B-777BB4.svg)](#requirements)

Repository: https://github.com/HairyDuck/osticket-api-plus

---

## Why osTicket API Plus?

Stock osTicket only exposes **ticket creation** over HTTP (`POST /api/tickets.json`). Integrators who need a real **osTicket REST API** for helpdesk automation, CRM sync, chatbots, or mobile apps usually hit a wall: scrape the staff panel, or fork core.

**osTicket API Plus** is a drop-in plugin that extends the official API surface safely:

| Need | Stock API | API Plus |
|------|-----------|----------|
| Create ticket | Yes | Yes (unchanged) |
| List / search tickets | No | Yes |
| Get ticket + thread | No | Yes |
| User reply by email scope | No | Yes |
| Staff reply / note / status | No | Yes |
| Assign / claim | No | Yes |
| Set priority / help topic | No | Yes |
| Status, dept, staff, canned catalogues | No | Yes |
| Health check | No | Yes |

Ideal for: **osTicket automation**, Zapier/Make-style workflows, internal tools, AI agents, and multi-channel support systems that already speak HTTP JSON.

---

## Features

- **User-scoped API** – list, view, and reply to tickets owned by an email address
- **Staff API** – full agent actions under a configured staff username
- **Public ticket number routes** – operate on `#12345`, not only internal ids
- **Catalogues** – statuses, departments, staff directory, canned responses, priorities, help topics
- **Rich list filters** – open/closed/all, status id/name, department, topic, priority, `updated_since`, pagination with `total` / `has_more`
- **Production-safe** – plugin-only install; stock create path untouched; staff API off by default
- **Same auth as core** – `X-API-Key` + allowed IP

---

## Requirements

- osTicket **1.17+** or **1.18+** (PHP 8 recommended)
- A valid API key (Admin → Manage → API) with your client IP allowed
- Plugin folder writable under `include/plugins/`

---

## Install

1. Copy the `osticket-api-plus` folder to `include/plugins/osticket-api-plus/` on your osTicket server.
2. Admin → Manage → Plugins → **Add New Plugin** → install **osTicket API Plus**.
3. Enable the plugin and open instance config:
   - Enable **user-scoped API** if end-user style access is needed
   - Set **Staff username**, then enable **staff API**
4. Use an API key whose IP matches the machine calling the API.

Upgrade from 1.0.x: replace the plugin files, keep the instance enabled, then hit `/api-plus/health.json` to confirm `version` is `1.1.0`.

---

## Authentication

```http
X-API-Key: YOUR_KEY_HERE
Content-Type: application/json
```

Source IP must be allowed on the key. All responses are JSON.

Base URL examples use `https://support.example.com`.

---

## Quick start

```bash
# Health / version
curl -sS -H "X-API-Key: YOUR_KEY" \
  "https://support.example.com/api/http.php/api-plus/health.json"

# Staff: list open tickets
curl -sS -H "X-API-Key: YOUR_KEY" \
  "https://support.example.com/api/http.php/api-plus/staff/tickets.json?status=open&limit=25"

# Staff: get by public number
curl -sS -H "X-API-Key: YOUR_KEY" \
  "https://support.example.com/api/http.php/api-plus/staff/tickets/by-number/12345.json"

# Staff: reply and close
curl -sS -X POST -H "X-API-Key: YOUR_KEY" -H "Content-Type: application/json" \
  -d "{\"message\":\"Thanks – this is resolved.\",\"status\":\"closed\",\"alert\":true}" \
  "https://support.example.com/api/http.php/api-plus/staff/tickets/by-number/12345/reply.json"
```

---

## Health

`GET /api/http.php/api-plus/health.json`

Returns plugin version and whether user/staff APIs are enabled. Requires an API key; does not require staff/user toggles.

---

## User-scoped endpoints

Enable **Enable user-scoped API**. All calls require `email=` (query or JSON body) and only touch tickets owned by that address.

### List tickets

```bash
curl -sS -H "X-API-Key: YOUR_KEY" \
  "https://support.example.com/api/http.php/api-plus/tickets.json?email=user@example.com&status=open"
```

Optional: `status=open|closed|all` (default `all`), `limit`, `offset`.

### Get ticket + thread

```bash
curl -sS -H "X-API-Key: YOUR_KEY" \
  "https://support.example.com/api/http.php/api-plus/tickets/12345.json?email=user@example.com"
```

### Post a user reply

```bash
curl -sS -X POST -H "X-API-Key: YOUR_KEY" -H "Content-Type: application/json" \
  -d "{\"message\":\"Thanks, that fixed it.\"}" \
  "https://support.example.com/api/http.php/api-plus/tickets/12345.json?email=user@example.com"
```

---

## Staff endpoints

Enable **Enable staff API** and set **Staff username** to an existing agent. Actions are authored as that agent.

### List tickets

```bash
curl -sS -H "X-API-Key: YOUR_KEY" \
  "https://support.example.com/api/http.php/api-plus/staff/tickets.json?status=open&limit=25"
```

Query parameters:

| Param | Description |
|-------|-------------|
| `status` | `open` (default), `closed`, or `all` |
| `status_id` | Exact status id (instead of `status`) |
| `status_name` | Exact status name (instead of `status`) |
| `email` | Filter by requester email |
| `dept_id` | Department id |
| `topic_id` | Help topic id |
| `priority_id` | Priority id |
| `updated_since` | ISO-8601, `Y-m-d H:i:s`, or unix timestamp |
| `limit` | Page size (max 100) |
| `offset` | Pagination offset |

Response includes `count`, `total`, `limit`, `offset`, `has_more`.

### Get ticket (internal id or public number)

```bash
curl -sS -H "X-API-Key: YOUR_KEY" \
  "https://support.example.com/api/http.php/api-plus/staff/tickets/42.json"

curl -sS -H "X-API-Key: YOUR_KEY" \
  "https://support.example.com/api/http.php/api-plus/staff/tickets/by-number/12345.json"
```

### Reply, status, note

```bash
# Reply (optional status change)
curl -sS -X POST -H "X-API-Key: YOUR_KEY" -H "Content-Type: application/json" \
  -d "{\"message\":\"We have looked into this.\",\"status\":\"closed\",\"alert\":true}" \
  "https://support.example.com/api/http.php/api-plus/staff/tickets/42/reply.json"

# Status only
curl -sS -X POST -H "X-API-Key: YOUR_KEY" -H "Content-Type: application/json" \
  -d "{\"status\":\"Resolved\",\"comments\":\"Closed via API\"}" \
  "https://support.example.com/api/http.php/api-plus/staff/tickets/by-number/12345/status.json"

# Internal note
curl -sS -X POST -H "X-API-Key: YOUR_KEY" -H "Content-Type: application/json" \
  -d "{\"note\":\"Checked logs; waiting on customer.\",\"alert\":false}" \
  "https://support.example.com/api/http.php/api-plus/staff/tickets/42/note.json"
```

`status` may be a status id, status name, or state (`open` / `closed`).

### Assign / claim

```bash
# Claim as the configured staff user
curl -sS -X POST -H "X-API-Key: YOUR_KEY" -H "Content-Type: application/json" \
  -d "{\"claim\":true,\"note\":\"Taking this\"}" \
  "https://support.example.com/api/http.php/api-plus/staff/tickets/42/assign.json"

# Assign to staff / team / department
curl -sS -X POST -H "X-API-Key: YOUR_KEY" -H "Content-Type: application/json" \
  -d "{\"staff_id\":5,\"dept_id\":2,\"alert\":true}" \
  "https://support.example.com/api/http.php/api-plus/staff/tickets/by-number/12345/assign.json"
```

Body fields: `claim`, `staff_id`, `team_id`, `dept_id`, `note`, `alert`.

### Priority and help topic

```bash
curl -sS -X POST -H "X-API-Key: YOUR_KEY" -H "Content-Type: application/json" \
  -d "{\"priority_id\":2}" \
  "https://support.example.com/api/http.php/api-plus/staff/tickets/42/priority.json"

curl -sS -X POST -H "X-API-Key: YOUR_KEY" -H "Content-Type: application/json" \
  -d "{\"topic_id\":3}" \
  "https://support.example.com/api/http.php/api-plus/staff/tickets/42/topic.json"
```

### Catalogues

```bash
curl -sS -H "X-API-Key: YOUR_KEY" \
  "https://support.example.com/api/http.php/api-plus/staff/statuses.json"

curl -sS -H "X-API-Key: YOUR_KEY" \
  "https://support.example.com/api/http.php/api-plus/staff/depts.json"

curl -sS -H "X-API-Key: YOUR_KEY" \
  "https://support.example.com/api/http.php/api-plus/staff/staff.json"

curl -sS -H "X-API-Key: YOUR_KEY" \
  "https://support.example.com/api/http.php/api-plus/staff/canned.json"

curl -sS -H "X-API-Key: YOUR_KEY" \
  "https://support.example.com/api/http.php/api-plus/staff/priorities.json"

curl -sS -H "X-API-Key: YOUR_KEY" \
  "https://support.example.com/api/http.php/api-plus/staff/topics.json"
```

Use catalogue ids when assigning, setting priority/topic, or filtering lists.

---

## Production rollout checklist

1. Deploy the plugin folder; leave disabled if you want a pure file drop first.
2. Enable with **Staff API off** and **User API off** → confirm stock `POST /api/tickets.json` still works.
3. Enable user API; test list/get with a test email.
4. Enable staff API with a dedicated agent; test on a **test ticket**.
5. Point automation at staff endpoints only after smoke checks pass.

---

## Features we will consider if requested

These are **not implemented yet**. Open an issue or pull request if you need them:

- Attachments (list, upload on reply, download)
- Subject / requester email search (`q=`)
- Collaborators / CC management
- Ticket merge / related links
- Outbound webhooks for ticket events
- Staff API mutation audit log
- OpenAPI / Postman collection

Community contributions welcome under the MIT licence.

---

## Layout

```text
osticket-api-plus/
  plugin.php              Metadata (version 1.1.0)
  osticket-api-plus.php   Bootstrap + route registration
  config.php              Admin settings
  api.php                 Controllers
  tests/smoke.php         Offline syntax + route checks
  LICENSE
  README.md
```

Offline smoke: `php tests/smoke.php`

---

## Keywords

osTicket API, osTicket REST API, osTicket plugin, osTicket HTTP API, list tickets API, reply to ticket API, assign ticket API, osTicket automation, helpdesk API, open source osTicket extension.

---

## Licence

MIT – see [LICENSE](LICENSE).
