# Contribot (`contribot`)

[![CI](https://github.com/AshutoshAhirwal/contribot/actions/workflows/ci.yml/badge.svg)](https://github.com/AshutoshAhirwal/contribot/actions/workflows/ci.yml)
[![License: GPL v2+](https://img.shields.io/badge/License-GPL%20v2%2B-blue.svg)](LICENSE.txt)

**Contribot** is a developer-mode assistant that lives inside a Drupal site, understands that specific site deeply, and helps developers implement requirements the **"Drupal Way"** — **contrib-first**, **config-first**, and **patch-not-rewrite** — instead of blindly writing custom code like generic AI coding tools do.

It is a **bring-your-own-API-key (BYOK)** module: you connect your own Google Gemini, Anthropic Claude, or OpenAI API key, and every request goes directly from your server to that provider. Nothing is proxied through the module maintainer.

## ⚠️ Before you install this

Contribot can **propose and, with your explicit approval, apply mutations to a live Drupal site's configuration and codebase** (content types, taxonomy vocabularies, roles, contrib module installs with patches, and generated custom code). Every mutation is human-approved, snapshotted, and revertible — see [SECURITY.md](SECURITY.md) for exactly what it can do and what gates each capability — but you are responsible for:

- **Testing on a staging/local environment first**, not a production site, especially while you're still learning how it behaves.
- Reviewing what your chosen LLM provider does with the data described in [PRIVACY.md](PRIVACY.md) before enabling it on a site with sensitive data.

This software is distributed **WITHOUT ANY WARRANTY**, to the extent permitted by law, per GPL sections 15 and 16 (see [LICENSE.txt](LICENSE.txt)) — there is no guarantee it is fit for any particular purpose, and the maintainer is not liable for damages arising from its use.

---

## Key Principles & Architecture

1. **Contrib-First Thinking**:
   If a maintained contrib module covers most of a requirement, `contribot` recommends installing that module plus a scoped patch for the gap rather than building custom code.

2. **Config vs. Code Judgment**:
   Many Drupal requirements can be solved by Configuration Management alone (fields, content types, view modes, permissions, Views, workflows). `contribot` prioritizes exportable YAML configuration over unnecessary custom PHP.

3. **Reusing Ecosystem Infrastructure**:
   Built on top of **`drupal/tool`** (Tool API) for its site-inspection tool plugins, and the **Key** module for credential storage, rather than reinventing plugin discovery or secret management.

4. **Human-in-the-Loop Safe Mutation & Audit Approval**:
   No change is ever applied automatically. Every proposal displays reasoning, live diff previews, validation status (*Ready to Apply* vs *Needs Manual Review*), and explicit **[Apply Changes]**, **[Edit]**, and **[Revert Last Change]** action buttons.

---

## Component Architecture

- **`SiteContextAssembler`**: Packages site core version, active modules, composer locks, custom module inventories, and scoped config schema into token-budgeted prompt context (capped at 32k tokens).
- **`ContribIndexerService` & `ContribMatcherService`**: Populates local database index `{contribot_contrib_index}` from drupal.org API. Filters candidates via pure PHP Semver `Composer\Semver\Semver::satisfies()`, followed by Min-Max normalized hybrid DB/LLM relevance scoring.
- **`ContribSourceFetcherService`**: Dynamically downloads exact candidate release source code into `private://contribot/staging/{module}` at patch generation time.
- **`PatchValidatorService` & `PhpCodeValidatorService`**: Executes dry-run `git apply --check` and `php -l` / `phpcs` checks using type-safe Symfony Process array arguments prior to presenting proposals, and again immediately before anything is written to disk.
- **`ComposerPatchManagerService`**: Saves validated `.patch` files into `private://contribot/patches/`, registers them in root `composer.json`'s `extra.patches` section, and enqueues background `composer update` jobs via Drupal Queue API.
- **`SnapshotManagerService`**: Creates pre-mutation configuration snapshots (`config_before.json`, covering the site's full config, not a partial slice) and file backups in `private://contribot/snapshots/`. Uninstalls newly installed modules and restores active configuration on revert.
- **`MutationLockManagerService`**: Acquires exclusive locks via Drupal Lock API (`\Drupal::lock()`) with key `contribot_mutation_lock` to prevent concurrent mutation race conditions (covers both apply and revert).
- **`EnvironmentDetectorService`**: Detects production environments (`Settings::get('environment') === 'production'`) and hard-disables mutations by default.
- **`DataPrivacyManagerService`**: Enforces `structure_only` privacy (stripping node content, user records, and field values) and renders an inspectable `[View Payload]` preview toggle.

---

## Requirements

- Drupal core `^10.3 || ^11.0`
- PHP `>=8.3`
- [Key](https://www.drupal.org/project/key) module (stores your LLM provider API key)
- [Tool](https://www.drupal.org/project/tool) module (site-inspection tool plugin base)
- An API key from one of: [Google AI Studio](https://aistudio.google.com/app/apikey) (Gemini), [Anthropic Console](https://console.anthropic.com/) (Claude), or [OpenAI Platform](https://platform.openai.com/api-keys) — **not** a consumer chat subscription (Gemini Advanced / Claude Pro / ChatGPT Plus). API access is billed and provisioned separately from those subscriptions.

### Tested versions

Manually verified in this repository's own DDEV environment on **Drupal 11.3 / PHP 8.4**. Continuous integration additionally runs the test suite against **Drupal 10.3.x-dev** and **11.1.x-dev** on **PHP 8.3 and 8.4** — see [`.github/workflows/ci.yml`](.github/workflows/ci.yml) and the CI badge above for current status. If you run it on a different combination within the `composer.json` constraints, please report results via an issue.

---

## Configuration & Usage

This is the exact sequence to go from a fresh install to a working chat session.

1. **Install the module and its dependencies:**
   ```
   composer require drupal/contribot
   drush en contribot key tool -y
   ```

2. **Add your LLM provider's API key to the Key module:**
   - Go to `/admin/config/system/keys/add`.
   - Choose a **Key type** of *Authentication* (or *Unstructured text*), give it a machine name you'll recognize (e.g. `gemini_api_key`), paste your API key as the **Key value**, and save.

3. **Configure Contribot:**
   - Go to `/admin/config/development/contribot`.
   - Check **Enable Developer Mode**.
   - Choose a **Security Preset** — start with *Read-Only* while you're evaluating the module; move to *Config-Only* or *Full Mutation* once you're comfortable with what it proposes (see [SECURITY.md](SECURITY.md) for exactly what each preset unlocks).
   - Set **LLM Provider** to match the key you added (Gemini / Anthropic Claude / OpenAI) — this must be selected explicitly; it is not guessed from the key.
   - Enter the **Key Module Credential ID** you created in step 2 (e.g. `gemini_api_key`).
   - Leave **Model Name Override** blank to use the provider's default model, or set one explicitly.
   - Leave **Data Privacy Level** at *Structure Only* unless you specifically need *Full Context* (see [PRIVACY.md](PRIVACY.md) for what each level sends to your provider).
   - Save.

4. **Grant the permission:**
   - Go to `/admin/people/permissions` and grant **Use Contribot** to the roles that should see the assistant (this permission is deliberately marked "restrict access" and granted to no role by default).

5. **Use it:**
   - Reload any admin page. The **⚡ Contribot** drawer button appears for users with the permission.
   - Describe a requirement in plain language. Contribot will ask a clarifying question if needed, then recommend a `config_only`, `contrib_patch`, or `custom_code` path with reasoning.
   - Review the diff/YAML/code shown, then use **[Apply Changes]** to apply it (a rollback snapshot is taken first automatically) or **[Revert Last Change]** afterward if you change your mind.

If no provider is configured yet, the assistant runs in a clearly-labeled **Demo Mode**, returning template responses instead of real model output — useful for exploring the UI before you have a key handy, but never mistake it for a real recommendation.

---

## Screenshots

<!--
TODO before publishing: add real screenshots/GIF of the chat drawer in
action here. None are included yet — this repository doesn't have a
capture of the running UI to publish, and a placeholder image would be
misleading. A short screen recording of: opening the drawer, asking a
requirement, reviewing the diff, and applying + reverting a change is the
single biggest driver of whether someone tries a new Drupal module.
-->

*(Screenshots pending — see the note above.)*
