#!/bin/bash

echo "=== CREATING ORDER RECEIPT EMAIL TEMPLATE ==="
echo ""

# Read the template file
TEMPLATE_FILE="web/modules/custom/myeventlane_messaging/config/install/myeventlane_messaging.template.order_receipt.yml"
SUBJECT=$(grep "^subject:" "$TEMPLATE_FILE" | sed 's/^subject: //')
BODY_HTML=$(sed -n '/^body_html:/,/^utm:/p' "$TEMPLATE_FILE" | sed '1d;$d')

echo "Setting enabled..."
ddev exec "drush config:set myeventlane_messaging.template.order_receipt enabled true -y"

echo ""
echo "Setting subject..."
ddev exec "drush config:set myeventlane_messaging.template.order_receipt subject \"$SUBJECT\" -y"

echo ""
echo "Setting body_html (this may take a moment)..."
# Use a temporary file to pass the body content
TEMP_BODY=$(mktemp)
sed -n '/^body_html:/,/^utm:/p' "$TEMPLATE_FILE" | sed '1d;$d' > "$TEMP_BODY"
ddev exec "drush config:set myeventlane_messaging.template.order_receipt body_html \"$(cat $TEMP_BODY)\" -y"
rm "$TEMP_BODY"

echo ""
echo "Clearing cache..."
ddev exec "drush cr"

echo ""
echo "=== DONE ==="
echo ""
echo "Email template should now be configured."
echo ""







