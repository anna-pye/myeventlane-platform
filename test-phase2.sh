#!/bin/bash

echo "=========================================="
echo "Phase 2 Testing Script"
echo "=========================================="
echo ""

# Colors for output
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${YELLOW}Step 1: Checking module status...${NC}"
if ddev drush pm:list --status=enabled --type=module | grep -q "myeventlane_vendor"; then
    echo -e "${GREEN}✓ Module is enabled${NC}"
else
    echo -e "${RED}✗ Module is not enabled${NC}"
    exit 1
fi

echo ""
echo -e "${YELLOW}Step 2: Checking vendor fields...${NC}"
ddev drush php:eval "
\$fields = \Drupal::service('entity_field.manager')->getFieldDefinitions('myeventlane_vendor', 'myeventlane_vendor');
\$expected = ['field_vendor_bio', 'field_vendor_logo', 'field_vendor_users', 'field_vendor_store', 'field_public_show_email', 'field_public_show_phone', 'field_public_show_location'];
\$found = [];
foreach (\$fields as \$name => \$field) {
    if (in_array(\$name, \$expected)) {
        \$found[] = \$name;
    }
}
echo 'Expected fields: ' . implode(', ', \$expected) . PHP_EOL;
echo 'Found fields: ' . implode(', ', \$found) . PHP_EOL;
if (count(\$found) === count(\$expected)) {
    echo 'SUCCESS: All vendor fields found!' . PHP_EOL;
} else {
    echo 'WARNING: Some fields missing. Run: ddev drush config:import -y && ddev drush cr' . PHP_EOL;
}
"

echo ""
echo -e "${YELLOW}Step 3: Checking Commerce Store Stripe fields...${NC}"
ddev drush php:eval "
\$fields = \Drupal::service('entity_field.manager')->getFieldDefinitions('commerce_store', 'online');
\$expected = ['field_stripe_account_id', 'field_stripe_onboard_url', 'field_stripe_dashboard_url', 'field_stripe_connected', 'field_stripe_charges_enabled', 'field_stripe_payouts_enabled', 'field_vendor_reference'];
\$found = [];
foreach (\$fields as \$name => \$field) {
    if (in_array(\$name, \$expected)) {
        \$found[] = \$name;
    }
}
echo 'Expected fields: ' . implode(', ', \$expected) . PHP_EOL;
echo 'Found fields: ' . implode(', ', \$found) . PHP_EOL;
if (count(\$found) === count(\$expected)) {
    echo 'SUCCESS: All store Stripe fields found!' . PHP_EOL;
} else {
    echo 'WARNING: Some fields missing. Run: ddev drush config:import -y && ddev drush cr' . PHP_EOL;
}
"

echo ""
echo -e "${YELLOW}Step 4: Checking event subscriber registration...${NC}"
if ddev drush config:get myeventlane_vendor.services.yml 2>&1 | grep -q "vendor_store_subscriber"; then
    echo -e "${GREEN}✓ Event subscriber is registered${NC}"
else
    echo -e "${RED}✗ Event subscriber not found${NC}"
fi

echo ""
echo -e "${YELLOW}Step 5: Checking pathauto pattern...${NC}"
if ddev drush config:get pathauto.pattern.myeventlane_vendor 2>&1 | grep -q "pattern"; then
    echo -e "${GREEN}✓ Pathauto pattern configured${NC}"
    ddev drush config:get pathauto.pattern.myeventlane_vendor pattern
else
    echo -e "${YELLOW}⚠ Pathauto pattern not found (may need to enable pathauto module)${NC}"
fi

echo ""
echo -e "${YELLOW}Step 6: Testing database tables...${NC}"
if ddev drush sqlq "SHOW TABLES LIKE 'myeventlane_vendor%'" | grep -q "myeventlane_vendor"; then
    echo -e "${GREEN}✓ Vendor tables exist${NC}"
    VENDOR_COUNT=$(ddev drush sqlq "SELECT COUNT(*) FROM myeventlane_vendor" | tail -1)
    echo "  Current vendor count: $VENDOR_COUNT"
else
    echo -e "${RED}✗ Vendor tables not found${NC}"
fi

echo ""
echo -e "${YELLOW}Step 7: Checking form handler...${NC}"
FORM_HANDLER=$(ddev drush config:get core.entity_type.myeventlane_vendor handlers.form.default 2>&1 | tail -1)
if echo "$FORM_HANDLER" | grep -q "VendorForm"; then
    echo -e "${GREEN}✓ Custom VendorForm is configured${NC}"
else
    echo -e "${YELLOW}⚠ Using default ContentEntityForm (may need config import)${NC}"
fi

echo ""
echo "=========================================="
echo -e "${GREEN}Testing Complete!${NC}"
echo "=========================================="
echo ""
echo "Next steps:"
echo "1. If fields are missing, run: ddev drush config:import -y && ddev drush cr"
echo "2. Test creating a vendor at: /admin/structure/myeventlane/vendor/add"
echo "3. Verify store auto-creation works"
echo "4. Check form sections are organized correctly"
echo ""




















