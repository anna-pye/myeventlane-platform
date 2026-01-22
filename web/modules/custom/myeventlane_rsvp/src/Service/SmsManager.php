<?php

namespace Drupal\myeventlane_rsvp\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Twilio\Rest\Client;

/**
 *
 */
final class SmsManager {

  private bool $enabled;
  private string $provider;
  private array $twilio;

  public function __construct(
    ConfigFactoryInterface $config,
  ) {
    $c = $config->get('myeventlane_rsvp.sms_settings');
    $this->enabled = (bool) $c->get('enabled');
    $this->provider = (string) $c->get('provider');
    $this->twilio = [
      'sid' => $c->get('twilio_sid'),
      'token' => $c->get('twilio_token'),
      'from' => $c->get('twilio_from'),
    ];
  }

  /**
   *
   */
  public function send(string $to, string $msg): bool {
    if (!$this->enabled) {
      return FALSE;
    }
    if ($this->provider !== 'twilio') {
      return FALSE;
    }

    $client = new Client($this->twilio['sid'], $this->twilio['token']);
    $client->messages->create($to, [
      'from' => $this->twilio['from'],
      'body' => $msg,
    ]);

    return TRUE;
  }

}
