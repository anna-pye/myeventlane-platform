# Next Steps - Theme Improvements & Testing

## Immediate Actions (Do Now)

### 1. Test Admin Dashboard Fix ✅
- [x] Fixed route name from `myeventlane_vendor.collection` to `entity.myeventlane_vendor.collection`
- [ ] **Verify:** Visit `/admin/myeventlane` and confirm it loads without errors
- [ ] **Verify:** Click "Manage Vendors" link and confirm it works

### 2. Build Theme Assets
```bash
cd web/themes/custom/myeventlane_theme
ddev npm run build
```
- [ ] Verify build completes without errors
- [ ] Check that `dist/main.css` and `dist/main.js` are generated

### 3. Clear Drupal Cache
```bash
ddev drush cr
```
- [ ] Cache cleared successfully

---

## Theme Testing Checklist

### Menu System
- [ ] **Configure Main Menu Block:**
  - Go to `/admin/structure/block`
  - Find "Main navigation" block
  - Place it in the "Header" region
  - Save configuration

- [ ] **Test Desktop Navigation:**
  - View site on desktop (768px+)
  - Verify menu items appear in header
  - Click menu items - they should work
  - Verify active page is highlighted

- [ ] **Test Mobile Navigation:**
  - View site on mobile (< 768px)
  - Click hamburger menu button
  - Menu should slide in from right
  - Test all menu links
  - Test close button (×)
  - Test clicking overlay closes menu
  - Test Escape key closes menu
  - Test keyboard navigation (Tab, Shift+Tab)

### Header Component
- [ ] Logo links to homepage
- [ ] Account dropdown works (if logged in)
- [ ] Shopping cart displays (if items in cart)
- [ ] "Create Event" button works
- [ ] Header is sticky (stays at top when scrolling)

### Accessibility
- [ ] All buttons/links are minimum 44px × 44px
- [ ] Focus indicators visible on all interactive elements
- [ ] Keyboard navigation works throughout
- [ ] Screen reader tested (optional but recommended)
- [ ] Skip link works (jumps to main content)

### Spacing & Layout
- [ ] No visual regressions
- [ ] Spacing looks consistent (8px grid)
- [ ] Mobile layout works correctly
- [ ] Desktop layout works correctly

---

## Cleanup Tasks (Optional but Recommended)

### Remove Duplicate Header Code
The following templates still have duplicate header markup. They should be updated to use the header include:

- [ ] `templates/page--front.html.twig`
- [ ] `templates/page--events.html.twig`
- [ ] `templates/page--calendar.html.twig`
- [ ] `templates/page--events--category.html.twig`

**Option 1:** Update each to use header include:
```twig
{% include '@myeventlane_theme/includes/header.html.twig' %}
```

**Option 2:** Let them inherit from `page.html.twig` if they don't need custom structure

---

## Verification Commands

### Quick Health Check
```bash
# Clear cache
ddev drush cr

# Check for PHP errors
ddev exec vendor/bin/phpcs web/modules/custom/myeventlane_admin_dashboard

# Check routing
ddev drush route:debug | grep myeventlane_vendor
```

### Test Menu Block Configuration
```bash
# Check if menu block is configured
ddev drush config:get block.block.myeventlane_theme_main_menu

# If not configured, you may need to place it via admin UI
```

---

## If Issues Arise

### Menu Not Showing
1. Check menu block is placed in Header region
2. Verify menu has items: `/admin/structure/menu/manage/main`
3. Check block visibility settings

### JavaScript Not Working
1. Check browser console for errors
2. Verify `dist/main.js` exists and is loaded
3. Check library is attached: `ddev drush config:get myeventlane_theme.libraries.yml`

### Styling Issues
1. Verify Vite build completed: `ddev npm run build`
2. Check `dist/main.css` exists
3. Clear browser cache
4. Check Drupal cache: `ddev drush cr`

### Route Errors
1. Clear cache: `ddev drush cr`
2. Check route exists: `ddev drush route:debug | grep [route_name]`
3. Verify module is enabled: `ddev drush pm:list | grep myeventlane`

---

## Documentation Review

- ✅ `THEME_AUDIT_REPORT.md` - Full audit findings
- ✅ `THEME_IMPROVEMENTS_SUMMARY.md` - What was changed
- ✅ `VERIFICATION_CHECKLIST.md` - Detailed testing checklist
- ✅ `NEXT_STEPS.md` - This file

---

## Priority Order

1. **Test admin dashboard fix** (5 min)
2. **Build theme assets** (2 min)
3. **Configure menu block** (5 min)
4. **Test navigation** (10 min)
5. **Test mobile menu** (10 min)
6. **Cleanup duplicate templates** (optional, 30 min)

**Total time:** ~30-45 minutes for essential testing

---

## Success Criteria

✅ Admin dashboard loads without errors  
✅ Main menu displays in header  
✅ Mobile menu works with keyboard navigation  
✅ All touch targets are 44px+  
✅ No console errors  
✅ No visual regressions  

---

**Last Updated:** 2024


















