# Drupal AI Copilot (`ai_copilot`)

**Drupal AI Copilot** is a developer-mode assistant that lives inside a Drupal site, understands that specific site deeply, and helps developers implement requirements the **"Drupal Way"** — **contrib-first**, **config-first**, and **patch-not-rewrite** — instead of blindly writing custom code like generic AI coding tools do.

---

## Key Principles & Architecture

1. **Contrib-First Thinking**:
   If a maintained contrib module covers $\ge 80\%$ of a requirement, `ai_copilot` recommends installing that module plus a scoped patch for the gap rather than building custom code.

2. **Config vs. Code Judgment**:
   Many Drupal requirements can be solved by Configuration Management alone (fields, content types, view modes, permissions, Views, workflows). `ai_copilot` prioritizes exportable YAML configuration over unnecessary custom PHP.

3. **Reusing Ecosystem Infrastructure**:
   Built directly on top of **`drupal/tool`** (Tool API) and **`drupal/mcp_tools`** (222+ site introspection & mutation plugins). It does not reinvent site inspection or entity CRUD.

4. **Human-in-the-Loop Safe Mutation & Audit Approval**:
   No change is ever applied automatically. Every proposal displays reasoning, live diff previews, validation status (*Ready to Apply* vs *Needs Manual Review*), and explicit **[Apply Changes]**, **[Edit]**, and **[Revert Last Change]** action buttons.

---

## Component Architecture

- **`SiteContextAssembler`**: Packages site core version, active modules, composer locks, custom module inventories, and scoped config schema into token-budgeted prompt context (capped at 32k tokens).
- **`ContribIndexerService` & `ContribMatcherService`**: Populates local database index `{ai_copilot_contrib_index}` from drupal.org API. Filters candidates via pure PHP Semver `Composer\Semver\Semver::satisfies()`, followed by Min-Max normalized hybrid DB/LLM relevance scoring.
- **`ContribSourceFetcherService`**: Dynamically downloads exact candidate release source code into `private://ai_copilot/staging/{module}` at patch generation time.
- **`PatchValidatorService` & `PhpCodeValidatorService`**: Executes dry-run `git apply --check` and `php -l` / `phpcs` checks using type-safe Symfony Process array arguments prior to presenting proposals.
- **`ComposerPatchManagerService`**: Saves validated `.patch` files into `private://ai_copilot/patches/`, registers them in root `composer.json`'s `extra.patches` section, and enqueues background `composer update` jobs via Drupal Queue API.
- **`SnapshotManagerService`**: Creates pre-mutation configuration snapshots (`config_before.json`) and file backups in `private://ai_copilot/snapshots/`. Uninstalls newly installed modules and restores active configuration on revert.
- **`MutationLockManagerService`**: Acquires exclusive locks via Drupal Lock API (`\Drupal::lock()`) with key `ai_copilot_mutation_lock` to prevent concurrent mutation race conditions.
- **`EnvironmentDetectorService`**: Detects production environments (`Settings::get('environment') === 'production'`) and hard-disables mutations by default.
- **`DataPrivacyManagerService`**: Enforces `structure_only` privacy (stripping node content, user records, and field values) and renders an inspectable `[View Payload]` preview toggle.

---

## Configuration & Usage

1. Navigate to `/admin/config/development/ai-copilot`.
2. Enable **Developer Mode**.
3. Select your desired Security Preset (*Read-Only*, *Config-Only*, or *Full Mutation*).
4. Enter your Key module credential ID for API keys.
5. Save settings. The slide-over **⚡ AI Copilot** drawer will appear in the admin UI for users with the `use ai copilot` permission.
