# API

A small JSON API for the projects and tasks of one team, for scripts and integrations. It uses
[Laravel Sanctum](https://laravel.com/docs/12.x/sanctum) personal access tokens (`Authorization: Bearer …`). The browser
session never works on it.

Base URL: `https://your-app.example/api/v1`

## Getting a token

1. Sign in and open **Profile → API tokens**.
2. Give it a name, pick the **team** it is for, the **access** (read only, or read and write) and when it expires
   (30 days, 90 days, a year or never; 90 by default). Making a token asks for your password first.
3. Copy the token. It is shown **once**: only a hash is stored.

A token:

- works inside **one team** only, whichever team you have open in the browser;
- acts as **you, with your role in that team**. Members can do what members can do; only owners and admins can delete a
  project. If you are removed from the team, or the team is deleted, the token goes with it;
- obeys the team's **plan limits** (for example the project limit answers `402`);
- is rate limited per token (60 requests a minute, `THROTTLE_API_PER_MINUTE`). Over the limit it answers `429` with a
  `Retry-After` header;
- is written to the team's audit log (marked "via API") and listed, without the secret, in your data download.

Revoke a token from the same page and it stops working at once. Expired tokens are deleted a day later.

## Requests

Send `Accept: application/json`. Errors are always JSON.

| Status | Meaning |
| --- | --- |
| 200 / 201 / 204 | Done (204 has no body) |
| 401 | Missing, wrong, revoked or expired token |
| 402 | The team's plan limit is reached (`message` says which) |
| 403 | The token is read only, or your role in the team does not allow it |
| 404 | No such project or task in the token's team |
| 422 | Invalid input (`errors` lists the fields) |
| 429 | Too many requests |

Lists are paged, 25 at a time: `?page=2`. Each has `data`, `links` and `meta`.

## Projects

| Method and path | Needs | Notes |
| --- | --- | --- |
| `GET /projects` | read | |
| `GET /projects/{id}` | read | |
| `POST /projects` | write | `name` (required, max 255), `description` (optional, max 2000) |
| `PATCH /projects/{id}` | write | any of `name`, `description` |
| `DELETE /projects/{id}` | write | owners and admins only; deletes its tasks and their files |

## Tasks

| Method and path | Needs | Notes |
| --- | --- | --- |
| `GET /projects/{id}/tasks` | read | `?status=todo\|doing\|done` to filter |
| `POST /projects/{id}/tasks` | write | `title` (required, max 255), `status` (optional, default `todo`) |
| `PATCH /projects/{id}/tasks/{task}` | write | any of `title`, `status` |
| `DELETE /projects/{id}/tasks/{task}` | write | |

## Examples

```bash
export API=http://localhost:8080/api/v1
export TOKEN='1|paste-your-token-here'

# List projects
curl -s $API/projects -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json'

# Create one
curl -s -X POST $API/projects \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"name": "Launch", "description": "Ship it"}'
# {"data":{"id":12,"name":"Launch","description":"Ship it","tasks_count":0,"created_at":"…","updated_at":"…"}}

# Add a task to it, then mark it done
curl -s -X POST $API/projects/12/tasks \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"title": "Write the announcement"}'

curl -s -X PATCH $API/projects/12/tasks/40 \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"status": "done"}'

# Only the open tasks
curl -s "$API/projects/12/tasks?status=todo" -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json'
```

## Not in the API (yet)

Attachments, billing, team management and the audit log. A token's abilities are `projects:read` and `projects:write`
(`App\Models\PersonalAccessToken`); more areas would be more abilities.
