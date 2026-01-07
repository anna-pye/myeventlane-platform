<?php

/**
 * @file
 * Script to create order_receipt email template config.
 */

use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;

$autoloader = require_once 'autoload.php';
$kernel = DrupalKernel::createFromRequest(Request::createFromGlobals(), $autoloader, 'prod');
$kernel->boot();
$container = $kernel->getContainer();

$configFactory = $container->get('config.factory');
$config = $configFactory->getEditable('myeventlane_messaging.template.order_receipt');

$subject = 'Your tickets for {{ event_name|default("your event") }} – MyEventLane';

$body_html = <<<'HTML'
<div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; max-width: 600px; margin: 0 auto; background-color: #fef5ec; padding: 20px;">
  <div style="background-color: #ffffff; border-radius: 8px; padding: 30px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
    <div style="text-align: center; margin-bottom: 30px;">
      <h1 style="color: #2c3e50; margin: 0; font-size: 28px;">🎉 Thank you for your order!</h1>
    </div>

    <p style="color: #34495e; font-size: 16px; line-height: 1.6; margin-bottom: 20px;">
      Hi {{ first_name }},
    </p>

    <p style="color: #34495e; font-size: 16px; line-height: 1.6; margin-bottom: 20px;">
      Your order <strong>#{{ order_number }}</strong> has been confirmed. We've attached your calendar file(s) to this email.
    </p>

    {% if events|length > 0 %}
      <div style="margin: 30px 0;">
        <h2 style="color: #2c3e50; font-size: 20px; margin-bottom: 15px; border-bottom: 2px solid #fef5ec; padding-bottom: 10px;">Your Events</h2>
        {% for event in events %}
          <div style="background-color: #fef5ec; border-radius: 6px; padding: 20px; margin-bottom: 15px;">
            <h3 style="color: #2c3e50; font-size: 18px; margin: 0 0 10px 0;">{{ event.title }}</h3>
            {% if event.start_date %}
              <p style="color: #34495e; font-size: 14px; margin: 5px 0;">
                <strong>Date:</strong> {{ event.start_date }}
                {% if event.end_date %} - {{ event.end_date }}{% endif %}
              </p>
            {% endif %}
            {% if event.start_time %}
              <p style="color: #34495e; font-size: 14px; margin: 5px 0;">
                <strong>Time:</strong> {{ event.start_time }}
                {% if event.end_time %} - {{ event.end_time }}{% endif %}
              </p>
            {% endif %}
            {% if event.location %}
              <p style="color: #34495e; font-size: 14px; margin: 5px 0;">
                <strong>Location:</strong> {{ event.location }}
              </p>
            {% endif %}
          </div>
        {% endfor %}
      </div>
    {% endif %}

    {% if ticket_items|length > 0 %}
      <div style="margin: 30px 0;">
        <h2 style="color: #2c3e50; font-size: 20px; margin-bottom: 15px; border-bottom: 2px solid #fef5ec; padding-bottom: 10px;">Your Tickets</h2>
        {% for item in ticket_items %}
          <div style="background-color: #f9f9f9; border-radius: 6px; padding: 15px; margin-bottom: 10px;">
            <div style="display: flex; justify-content: space-between; align-items: start;">
              <div>
                <div style="font-weight: 600; color: #2c3e50; font-size: 16px; margin-bottom: 5px;">{{ item.title }}</div>
                <div style="color: #7f8c8d; font-size: 14px;">Quantity: {{ item.quantity }}</div>
                {% if item.attendees|length > 0 %}
                  <div style="margin-top: 10px; font-size: 14px; color: #34495e;">
                    <strong>Attendees:</strong>
                    <ul style="margin: 5px 0; padding-left: 20px;">
                      {% for attendee in item.attendees %}
                        <li>{{ attendee.name }}{% if attendee.email %} ({{ attendee.email }}){% endif %}</li>
                      {% endfor %}
                    </ul>
                  </div>
                {% endif %}
              </div>
              <div style="font-weight: 600; color: #2c3e50; font-size: 16px;">{{ item.price }}</div>
            </div>
          </div>
        {% endfor %}
      </div>
    {% endif %}

    {% if donation_total > 0 %}
      <div style="margin: 30px 0; background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; border-radius: 4px;">
        <h3 style="color: #856404; font-size: 18px; margin: 0 0 10px 0;">💝 Your Donation</h3>
        <p style="color: #856404; font-size: 16px; margin: 5px 0;">
          <strong>Donation Amount:</strong> {{ donation_total }}
        </p>
        <p style="color: #856404; font-size: 14px; margin: 10px 0 0 0;">
          Thank you for your generous donation! Your contribution helps support our platform and events.
        </p>
      </div>
    {% endif %}

    <div style="margin: 30px 0; padding: 20px; background-color: #f9f9f9; border-radius: 6px;">
      <div style="display: flex; justify-content: space-between; align-items: center;">
        <span style="font-weight: 600; color: #2c3e50; font-size: 18px;">Total Paid</span>
        <span style="font-weight: 700; color: #2c3e50; font-size: 20px;">{{ total_paid }}</span>
      </div>
    </div>

    <div style="text-align: center; margin: 30px 0;">
      <a href="{{ order_url }}" style="display: inline-block; background-color: #ff6b9d; color: #ffffff; text-decoration: none; padding: 12px 24px; border-radius: 6px; font-weight: 600; font-size: 16px;">
        View My Tickets
      </a>
    </div>

    <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #e0e0e0; text-align: center; color: #7f8c8d; font-size: 12px;">
      <p style="margin: 5px 0;">This email was sent to {{ order_email }}</p>
      <p style="margin: 5px 0;">If you have any questions, please contact us.</p>
    </div>
  </div>
</div>
HTML;

$config->set('enabled', TRUE);
$config->set('subject', $subject);
$config->set('body_html', $body_html);
$config->set('utm.enable', TRUE);
$config->set('utm.params.utm_source', 'email');
$config->set('utm.params.utm_medium', 'transactional');
$config->set('utm.params.utm_campaign', 'receipt');
$config->save();

echo "Order receipt email template created successfully!\n";







