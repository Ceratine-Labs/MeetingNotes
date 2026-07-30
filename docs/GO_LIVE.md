# Go-live checklist

Drafted 2026-07-30 from the pre-launch review. Items marked (owner: Ryan)
need a human decision or an account only Ryan can open; the rest is
buildable. Strike items as they land; this document is the single list.

## Blocking before real customers

- [ ] **Domain + TLS** (owner: Ryan). Also SPF/DKIM once email is chosen.
- [ ] **Transactional email.** `MAIL_MAILER=log` today. Pick a provider,
      wire SMTP creds, verify: account verification, password reset,
      workspace invitations, billing notices.
- [ ] **Paystack production credentials** (owner: Ryan) and one full
      test-mode cycle against a publicly reachable webhook endpoint:
      subscribe, renew, failed payment, grace period, cancel.
- [ ] **Final pricing decision** (owner: Ryan). Seeded numbers are
      placeholders until confirmed; see the pricing discussion notes.
      Consider a fair-use cap instead of a hard "Unlimited" on Business.
- [ ] **Privacy erasure job.** The policy promises deletion
      `site.data_retention_days` after account deletion; the scheduled
      job does not exist yet. The policy is a false statement until this
      ships or the wording changes.
- [ ] **Legal review** of terms + privacy (POPIA/CPA), and name a real
      Information Officer (owner: Ryan).
- [ ] **Server provisioning:** box, PostgreSQL, TLS, queue worker
      (deploy/meetingnotes-worker.supervisor.conf), scheduler cron,
      nginx (deploy/meetingnotes.nginx.conf), production .env.
- [ ] **Backups with an off-server destination and one practised
      restore.** An unrestored backup is a hope, not a backup.
- [ ] **Error monitoring + uptime checks.** Nothing is wired today.
- [ ] **LLM spend guardrail:** budget alert on the provider console, on
      top of in-app quotas.

## Testing programme (in progress, this order)

- [x] **FakeDriver** in the Llm module: deterministic, instant, valid
      output; selectable per environment; refuses to run in production.
- [x] **Demo seeder** (`php artisan demo:seed`): demo workspace with
      meetings in varied states, decisions, action items open and done,
      so any environment is instantly browsable without an LLM key.
      Login demo@notefiend.test / demo-password-123.
- [x] **Playwright E2E suite** (`e2e/`, 12 tests): public pages + PWA
      endpoints, registration, login, paste-to-minutes through the fake
      driver, export, minutes library states, action items tick/reopen
      and filters, mobile overflow + expandable rows.
- [x] **CI workflow** (.github/workflows/ci.yml) running PHPUnit (122
      tests) + Playwright on every push and pull request.

Existing coverage for reference: 15 PHPUnit feature files (billing,
webhooks, quota, tenancy isolation, minutes pipeline with HTTP-faked
Anthropic responses, UI, exports, search, backups, public site, action
items register).

## Non-blocking, soon after

- [ ] Advertising/analytics stack decision (owner: Ryan). Keep the
      privacy policy honest: it currently promises no tracking cookies.
- [ ] Annual billing offer (code supports the interval already).
- [ ] Sweep remaining `bi bi-*` icons in minutes create/show views.
- [ ] Sitemap.xml for the public pages.
- [ ] In-app polish: reveals/tour on dashboard and minutes views.
