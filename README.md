# osTicket Domain Gate

**Domain allowlist / blocklist plugin** for osTicket. Gate new tickets by sender domain, notify blocked senders with a canned reply, honour organisation domain mappings, allow existing ticket references, and let staff permanently allow a domain with one internal note.

Same install model as other osTicket plugins. No core file patches. MIT licensed.

[![Licence](https://img.shields.io/badge/licence-MIT-blue.svg)](LICENSE)
[![osTicket](https://img.shields.io/badge/osTicket-1.17%2B%20%7C%201.18%2B-brightgreen.svg)](#requirements)
[![PHP](https://img.shields.io/badge/PHP-8%2B-777BB4.svg)](#requirements)

Repository: https://github.com/HairyDuck/osticket-domain-gate

---

## Why Domain Gate?

Stock osTicket can reject unknown users or ban addresses, but:

- **Reject + notify** in one ticket filter is unreliable
- Free-mail vs work-mail policy is awkward to maintain as filters
- Recognised customer domains often already live on **Organisation** records

Domain Gate applies a clear domain policy on `ticket.created`, sends a canned (or fallback) reply, closes the ticket, and leaves staff a note explaining how to allow the domain.

---

## Features

- **Modes:** allowlist (default), blocklist, or both
- **Canned rejection reply** as a configured staff user (fallback plain text supported), with a **config preview** of the selected canned body
- **Organisation domains:** if `Organization::forDomain()` matches, allow
- **Ticket-number bypass:** subject/body refs like `#23836` / `Ticket #23836` allow when that ticket exists (reply-style mail)
- **Staff allow command:** internal note first line `DOMAIN-GATE-ALLOW` appends the sender domain to `data/allowlist-extra.txt`
- **Email / address overrides:** explicit allow and deny lists
- **Channel toggles:** Email (default on), Web Forms, API (default off)
- Complements core **Banlist** and **Accept email from unknown Users**; does not replace them

---

## Requirements

- osTicket **1.17+** or **1.18+** (PHP 8 recommended)
- An active staff agent username for outbound rejection replies
- Optional: a canned response for the rejection body

---

## Install

1. Copy the `osticket-domain-gate` folder to `include/plugins/osticket-domain-gate/` on your osTicket server.
2. Ensure `include/plugins/osticket-domain-gate/data/` is writable by the web/PHP user (for `allowlist-extra.txt`).
3. Admin → Manage → Plugins → **Add New Plugin** → install **osTicket Domain Gate**.
4. Enable the plugin and open instance config (sections 1–5):
   - Pick **Reply as agent** and **Policy mode**
   - Fill **Allowlist domains** (and optionally Organisation-domain honour)
   - Select a **Canned rejection response** (preview shows after Save) or use fallback text
   - Confirm **Close blocked tickets as** status
5. Seed allowlist domains (customer work domains). Keep Web/API gating off unless you intend to gate those channels too.

---

## Policy modes

| Mode | Behaviour |
|------|-----------|
| **Allowlist only** | New tickets allowed only if domain (or override email) is allowlisted, or an override applies (org / ticket ref / allow-email) |
| **Blocklist only** | New tickets blocked only if domain is on the blocklist (or deny-email) |
| **Both** | Must be allowlisted and not blocklisted |

Default free-mail domains are pre-filled in the blocklist field for convenience when using blocklist/both modes.

---

## Staff: allow a domain

When Domain Gate blocks a ticket it posts an internal note. To allow that sender's domain permanently:

1. Open the (closed) ticket
2. Post an **internal note** whose **first line** is exactly:

```text
DOMAIN-GATE-ALLOW
```

3. The plugin appends the domain to `data/allowlist-extra.txt` and confirms with another note

You can also edit the allowlist in plugin config, or maintain extras by hand.

---

## Overrides (always checked first)

1. Deny email list  
2. Allow email list  
3. Existing ticket number in subject/body (if enabled)  
4. Organisation domain mapping (if enabled)  
5. Mode allowlist / blocklist rules  

---

## Interaction with core settings

- **Accept email from unknown Users = off:** never-seen addresses may never create a ticket, so Domain Gate never runs for them. Turn that on if you want Domain Gate (and its reply) to handle unknown free-mail instead of a silent drop.
- **Banlist:** hard block with no Domain Gate reply. Use for abusive addresses.
- **Existing Reject Email filters:** review overlap so you do not double-close or double-reply.

---

## Test plan

1. Allowlist mode with `customer.com` listed; mail from `ops@customer.com` creates a normal open ticket.  
2. Mail from `person@gmail.com` gets a rejection reply and ends Closed, with a Domain Gate note.  
3. Mail subject `Re: Ticket #12345` where `#12345` exists is allowed even from gmail.  
4. Organisation with domain `acme.com` allows `user@acme.com` without listing it in the text allowlist.  
5. On a blocked ticket, note `DOMAIN-GATE-ALLOW`; confirm extras file and a later gmail-from-that-domain only works if that domain was the blocked sender's domain.  
6. Confirm Web form tickets still work with **Gate Web Forms** left off.

Offline smoke: `php tests/smoke.php`

---

## Changelog

### 1.0.0

- Initial release: allowlist / blocklist / both, canned rejection, organisation domains, ticket-ref bypass, `DOMAIN-GATE-ALLOW` staff command

---

## Layout

```text
osticket-domain-gate/
  plugin.php                 Metadata (version 1.0.0)
  osticket-domain-gate.php   Bootstrap + signals
  config.php                 Admin settings
  include/class.domain_gate.php
  data/allowlist-extra.txt.example
  tests/smoke.php
  LICENSE
  README.md
```

---

## Keywords

osTicket domain allowlist, osTicket block free email, osTicket reject gmail, osTicket organisation domain, osTicket canned rejection, helpdesk domain gate

---

## Licence

MIT – see [LICENSE](LICENSE).
