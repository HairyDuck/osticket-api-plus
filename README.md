# osTicket API Plus

Open-source plugin that extends the stock osTicket HTTP API so you can **list**, **view**, **reply to**, and **update** tickets. Stock osTicket only supports ticket creation.

Clean-room implementation. Not affiliated with Nitemare Labs or any paid plugin.

Repository (private while in progress): https://github.com/HairyDuck/osticket-api-plus

**MIT licensed.**

## Production safety

- Does **not** patch or replace core osTicket files.
- Registers extra routes under `/api/http.php/api-plus/...` via the existing `api` signal.
- Stock `POST /api/tickets.json` create path is untouched.
- Route registration is wrapped in try/catch so a plugin fault cannot break ticket creation.
- **Staff API defaults to disabled** until you set a staff username and enable it.
- Install / enable only when you are ready; leaving the plugin disabled means zero API surface change.

## Requirements

- osTicket 1.17+ / 1.18+ (PHP 8 recommended)
- A valid API key (Admin → Manage → API) with the client IP allowed

## Install

1. Copy the `osticket-api-plus` folder to `include/plugins/osticket-api-plus/` on the osTicket server.
2. Admin → Manage → Plugins → **Add New Plugin** → install **osTicket API Plus**.
3. Enable the plugin and open its instance config:
   - Enable user-scoped API (optional)
   - Enable staff API only after setting **Staff username**
4. Create or reuse an API key for the machine that will call the API.

## Authentication

Same as stock:

```http
X-API-Key: YOUR_KEY_HERE
```

Source IP must match the key. Responses are JSON (`application/json`).

Base URL examples assume osTicket at `https://support.example.com`.

## User-scoped endpoints

Scoped to tickets owned by `email`. Enable **Enable user-scoped API**.

### List tickets for an email

```bash
curl -sS -H "X-API-Key: YOUR_KEY" \
  "https://support.example.com/api/http.php/api-plus/tickets.json?email=user@example.com"
```

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

## Staff endpoints

Enable **Enable staff API** and set **Staff username** to an existing agent.

### List tickets

```bash
curl -sS -H "X-API-Key: YOUR_KEY" \
  "https://support.example.com/api/http.php/api-plus/staff/tickets.json?status=open&limit=25"
```

`status`: `open` (default), `closed`, or `all`. Optional `email=` filter.

### Get ticket + thread

```bash
curl -sS -H "X-API-Key: YOUR_KEY" \
  "https://support.example.com/api/http.php/api-plus/staff/tickets/42.json"
```

(`42` is internal ticket id, not the public number.)

### Staff reply (optional status change)

```bash
curl -sS -X POST -H "X-API-Key: YOUR_KEY" -H "Content-Type: application/json" \
  -d "{\"message\":\"We have closed this as not applicable.\",\"status\":\"closed\",\"alert\":true}" \
  "https://support.example.com/api/http.php/api-plus/staff/tickets/42/reply.json"
```

`status` may be a status id, status name, or state (`open` / `closed`).

### Set status only

```bash
curl -sS -X POST -H "X-API-Key: YOUR_KEY" -H "Content-Type: application/json" \
  -d "{\"status\":\"closed\",\"comments\":\"Closed via API\"}" \
  "https://support.example.com/api/http.php/api-plus/staff/tickets/42/status.json"
```

### Internal note

```bash
curl -sS -X POST -H "X-API-Key: YOUR_KEY" -H "Content-Type: application/json" \
  -d "{\"note\":\"Checked; waiting on customer.\",\"alert\":false}" \
  "https://support.example.com/api/http.php/api-plus/staff/tickets/42/note.json"
```

## Recommended rollout on a live helpdesk

1. Deploy the plugin folder; **do not enable** yet if you want a pure file drop first.
2. Enable with **Staff API off** and **User API off** → confirm stock ticket create still works.
3. Enable user API only; test list/get with a test email.
4. Enable staff API with a dedicated agent account; test on a **test ticket** first.
5. Only then point automation at staff endpoints.

## Layout

```text
osticket-api-plus/
  plugin.php              Metadata
  osticket-api-plus.php   Plugin bootstrap + route registration
  config.php              Admin settings
  api.php                 Controllers
  LICENSE
  README.md
```

## Licence

MIT – see [LICENSE](LICENSE).
