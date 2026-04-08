<?php

declare(strict_types=1);

namespace Drupal\myeventlane_auth\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Administrative settings for MyEventLane SSO.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_auth_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['myeventlane_auth.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('myeventlane_auth.settings');

    $form['auth_base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Auth site base URL'),
      '#description' => $this->t('Must be the base URL of this Drupal site (same codebase as the public site). Example: https://staging.myeventlane.com.au. A separate hostname (e.g. auth.) only works if the web server serves this same installation there; otherwise /auth/login returns 404. Leave empty to use myeventlane_core public_domain. Used for vendor SSO redirects and as default JWT issuer when JWT issuer is empty.'),
      '#default_value' => $config->get('auth_base_url'),
      '#required' => FALSE,
    ];

    $form['jwt'] = [
      '#type' => 'details',
      '#title' => $this->t('JWT access tokens (iss / aud)'),
      '#open' => TRUE,
    ];
    $form['jwt']['jwt_issuer'] = [
      '#type' => 'textfield',
      '#title' => $this->t('JWT issuer (iss)'),
      '#description' => $this->t('Optional. When set, access tokens include this iss and validation requires a match. Leave empty to derive from Auth site base URL when that field is set.'),
      '#default_value' => $config->get('jwt_issuer') ?? '',
    ];
    $form['jwt']['jwt_audience'] = [
      '#type' => 'textfield',
      '#title' => $this->t('JWT audience (aud)'),
      '#description' => $this->t('Optional. When set, access tokens include this aud claim and validation requires a match. Recommended for production (e.g. myeventlane).'),
      '#default_value' => $config->get('jwt_audience') ?? '',
    ];

    $form['token_ttl'] = [
      '#type' => 'details',
      '#title' => $this->t('Token lifetimes'),
      '#open' => TRUE,
    ];
    $form['token_ttl']['access_token_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Access token TTL (seconds)'),
      '#min' => 300,
      '#max' => 900,
      '#default_value' => $config->get('access_token_ttl'),
    ];
    $form['token_ttl']['refresh_token_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Refresh token TTL (seconds)'),
      '#min' => 86400,
      '#max' => 7776000,
      '#default_value' => $config->get('refresh_token_ttl'),
    ];
    $form['token_ttl']['authorization_code_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Authorization code TTL (seconds)'),
      '#min' => 60,
      '#max' => 3600,
      '#default_value' => $config->get('authorization_code_ttl'),
    ];

    $form['cookie'] = [
      '#type' => 'details',
      '#title' => $this->t('Refresh cookie (auth host)'),
      '#open' => FALSE,
    ];
    $form['cookie']['refresh_cookie_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Cookie name'),
      '#default_value' => $config->get('refresh_cookie_name'),
      '#required' => TRUE,
    ];
    $form['cookie']['refresh_cookie_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Cookie path'),
      '#default_value' => $config->get('refresh_cookie_path'),
      '#required' => TRUE,
    ];
    $form['cookie']['refresh_cookie_domain'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Cookie Domain (optional)'),
      '#description' => $this->t('For cross-subdomain refresh cookies, set the registrable domain (e.g. .staging.myeventlane.com.au). Leave empty for host-only cookies.'),
      '#default_value' => $config->get('refresh_cookie_domain') ?? '',
    ];

    $form['allowlists'] = [
      '#type' => 'details',
      '#title' => $this->t('Allowlists'),
      '#open' => TRUE,
    ];
    $form['allowlists']['allowed_redirect_hosts'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Allowed redirect_uri hosts'),
      '#description' => $this->t('One hostname per line (no scheme). Merged with myeventlane_core domain hosts when that module is enabled.'),
      '#default_value' => implode("\n", $config->get('allowed_redirect_hosts') ?: []),
    ];
    $form['allowlists']['allowed_cors_origins'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Allowed CORS origins'),
      '#description' => $this->t('Full Origin URLs, one per line (e.g. https://vendor.staging.myeventlane.com.au).'),
      '#default_value' => implode("\n", $config->get('allowed_cors_origins') ?: []),
    ];

    $form['vendor_sso'] = [
      '#type' => 'details',
      '#title' => $this->t('Vendor SSO redirect (optional)'),
      '#open' => TRUE,
    ];
    $form['vendor_sso']['vendor_sso_redirect_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Redirect anonymous /vendor/* traffic to auth login'),
      '#default_value' => (bool) $config->get('vendor_sso_redirect_enabled'),
    ];
    $form['vendor_sso']['vendor_sso_client_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Vendor OAuth client ID'),
      '#default_value' => $config->get('vendor_sso_client_id'),
    ];
    $form['vendor_sso']['vendor_sso_callback_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Vendor callback path'),
      '#default_value' => $config->get('vendor_sso_callback_path'),
    ];
    $form['vendor_sso']['vendor_sso_success_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Post-SSO redirect path (vendor host)'),
      '#description' => $this->t('Must be a site-relative path starting with / (e.g. /vendor/dashboard). External URLs are not allowed.'),
      '#default_value' => $config->get('vendor_sso_success_path'),
    ];
    $form['vendor_sso']['vendor_sso_redirect_hosts'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Extra vendor hostnames'),
      '#description' => $this->t('One per line. Vendor domain from myeventlane_core is included automatically when present.'),
      '#default_value' => implode("\n", $config->get('vendor_sso_redirect_hosts') ?: []),
    ];

    $form['oauth'] = [
      '#type' => 'details',
      '#title' => $this->t('OAuth client (confidential)'),
      '#description' => $this->t('For production, also set $settings["myeventlane_auth"]["client_secrets"] in settings.php for server-side exchanges (vendor callback).'),
      '#open' => TRUE,
    ];
    $form['oauth']['oauth_client_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client ID'),
      '#default_value' => array_key_first($config->get('oauth_clients') ?: []) ?: 'vendor',
    ];
    $form['oauth']['oauth_client_secret'] = [
      '#type' => 'password',
      '#title' => $this->t('New client secret'),
      '#description' => $this->t('Leave blank to keep the existing hash. When set, replaces the stored password_hash for this client.'),
    ];
    $form['oauth']['oauth_redirect_prefixes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Redirect URI prefixes for this client'),
      '#description' => $this->t('One prefix per line (scheme + host + optional path prefix).'),
      '#default_value' => '',
    ];

    $firstId = array_key_first($config->get('oauth_clients') ?: []);
    if (is_string($firstId) && $firstId !== '') {
      $client = $config->get('oauth_clients')[$firstId];
      $prefixes = $client['redirect_uri_prefixes'] ?? [];
      $form['oauth']['oauth_redirect_prefixes']['#default_value'] = is_array($prefixes)
        ? implode("\n", $prefixes)
        : '';
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $hosts = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $form_state->getValue('allowed_redirect_hosts')))));
    $origins = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $form_state->getValue('allowed_cors_origins')))));
    $vendorHosts = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $form_state->getValue('vendor_sso_redirect_hosts')))));

    $clientId = trim((string) $form_state->getValue('oauth_client_id'));
    $secret = (string) $form_state->getValue('oauth_client_secret');
    $prefixLines = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $form_state->getValue('oauth_redirect_prefixes')))));

    $editable = $this->configFactory->getEditable('myeventlane_auth.settings');
    $editable
      ->set('auth_base_url', trim((string) $form_state->getValue('auth_base_url')))
      ->set('jwt_issuer', trim((string) $form_state->getValue('jwt_issuer')))
      ->set('jwt_audience', trim((string) $form_state->getValue('jwt_audience')))
      ->set('access_token_ttl', (int) $form_state->getValue('access_token_ttl'))
      ->set('refresh_token_ttl', (int) $form_state->getValue('refresh_token_ttl'))
      ->set('authorization_code_ttl', (int) $form_state->getValue('authorization_code_ttl'))
      ->set('refresh_cookie_name', trim((string) $form_state->getValue('refresh_cookie_name')))
      ->set('refresh_cookie_path', trim((string) $form_state->getValue('refresh_cookie_path')))
      ->set('refresh_cookie_domain', trim((string) $form_state->getValue('refresh_cookie_domain')))
      ->set('vendor_sso_redirect_enabled', (bool) $form_state->getValue('vendor_sso_redirect_enabled'))
      ->set('vendor_sso_client_id', trim((string) $form_state->getValue('vendor_sso_client_id')))
      ->set('vendor_sso_callback_path', trim((string) $form_state->getValue('vendor_sso_callback_path')))
      ->set('vendor_sso_success_path', trim((string) $form_state->getValue('vendor_sso_success_path')))
      ->set('vendor_sso_redirect_hosts', $vendorHosts)
      ->set('allowed_redirect_hosts', $hosts)
      ->set('allowed_cors_origins', $origins);

    $clients = $editable->get('oauth_clients') ?: [];
    if (!is_array($clients)) {
      $clients = [];
    }
    if ($clientId !== '') {
      $row = $clients[$clientId] ?? ['secret_hash' => '', 'redirect_uri_prefixes' => []];
      if (!is_array($row)) {
        $row = ['secret_hash' => '', 'redirect_uri_prefixes' => []];
      }
      if ($secret !== '') {
        $row['secret_hash'] = password_hash($secret, PASSWORD_DEFAULT);
      }
      $row['redirect_uri_prefixes'] = $prefixLines;
      $clients[$clientId] = $row;
    }
    $editable->set('oauth_clients', $clients)->save();

    parent::submitForm($form, $form_state);
  }

}
