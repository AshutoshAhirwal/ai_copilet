# Data Sent to Third-Party LLM Providers

AI Copilot is a **bring-your-own-API-key (BYOK)** module. When you configure a
provider (Google Gemini, Anthropic Claude, or OpenAI), every request from the
chat panel and evaluation pipeline is sent directly from your Drupal server
to that provider's API, using the credential you supplied via the Key module.
The module's maintainer never receives or proxies this traffic.

This page describes exactly what leaves your site, so you can make an
informed decision before enabling AI Copilot on a site with sensitive data —
this is a common early question for regulated, enterprise, and government
adopters.

## What is always sent

- Your chat prompts and follow-up messages, verbatim.
- The system prompt describing AI Copilot's decision-making rules.
- Candidate contrib module metadata (titles, descriptions, machine names)
  pulled from your local `{ai_copilot_contrib_index}` table, which is itself
  sourced from public drupal.org project pages.

## What is sent depending on your `data_privacy_level` setting

Configured at **AI Copilot Settings → Data Privacy & Outbound Payload
Filtering** (`/admin/config/development/ai-copilot`):

- **Structure Only (default, recommended)** — Sends entity/bundle machine
  names, field machine names and types, the list of active modules, and
  scoped configuration schema metadata. Node content, user records, field
  *values*, and any user-entered data are stripped before the request is
  built.
- **Full Context** — Additionally includes sampled content-entity structure.
  Even in this mode, AI Copilot does not intentionally include passwords,
  session data, or raw user account fields — but this mode sends more of
  your site's shape to the provider and should be used deliberately, not by
  default.

You can inspect the exact outbound JSON for any given request via the
**[View Payload]** toggle in the chat drawer before it is sent.

## What is never sent

- Anything AI Copilot doesn't explicitly assemble: raw database dumps,
  file contents outside of what a specific patch/validation step needs,
  or credentials other than the one API key used to authenticate the
  request itself.
- The API key itself is never included in prompt content — it is used only
  as transport-layer authentication (an HTTP header) to the provider.

## Your provider's own data policy applies

Once a request reaches Google, Anthropic, or OpenAI, **their** data
retention, training-use, and privacy policies govern it — this module has no
control over that. If this matters for your compliance requirements, review
your chosen provider's API (not consumer-product) data policy directly:
API-tier usage is typically governed by separate terms from consumer chat
products (e.g. Gemini API vs. Gemini Advanced, or the Anthropic API vs.
claude.ai), and terms differ by provider and by plan.

## Related

- See [SECURITY.md](SECURITY.md) for access control, mutation safeguards,
  and rollback behavior.
- See the README's [Configuration & Usage](README.md#configuration--usage)
  section for where the privacy level setting lives.
