# MyEventLane Auth

Branded login, registration, and password-reset UX for MyEventLane. Works with Gin Login; no role assignment on registration. Account type is stored in `field_mel_account_type` (customer/organiser).

## Install

```bash
ddev drush en myeventlane_auth -y
ddev drush cr
```

## 8-step test checklist

1. **Login page** — Open `/user/login`. Confirm MEL card styling, “Email or username” label, description “Use the email you signed up with.”, “Log in” button, and a “Show” button next to the password field that toggles to “Hide”.
2. **Login Show/Hide** — Click “Show”; password is visible. Click “Hide”; password is masked. Button has `aria-pressed` and accessible label.
3. **Register page** — Open `/user/register`. Confirm no username, picture, contact, timezone, path, or language. Account type radios: “I’m attending events” / “I’m running events”. Submit label “Create account”.
4. **Register flow** — Submit with a new email and “I’m running events”. User is created; username is email local part (sanitised); `field_mel_account_type` = organiser; no vendor/organiser role assigned.
5. **Password reset** — Open `/user/password`. Confirm “Send reset link” and MEL styling.
6. **Auth library scope** — Open homepage or an event page. In DevTools, confirm `auth-pages.css` and `auth-pages.js` are **not** loaded (auth assets only on login/register/password).
7. **Programmatic user** — Create a user via Drush or code without setting `field_mel_account_type`. After save, field should be `customer` (presave default).
8. **Error summary** — Trigger a validation error on login or register. Error block has rounded 16px, soft red background, clear heading.

## Expected markup (wrapper classes)

- Form: `form.mel-auth-form.mel-auth-form-wrapper.mel-form--login` (or `--register`, `--password`).
- Password wrapper: `.form-item.mel-auth-password-wrapper` with `input.mel-auth-password-input` and `button.mel-auth-password-toggle`.
- Body/context: `.path-user` on user pages; Gin may add `.gin-login` or similar. Card is scoped under `.gin-login` / `.path-user` with max-width 560px.

## Rollback

```bash
ddev drush pmu myeventlane_auth -y
ddev drush cr
```

Remove the `auth_pages` library and route/path attachment from the theme if you want to fully revert styling.
