/**
 * @file
 * Main JavaScript for MyEventLane Vendor Theme.
 *
 * Includes:
 * - Mobile sidebar navigation toggle
 * - Help sidebar drawer (mobile)
 * - Dashboard charts initialization (Chart.js)
 * - Accessible tooltips (Boost help)
 * - Event form tab switching
 * - Dropdown menus
 * - Vendor alert dismiss (sessionStorage)
 */

import './vendor-alert';

(function (Drupal, once) {
  'use strict';

  // ---------------------------------------------------------------------------
  // Tooltip state (shared across page)
  // ---------------------------------------------------------------------------

  /** @type {HTMLElement|null} */
  let openTooltipTrigger = null;
  /** @type {HTMLElement|null} */
  let openTooltipEl = null;

  /**
   * Gets the tooltip element associated with a trigger.
   *
   * @param {HTMLElement} trigger
   * @return {HTMLElement|null}
   */
  const getTooltipEl = (trigger) => {
    const id = trigger.getAttribute('aria-describedby');
    if (!id) {
      return null;
    }
    const tooltip = document.getElementById(id);
    return tooltip instanceof HTMLElement ? tooltip : null;
  };

  /**
   * Closes the currently open tooltip (if any).
   */
  const closeOpenTooltip = () => {
    if (openTooltipEl) {
      openTooltipEl.hidden = true;
    }
    if (openTooltipTrigger) {
      openTooltipTrigger.setAttribute('aria-expanded', 'false');
    }
    openTooltipEl = null;
    openTooltipTrigger = null;
  };

  /**
   * Opens the tooltip for the given trigger (and closes any other).
   *
   * @param {HTMLElement} trigger
   */
  const openTooltipFor = (trigger) => {
    const tooltip = getTooltipEl(trigger);
    if (!tooltip) {
      return;
    }

    // If a different tooltip is open, close it first.
    if (openTooltipTrigger && openTooltipTrigger !== trigger) {
      closeOpenTooltip();
    }

    tooltip.hidden = false;
    trigger.setAttribute('aria-expanded', 'true');
    openTooltipEl = tooltip;
    openTooltipTrigger = trigger;
  };

  /**
   * Mobile sidebar navigation toggle.
   */
  Drupal.behaviors.melSidebarNavigation = {
    attach: function (context) {
      const toggles = once('mel-sidebar-toggle', '[data-sidebar-toggle]', context);
      const sidebar = document.querySelector('[data-sidebar]');
      const overlay = document.querySelector('[data-sidebar-overlay]');

      if (!sidebar) return;

      toggles.forEach((toggle) => {
        toggle.addEventListener('click', (e) => {
          e.preventDefault();
          const isOpen = sidebar.classList.toggle('is-open');
          toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
          overlay?.classList.toggle('is-visible', isOpen);
          document.body.style.overflow = isOpen ? 'hidden' : '';
        });
      });

      // Close on overlay click
      overlay?.addEventListener('click', () => {
        sidebar.classList.remove('is-open');
        overlay.classList.remove('is-visible');
        document.body.style.overflow = '';
        toggles.forEach((t) => t.setAttribute('aria-expanded', 'false'));
      });

      // Close on escape key
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && sidebar.classList.contains('is-open')) {
          sidebar.classList.remove('is-open');
          overlay?.classList.remove('is-visible');
          document.body.style.overflow = '';
          toggles.forEach((t) => t.setAttribute('aria-expanded', 'false'));
        }
      });
    }
  };

  /**
   * Help sidebar drawer (mobile).
   */
  Drupal.behaviors.melHelpSidebar = {
    attach: function (context) {
      const toggleBtn = once('mel-help-toggle', '[data-help-sidebar-toggle]', context);
      const closeBtn = once('mel-help-close', '[data-help-sidebar-close]', context);
      const sidebar = document.querySelector('[data-help-sidebar]');
      const overlay = document.querySelector('[data-help-overlay]');

      if (!sidebar) return;

      const openSidebar = () => {
        sidebar.classList.add('is-open');
        overlay?.classList.add('is-visible');
        document.body.style.overflow = 'hidden';
      };

      const closeSidebar = () => {
        sidebar.classList.remove('is-open');
        overlay?.classList.remove('is-visible');
        document.body.style.overflow = '';
      };

      toggleBtn.forEach((btn) => btn.addEventListener('click', openSidebar));
      closeBtn.forEach((btn) => btn.addEventListener('click', closeSidebar));
      overlay?.addEventListener('click', closeSidebar);

      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && sidebar.classList.contains('is-open')) {
          closeSidebar();
        }
      });
    }
  };

  /**
   * Dashboard charts initialization (Chart.js).
   */
  Drupal.behaviors.melDashboardCharts = {
    attach: function (context) {
      // Check if Chart.js is loaded
      if (typeof Chart === 'undefined') {
        console.warn('Chart.js not loaded');
        return;
      }

      const chartContainers = once('mel-chart', '[data-chart-id]', context);

      // Chart color palette from design tokens
      const colors = {
        primary: '#2563EB',
        coral: '#FF8A8A',
        green: '#5CC98B',
        yellow: '#FFEAAA',
        slate: '#637185',
        border: '#E6E6E6'
      };

      // Default chart options
      const defaultOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            display: false
          },
          tooltip: {
            backgroundColor: '#0D1520',
            titleFont: { family: 'Inter, sans-serif', size: 13 },
            bodyFont: { family: 'Inter, sans-serif', size: 12 },
            padding: 12,
            cornerRadius: 8
          }
        },
        scales: {
          x: {
            grid: { display: false },
            ticks: { color: colors.slate, font: { family: 'Inter, sans-serif', size: 11 } }
          },
          y: {
            grid: { color: colors.border },
            ticks: { color: colors.slate, font: { family: 'Inter, sans-serif', size: 11 } },
            beginAtZero: true
          }
        }
      };

      chartContainers.forEach((container) => {
        const chartId = container.dataset.chartId;
        const canvas = container.querySelector('canvas');
        const chartData = drupalSettings?.vendorCharts?.[chartId];

        if (!canvas || !chartData) return;

        // Merge options
        const options = { ...defaultOptions, ...chartData.options };

        // Handle donut chart center label
        if (chartData.type === 'doughnut' || chartData.type === 'pie') {
          options.cutout = chartData.type === 'doughnut' ? '70%' : 0;
          options.scales = {}; // No scales for donut
          options.plugins.legend = { display: true, position: 'bottom' };
        }

        // Create chart
        new Chart(canvas, {
          type: chartData.type || 'line',
          data: {
            labels: chartData.labels || [],
            datasets: chartData.datasets?.map((ds, i) => ({
              ...ds,
              borderColor: ds.borderColor || colors.primary,
              backgroundColor: ds.backgroundColor || (chartData.type === 'bar' ? colors.primary : `${colors.primary}20`),
              borderWidth: ds.borderWidth || 2,
              tension: ds.tension || 0.3,
              fill: ds.fill !== false
            })) || []
          },
          options
        });
      });
    }
  };

  /**
   * Accessible tooltips (ARIA-describedby, role=tooltip).
   *
   * Behaviour:
   * - Open on hover and focus
   * - Close on blur and Escape
   * - Never traps focus (tooltip is not focusable)
   * - Ensures only one tooltip is open at a time
   */
  Drupal.behaviors.melTooltips = {
    attach: function (context) {
      const triggers = once('mel-tooltip-trigger', '.mel-tooltip__trigger', context);

      triggers.forEach((trigger) => {
        // Defensive: tooltips are buttons, but support any element.
        if (!(trigger instanceof HTMLElement)) {
          return;
        }

        // Ensure a sane default state.
        trigger.setAttribute('aria-expanded', trigger.getAttribute('aria-expanded') || 'false');

        trigger.addEventListener('mouseenter', () => {
          openTooltipFor(trigger);
        });

        trigger.addEventListener('mouseleave', () => {
          // If the trigger still has keyboard focus, keep tooltip visible.
          if (document.activeElement === trigger) {
            return;
          }
          if (openTooltipTrigger === trigger) {
            closeOpenTooltip();
          }
        });

        trigger.addEventListener('focus', () => {
          openTooltipFor(trigger);
        });

        trigger.addEventListener('blur', () => {
          if (openTooltipTrigger === trigger) {
            closeOpenTooltip();
          }
        });

        trigger.addEventListener('keydown', (e) => {
          if (e.key === 'Escape') {
            // Close tooltip but keep focus on trigger.
            closeOpenTooltip();
          }
        });
      });
    }
  };

  /**
   * Chart period selector.
   */
  Drupal.behaviors.melChartPeriod = {
    attach: function (context) {
      const periodBtns = once('mel-period', '.mel-chart-period__btn', context);

      periodBtns.forEach((btn) => {
        btn.addEventListener('click', (e) => {
          e.preventDefault();
          const period = btn.dataset.period;
          const container = btn.closest('.mel-chart-period');

          // Update active state
          container.querySelectorAll('.mel-chart-period__btn').forEach((b) => {
            b.classList.remove('is-active');
          });
          btn.classList.add('is-active');

          // Trigger custom event for chart updates
          document.dispatchEvent(new CustomEvent('melChartPeriodChange', {
            detail: { period }
          }));
        });
      });
    }
  };

  /**
   * Event form tab switching.
   */
  Drupal.behaviors.melEventFormTabs = {
    attach: function (context) {
      const steppers = once('mel-stepper', '.mel-event-form__stepper', context);

      steppers.forEach((stepper) => {
        const steps = stepper.querySelectorAll('.mel-event-form__step');
        const panels = stepper.closest('.mel-event-form-page')?.querySelectorAll('.mel-event-form__panel');

        if (!panels || panels.length === 0) return;

        steps.forEach((step, index) => {
          step.addEventListener('click', (e) => {
            e.preventDefault();
            if (step.disabled) return;

            // Update steps
            steps.forEach((s, i) => {
              s.classList.toggle('is-active', i === index);
              s.setAttribute('aria-selected', i === index ? 'true' : 'false');
            });

            // Update panels
            panels.forEach((panel, i) => {
              panel.classList.toggle('is-active', i === index);
              panel.setAttribute('aria-hidden', i !== index ? 'true' : 'false');
            });
          });
        });
      });
    }
  };

  /**
   * Dropdown menus.
   */
  Drupal.behaviors.melDropdowns = {
    attach: function (context) {
      const triggers = once('mel-dropdown', '[data-dropdown-trigger]', context);

      triggers.forEach((trigger) => {
        const dropdown = trigger.closest('.mel-dropdown');
        const menu = dropdown?.querySelector('.mel-dropdown__menu');

        if (!menu) return;

        trigger.addEventListener('click', (e) => {
          e.preventDefault();
          e.stopPropagation();
          const isOpen = menu.hidden === false;

          // Close all other dropdowns
          document.querySelectorAll('.mel-dropdown__menu').forEach((m) => {
            m.hidden = true;
          });
          document.querySelectorAll('[data-dropdown-trigger]').forEach((t) => {
            t.setAttribute('aria-expanded', 'false');
          });

          // Toggle current
          menu.hidden = isOpen;
          trigger.setAttribute('aria-expanded', !isOpen ? 'true' : 'false');
        });
      });

      // Close on outside click
      document.addEventListener('click', (e) => {
        if (!e.target.closest('.mel-dropdown')) {
          document.querySelectorAll('.mel-dropdown__menu').forEach((m) => {
            m.hidden = true;
          });
          document.querySelectorAll('[data-dropdown-trigger]').forEach((t) => {
            t.setAttribute('aria-expanded', 'false');
          });
        }
      });
    }
  };

  /**
   * Auto-resize textareas.
   */
  Drupal.behaviors.melAutoResize = {
    attach: function (context) {
      const textareas = once('mel-autoresize', 'textarea.mel-textarea--auto', context);

      textareas.forEach((textarea) => {
        const resize = () => {
          textarea.style.height = 'auto';
          textarea.style.height = textarea.scrollHeight + 'px';
        };

        textarea.addEventListener('input', resize);
        resize(); // Initial resize
      });
    }
  };

  /**
   * Form validation feedback.
   */
  Drupal.behaviors.melFormValidation = {
    attach: function (context) {
      const inputs = once('mel-validation', '.mel-input, .mel-select, .mel-textarea', context);

      inputs.forEach((input) => {
        input.addEventListener('invalid', (e) => {
          const group = input.closest('.mel-form-group');
          group?.classList.add('mel-form-group--error');
        });

        input.addEventListener('input', () => {
          const group = input.closest('.mel-form-group');
          if (input.validity.valid) {
            group?.classList.remove('mel-form-group--error');
          }
        });
      });
    }
  };

})(Drupal, once);

