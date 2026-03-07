(function (Drupal, once) {
  "use strict";

  const SECTION_KEYS = ["overview", "dates", "tickets", "images", "description", "settings"];
  const SECTION_SELECTORS = {
    dates: [
      '[name^="field_event_start"]',
      '[name^="field_event_end"]',
      '[name^="date_time[field_event_start]"]',
      '[name^="date_time[field_event_end]"]',
    ],
    tickets: [
      '[name^="field_ticket"]',
      '[name^="field_tickets"]',
      '[name*="[price]"]',
      '[name*="[capacity]"]',
    ],
    images: [
      '[name^="field_event_image"]',
      '[name^="field_event_hero"]',
      '[name^="field_event_organizer_logo"]',
    ],
    description: [
      '[name^="body"]',
      '[name^="field_description"]',
      '[name^="field_event_description"]',
      '[name^="field_summary"]',
    ],
    settings: [
      '[name^="moderation_state"]',
      '[name^="status"]',
      '[name^="field_event_mode"]',
      '[name^="field_event_type"]',
      ".form-actions",
    ],
  };

  function getWrapper(node) {
    return node.closest(".js-form-wrapper, .js-form-item, details, .form-wrapper, fieldset");
  }

  function getSections(layout) {
    const sections = {};
    const content = layout.querySelector("[data-mel-editor-content]");
    if (!content) {
      return sections;
    }

    SECTION_KEYS.forEach((key) => {
      const section = content.querySelector('[data-mel-section="' + key + '"]');
      if (!section) {
        return;
      }
      sections[key] = section;
    });

    return sections;
  }

  function syncCommandCenterFromInspector(context) {
    const shell = context.closest(".vendor-workspace") || document;
    const ticketSource = shell.querySelector("[data-mel-health-ticket-percent]");
    const rsvpSource = shell.querySelector("[data-mel-health-rsvps]");
    const revenueSource = shell.querySelector("[data-mel-health-revenue]");

    const ticketTarget = shell.querySelector("[data-mel-cc-tickets]");
    const progressTarget = shell.querySelector("[data-mel-cc-progress]");
    const rsvpTarget = shell.querySelector("[data-mel-cc-rsvps]");
    const revenueTarget = shell.querySelector("[data-mel-cc-revenue]");

    if (ticketSource && ticketTarget) {
      const value = parseInt(ticketSource.getAttribute("data-mel-health-ticket-percent") || "0", 10);
      const normalized = Number.isNaN(value) ? 0 : Math.max(0, Math.min(100, value));
      ticketTarget.textContent = normalized + "%";
      if (progressTarget) {
        progressTarget.style.width = normalized + "%";
      }
    }

    if (rsvpSource && rsvpTarget) {
      rsvpTarget.textContent = rsvpSource.getAttribute("data-mel-health-rsvps") || "-";
    }

    if (revenueSource && revenueTarget) {
      const value = revenueSource.getAttribute("data-mel-health-revenue");
      revenueTarget.textContent = value ? "$" + value : "$-";
    }
  }

  function collectTargetWrappers(form, sectionKey) {
    const wrappers = new Set();
    const selectors = SECTION_SELECTORS[sectionKey] || [];
    selectors.forEach((selector) => {
      form.querySelectorAll(selector).forEach((field) => {
        const wrapper = getWrapper(field);
        if (wrapper) {
          wrappers.add(wrapper);
        }
      });
    });
    return wrappers;
  }

  function toggleFormFields(form, sectionKey) {
    const wrappers = Array.from(form.querySelectorAll(".js-form-wrapper, .js-form-item, details, .form-wrapper, fieldset"));
    if (sectionKey === "overview") {
      wrappers.forEach((wrapper) => {
        wrapper.style.display = "";
      });
      return;
    }

    const keep = collectTargetWrappers(form, sectionKey);
    wrappers.forEach((wrapper) => {
      wrapper.style.display = keep.has(wrapper) ? "" : "none";
    });
  }

  function activateSection(layout, sections, form, key) {
    Object.keys(sections).forEach((sectionKey) => {
      sections[sectionKey].classList.toggle("is-active", sectionKey === key);
    });

    layout.querySelectorAll(".mel-editor-menu button[data-section]").forEach((button) => {
      button.classList.toggle("is-active", button.getAttribute("data-section") === key);
    });

    if (form && sections[key]) {
      sections[key].appendChild(form);
      toggleFormFields(form, key);
    }
  }

  function bindEventTypeToggle(layout) {
    const eventType = layout.querySelector("#mel-event-type");
    const sourceType = layout.querySelector(
      '[name^="field_event_mode"] select, [name^="field_event_mode"], [name^="field_event_type"]'
    );

    if (sourceType && sourceType.value) {
      eventType.value = sourceType.value;
    }

    const applyType = (type) => {
      layout.querySelectorAll(".mel-section-tickets").forEach((el) => {
        el.style.display = type === "paid" ? "" : "none";
      });
      layout.querySelectorAll(".mel-section-rsvp").forEach((el) => {
        el.style.display = type === "rsvp" ? "" : "none";
      });
    };

    if (sourceType) {
      sourceType.addEventListener("change", () => {
        eventType.value = sourceType.value || eventType.value;
        applyType(eventType.value);
      });
    }

    eventType?.addEventListener("change", () => {
      if (sourceType) {
        sourceType.value = eventType.value;
        sourceType.dispatchEvent(new Event("change", { bubbles: true }));
      }
      applyType(eventType.value);
    });

    applyType(eventType?.value || "rsvp");
  }

  Drupal.behaviors.melEditorSections = {
    attach(context) {
      once("mel-editor-sections", "[data-mel-editor-layout]", context).forEach((layout) => {
        const sections = getSections(layout);
        const form =
          (sections.overview && (
            sections.overview.querySelector("form.node-event-form") ||
            sections.overview.querySelector('form[data-drupal-selector^="node-event-"]')
          )) ||
          null;

        layout.querySelectorAll(".mel-editor-menu button[data-section]").forEach((button) => {
          button.addEventListener("click", () => {
            activateSection(layout, sections, form, button.getAttribute("data-section"));
          });
        });

        bindEventTypeToggle(layout);
        syncCommandCenterFromInspector(layout);
        activateSection(layout, sections, form, "overview");
      });
    },
  };
})(Drupal, once);
