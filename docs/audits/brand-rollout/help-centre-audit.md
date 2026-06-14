# Help Centre Audit

**Brand rollout:** The Hidden Gem + The Guide (Bright Edition)
**Audit date:** 2026-06-14
**Method:** Evidence-based.

---

## 1. Modules (evidence)

| Module | Role |
|---|---|
| `myeventlane_help_centre` | Core Help Centre: routes, controllers, indexes, search, feedback, insights |
| `myeventlane_help_centre_ai` | AI layer for help centre |
| `myeventlane_help_assistant` | **Conversational AI assistant** (`/help/assistant`) — grounded Q&A |
| `myeventlane_help_improvement` | Feedback-driven content improvement loop |
| `myeventlane_help_shared` | Shared help services |
| `myeventlane_support`, `myeventlane_support_console` | Support / escalation surfaces |

---

## 2. Structure & routes (`myeventlane_help_centre.routing.yml`)

| Path | Controller | Purpose |
|---|---|---|
| `/help` | `HelpCentreController::homepage` | Help Centre home |
| `/help/index` | `::publicIndex` | Full index |
| `/help/attendees` | `::attendeesIndex` | **Audience-segmented** |
| `/help/organisers` | `::organisersIndex` | Audience-segmented |
| `/help/vendors` | `::vendorsIndex` | Audience-segmented |
| `/help/policies` | `::policiesIndex` | Policies & Trust |
| `/help/category/{category}` | `::categoryIndex` | Category browse |
| `/help/search` | `::searchIndex` | **Search** (Search API backed) |
| `/help/article/{node}/feedback` | `HelpFeedbackController::submitFeedback` | Article feedback loop |
| `/vendor/help`, `/vendor/help/topic/{category}` | `::vendorHelp` / `::vendorTopic` | Vendor-domain help |
| `/help/assistant` (GET) | `HelpAssistantController::page` | **AI assistant page** |
| `/help/assistant` (POST) | `HelpAssistantController::ask` | **AI assistant ask** |
| `/admin/mel/help-insights` | `HelpInsightsController::dashboard` | Staff analytics |
| `/api/help-action/{action}` | `HelpActionController::run` | Help actions API |

---

## 3. Content model

Node types (`config/sync/node.type.*.yml`): **`help_article`**, **`help_landing_page`**, `faq`, `support_procedure`, `staff_playbook`. Help content is structured Drupal content — categorised, audience-tagged, feedback-enabled.

---

## 4. Search

`/help/search` → `HelpCentreController::searchIndex` → `countSearchResults()` over the **Search API** index. Supporting Views: `mel_help_search`, `mel_help_category_listing`, `mel_help_featured_articles`, `mel_help_top_searches`. Chip-filtered help search UI (`_help-search.scss`, library `help_search`).

---

## 5. Existing AI integration — **the foundation for "Ask the Guide"**

`myeventlane_help_assistant` already implements a grounded, guard-railed conversational assistant:

| Capability | Evidence |
|---|---|
| Conversational endpoint | `/help/assistant` GET page + POST `ask` (`HelpAssistantController`) |
| **Grounded retrieval (RAG)** | `HelpAssistantService` builds prompt from Help Centre **search results** + `retrievalConfidence`; settings form: *"grounded Help Centre search and MyEventLane AI. Deterministic rules always run first."* |
| Prompt governance | `myeventlane_ai\Value\PromptDefinition` (versioned, hashed prompts: `mel.event_suggestions.empty v2`, etc.) |
| AI orchestration | `myeventlane_ai\Service\AiManager` |
| Provider | `myeventlane_ai/src/Provider/OpenAiProvider.php` — OpenAI-compatible Chat Completions; default model `gpt-4o-mini`; key via `MEL_OPENAI_API_KEY` env |
| Safety | `AiUsageGuardrailsForm`, circuit breaker (`isTripped('openai')`), max-tokens/timeout caps |
| **Event suggestions already exist** | `EventSuggestionEngine` — assistant can already suggest *events*, not just articles (prompt `mel.event_suggestions`) |
| Reporting | `/admin/reports/myeventlane/help-assistant` + help insights dashboard |

> **`EventSuggestionEngine` is a standout finding:** the help assistant **already blends help answers with event suggestions** using grounded retrieval + AI. That is functionally a proto-"Ask the Guide."

---

## 6. Can the Help Centre evolve into "Ask the Guide" **without architecture changes**?

**Yes.** Evidence-based assessment:

| Requirement for "Ask the Guide" | Already present? | Gap |
|---|---|---|
| Conversational Q&A endpoint | ✅ `/help/assistant` | Re-brand surface/persona |
| Grounded retrieval over content | ✅ Help search → prompt grounding | Optionally widen grounding to event/discovery data |
| Event recommendation capability | ✅ `EventSuggestionEngine` + (Phase 5) `EventRecommendationService` | Connect the two; widen prompts |
| Prompt governance & versioning | ✅ `PromptDefinition` | New Guide-persona prompts |
| Safety / cost controls | ✅ guardrails + circuit breaker | None |
| Provider abstraction | ✅ `AiProviderInterface` / `OpenAiProvider` | None (provider swap is config, not architecture) |
| Analytics / feedback loop | ✅ insights + feedback controllers | None |

**Conclusion:** "Ask the Guide" is achievable as a **persona + prompt + branding + grounding-scope** change on top of `myeventlane_help_assistant`. **No new architecture is required.** The biggest *content/UX* decision (not architecture) is whether the Guide answers (a) help/support questions only, or (b) help **and** discovery ("what should I do this weekend?") — the `EventSuggestionEngine` shows (b) is already partially built.

---

## 7. Verdicts

| Verdict | Item |
|---|---|
| **SAFE TO REUSE** | All help routes/content model, Search API help search, `HelpAssistantService`, `AiManager`, `PromptDefinition`, `EventSuggestionEngine`, guardrails, feedback/insights |
| **NEEDS EVOLUTION** | Assistant surface branding → "Ask the Guide"; Guide-persona prompts; re-skin help UI to Bright Edition; optionally widen grounding to discovery data |
| **DON'T TOUCH** | AI guardrails/circuit-breaker, prompt-hash governance, access/permissions |
| **DECIDE (team)** | Guide scope: support-only vs support+discovery; AI provider/model choice (config-level — current default `gpt-4o-mini` via OpenAI-compatible provider) |

**Bottom line:** The Help Centre is the **second-best-positioned surface** (after the event page) for The Guide. A grounded, guard-railed AI assistant that can already suggest events exists today. "Ask the Guide" is re-branding + new prompts + grounding scope — not re-architecture.
