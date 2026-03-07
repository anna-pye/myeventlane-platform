(function (Drupal, once) {
  "use strict";

  function buildEventsDeskUrl() {
    const url = new URL(window.location.href);
    url.searchParams.set("desk", "events");
    url.searchParams.delete("event");
    url.searchParams.delete("create");
    return url.toString();
  }

  function parseEditorState(urlString) {
    const url = new URL(urlString, window.location.origin);
    const eventId = parseInt(url.searchParams.get("event") || "0", 10);
    const create = url.searchParams.get("create") === "1";
    return {
      eventId: Number.isNaN(eventId) ? 0 : eventId,
      create: create,
      open: eventId > 0 || create,
    };
  }

  Drupal.behaviors.myeventlaneStudioDrawer = {
    attach(context) {
      once("mel-studio-drawer", ".mel-studio-shell", context).forEach((shell) => {
        const main = shell.querySelector(".mel-studio-shell__main");
        const rightPanel = shell.querySelector(".mel-studio-shell__right");
        const drawer = rightPanel ? rightPanel.querySelector(".mel-studio-editor-drawer") : null;
        if (!main || !rightPanel || !drawer) {
          return;
        }

        const drawerBody = drawer.querySelector(".mel-studio-editor-drawer__body");
        const closeControls = drawer.querySelectorAll("[data-mel-studio-close]");
        if (!drawerBody) {
          return;
        }

        shell.classList.add("mel-studio-shell--drawer");
        rightPanel.classList.add("mel-studio-shell__right--drawer");

        const syncActiveRows = (eventId) => {
          main.querySelectorAll(".mel-event-row[data-event-id]").forEach((row) => {
            const rowEventId = parseInt(row.getAttribute("data-event-id") || "0", 10);
            row.classList.toggle("is-active", rowEventId > 0 && rowEventId === eventId);
          });
        };

        const applyDrawerState = (urlString) => {
          const state = parseEditorState(urlString);
          shell.classList.toggle("is-editor-open", state.open);
          drawer.setAttribute("aria-hidden", state.open ? "false" : "true");
          syncActiveRows(state.eventId);
        };

        const loadDrawer = async (urlString, pushState) => {
          try {
            drawer.classList.add("is-loading");
            const response = await fetch(urlString, {
              credentials: "same-origin",
              headers: {
                "X-Requested-With": "XMLHttpRequest",
              },
            });
            if (!response.ok) {
              throw new Error(`HTTP ${response.status}`);
            }

            const html = await response.text();
            const parsed = new DOMParser().parseFromString(html, "text/html");
            const incomingBody = parsed.querySelector(".mel-studio-editor-drawer__body");
            if (!incomingBody) {
              throw new Error("Drawer content missing in response.");
            }

            drawerBody.innerHTML = incomingBody.innerHTML;
            Drupal.attachBehaviors(drawerBody);

            if (pushState) {
              window.history.pushState({ melStudioUrl: urlString }, "", urlString);
            }

            applyDrawerState(urlString);
          }
          catch (error) {
            window.location.assign(urlString);
          }
          finally {
            drawer.classList.remove("is-loading");
          }
        };

        main.addEventListener("click", (event) => {
          if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) {
            return;
          }

          const link = event.target.closest("a.js-mel-studio-event-link");
          if (link) {
            event.preventDefault();
            loadDrawer(link.href, true);
            return;
          }

          // Allow clicking the event row to open the editor.
          const row = event.target.closest(".mel-event-row[data-studio-link]");
          if (!row) {
            return;
          }

          // Keep existing action controls (forms/buttons/preview links) untouched.
          if (event.target.closest(".mel-event-actions")) {
            return;
          }

          const rowUrl = row.getAttribute("data-studio-link");
          if (!rowUrl) {
            return;
          }

          event.preventDefault();
          loadDrawer(rowUrl, true);
        });

        closeControls.forEach((control) => {
          control.addEventListener("click", (event) => {
            event.preventDefault();
            const closeUrl = buildEventsDeskUrl();
            window.history.pushState({ melStudioUrl: closeUrl }, "", closeUrl);
            applyDrawerState(closeUrl);
          });
        });

        window.addEventListener("popstate", () => {
          const currentUrl = window.location.href;
          const state = parseEditorState(currentUrl);
          if (state.open) {
            loadDrawer(currentUrl, false);
          }
          else {
            applyDrawerState(currentUrl);
          }
        });

        applyDrawerState(window.location.href);
      });
    },
  };
})(Drupal, once);
