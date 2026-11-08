# WORKSPACE RULES: WORDPRESS & DIVI 5 PLUGIN DEVELOPMENT

## 1. TOKEN CONSERVATION & AUDIT OVERRIDE (CRITICAL)
- **Zero Fluff:** Never write polite introductions, conclusions, or long explanations. Output ONLY brief technical steps and code.
- **Targeted Edits:** Modify ONLY the files explicitly requested in the current prompt. Do not refactor unrelated files.
- **No Blind Scanning:** Do not read the entire project directory on regular turns. Rely on the File Map below to know where classes/methods live.
- **OVERRIDE COMMAND ("Аудит кода"):** If the user writes **"Аудит кода"**, temporarily bypass the scanning restrictions: inspect all project files, remove duplicated logic (DRY principle), optimize performance, and report findings concisely.
- **Language:** Communicate with the user in Russian (concise and technical), keep code comments and variable names in English.

## 2. PROJECT FILE MAP (SINGLE SOURCE OF TRUTH)
To avoid duplicating functions, always respect this structure (`DiviProEngagement\` namespace):
- `divi-pro-engagement.php` -> Main entry, constants (`DPC_VERSION`, `DPC_PATH`, `DPC_URL`), PSR-4 autoloader.
- `includes/Core/Init.php` -> Hooks registration, conditional CSS/JS enqueueing.
- `includes/Core/Settings.php` -> Admin page, saving/retrieving options (`dpc_get_option()`).
- `includes/Core/Updater.php` -> GitHub auto-updater via `pre_set_site_transient_update_plugins`.
- `includes/Core/Shortcodes.php` -> Registers `[dpc_social_login]` and `[dpc_post_rating]`.
- `includes/Core/CustomLogin.php` -> Custom `wp-login.php` URL slug routing, 404 protection, and subscriber Admin Bar / `/wp-admin/` restriction.
- `includes/Modules/Comments/CommentsEngine.php` & `comments-template.php` -> Modern comments layout, top AJAX form (auto-approve for users, email-less moderation for guests), AJAX sorting, Likes/Dislikes, AntiSpam (Honeypot + Turnstile), Floating Nudge.
- `includes/Modules/PostRating/RatingEngine.php` -> Post rating logic (Stars & Likes/Dislikes), anti-fraud circular hash buffer, AJAX handler, Schema.org JSON-LD.
- `includes/Modules/Auth/` -> REST API OAuth endpoints, user creation, social providers (Google, Telegram, Discord, X/Twitter, Facebook).

## 3. VERSIONING & GITHUB RELEASE PROTOCOL (ON DEMAND ONLY)
- **Do NOT bump the plugin version automatically** on regular code edits.
- Change the version in `divi-pro-engagement.php` (header `* Version:` and `DPC_VERSION`), commit, push to `main`, and create a Git tag ONLY when the user gives a release command:
  - **"Релиз 3" (Patch):** Increment 3rd number (`1.0.0` -> `1.0.1`).
  - **"Релиз 2" (Minor):** Increment 2nd number, reset 3rd (`1.0.1` -> `1.1.0`).
  - **"Релиз 1" (Major):** Increment 1st number, reset 2nd & 3rd (`1.1.0` -> `2.0.0`).

## 4. ARCHITECTURE, SECURITY & PERFORMANCE
- **Environment:** WordPress 6.5+, PHP 8.1+, Divi 5. Start every PHP file with `declare(strict_types=1);` and `if ( ! defined( 'ABSPATH' ) ) exit;`.
- **Database:** No custom SQL tables. Use native `wp_comments`, `wp_commentmeta`, and `wp_postmeta`.
- **Security:** Sanitize inputs (`sanitize_text_field`, `absint`, `wp_unslash`), escape outputs (`esc_html`, `esc_attr`, `esc_url`), and verify Nonces (`check_ajax_referer`) on all AJAX/REST requests.
- **Cache & Divi 5 Compatibility:** All dynamic actions via AJAX/REST. Wrap JS in `jQuery(document).ready(function($){...})` with `$(document).on(...)` delegation. Disable frontend hooks when Divi Visual Builder is active (`et_core_is_fb_enabled()`).
